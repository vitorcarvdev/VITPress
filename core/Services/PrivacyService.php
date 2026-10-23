<?php
namespace Hub\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PrivacyService {

	const DEFAULT_MESSAGE = 'Este site pode utilizar cookies e tecnologias semelhantes para medir campanhas, entender a origem dos contatos e melhorar nossos serviços.';

	/** Textos padrão antigos gravados em hub_settings; equivalem à mensagem padrão atual. */
	const LEGACY_MESSAGES = array(
		'Utilizamos cookies para medir o desempenho de nossas campanhas. Saiba mais em nossa Política de Privacidade.',
	);

	public function init() {
		if ( empty( self::settings()['banner_enabled'] ) ) {
			return;
		}

		// Inject GCM v2 default before everything else
		add_action( 'wp_head', array( $this, 'inject_gcm_default' ), 1 );

		// HubConsent precisa existir antes do hub-tracker.js (enfileirado na prioridade 10).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_banner' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function settings() {
		$settings = get_option( 'hub_settings', array() );
		return isset( $settings['privacy'] ) && is_array( $settings['privacy'] ) ? $settings['privacy'] : array();
	}

	public static function message( $custom ) {
		$custom = trim( (string) $custom );
		if ( '' === $custom || in_array( $custom, self::LEGACY_MESSAGES, true ) ) {
			return self::DEFAULT_MESSAGE;
		}
		return $custom;
	}

	private static function policy_page_id() {
		$wp_policy_page = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $wp_policy_page ) {
			return $wp_policy_page;
		}
		$privacy = self::settings();
		return ! empty( $privacy['policy_page'] ) ? (int) $privacy['policy_page'] : 0;
	}

	public function inject_gcm_default() {
		?>
		<script>
		window.dataLayer = window.dataLayer || [];
		function gtag(){dataLayer.push(arguments);}
		
		// Set default consent state
		gtag('consent', 'default', {
			'ad_storage': 'denied',
			'analytics_storage': 'denied',
			'ad_user_data': 'denied',
			'ad_personalization': 'denied',
			'wait_for_update': 500
		});
		
		dataLayer.push({
			'event': 'default_consent'
		});
		</script>
		<?php
	}

	public function enqueue_assets() {
		$version = function ( $relative ) {
			$path = HUB_PATH . $relative;
			return HUB_VERSION . '.' . ( file_exists( $path ) ? filemtime( $path ) : '1' );
		};
		wp_enqueue_style( 'hub-privacy-css', HUB_URL . 'assets/css/hub-privacy.css', array(), $version( 'assets/css/hub-privacy.css' ) );
		wp_enqueue_script( 'hub-privacy-js', HUB_URL . 'assets/js/hub-privacy.js', array(), $version( 'assets/js/hub-privacy.js' ), true );
	}

	public function render_banner() {
		$privacy        = self::settings();
		$message        = self::message( $privacy['banner_text'] ?? '' );
		$policy_page_id = self::policy_page_id();
		$policy_url     = $policy_page_id ? get_permalink( $policy_page_id ) : '';
		$on_policy_page = $policy_page_id && is_page( $policy_page_id );
		?>
		<div id="hub-consent-banner" class="hub-consent-overlay" hidden<?php echo $on_policy_page ? ' data-hub-policy-page="1"' : ''; ?>>
			<div class="hub-consent-modal" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( 'Preferências de privacidade' ); ?>" aria-describedby="hub-consent-message" tabindex="-1">
				<p id="hub-consent-message" class="hub-consent-text"><?php echo esc_html( $message ); ?></p>
				<div class="hub-consent-actions">
					<button type="button" id="hub-consent-accept" class="hub-btn hub-btn-accept">Aceitar e continuar</button>
					<button type="button" id="hub-consent-reject" class="hub-btn hub-btn-reject">Recusar e continuar</button>
				</div>
				<?php if ( $policy_url ) : ?>
					<a class="hub-consent-policy" href="<?php echo esc_url( $policy_url ); ?>">Política de Privacidade</a>
				<?php endif; ?>
			</div>
		</div>
		<button type="button" id="hub-consent-manage" class="hub-consent-manage" aria-haspopup="dialog" hidden>Privacidade</button>
		<?php
	}
}
