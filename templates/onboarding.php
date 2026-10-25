<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template de Onboarding do Plugin VitPress - Escolha de Perfil de Segmento.
 */
?>
<div class="wrap hub-onboarding-wrap">
	<div class="hub-onboarding-card">
		<div class="hub-onboarding-header">
			<span class="dashicons dashicons-groups hub-logo-icon"></span>
			<h1>Bem-vindo ao VitPress</h1>
			<p class="description">Escolha seu segmento para personalizar menus, nomenclaturas e status do sistema.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hub-onboarding-form">
			<input type="hidden" name="action" value="hub_save_profile">
			<?php wp_nonce_field( 'hub_save_profile_nonce', 'hub_nonce' ); ?>

			<div class="hub-profile-options">
				<?php
				$count = 0;
				foreach ( $profiles as $id => $profile ) :
					$count++;
					$checked = ( 1 === $count ) ? 'checked="checked"' : '';
					?>
					<label class="hub-profile-option">
						<input type="radio" name="profile_type" value="<?php echo esc_attr( $id ); ?>" <?php echo $checked; ?>>
						<div class="hub-profile-content">
							<div class="hub-profile-title">
								<span class="dashicons <?php echo esc_attr( $profile->get_icon() ); ?>"></span>
								<strong><?php echo esc_html( $profile->get_name() ); ?></strong>
							</div>
							<p class="hub-profile-desc"><?php echo esc_html( $profile->get_description() ); ?></p>
						</div>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="hub-onboarding-footer">
				<button type="submit" class="button button-primary button-hero">
					Continuar &rarr;
				</button>
			</div>
		</form>
	</div>
</div>
