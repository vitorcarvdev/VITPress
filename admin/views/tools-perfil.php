<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice_success = '';
$settings = get_option( 'hub_settings', array() );

$license_provider = new \Hub\Core\Services\Licensing\LocalLicenseProvider();
$license_status   = $license_provider->getStatus();

// Exceção para localhost
$site_url = site_url();
$is_localhost = ( strpos( $site_url, 'localhost' ) !== false || strpos( $site_url, '127.0.0.1' ) !== false );

$is_licensed = ! empty( $license_status['is_valid'] ) || $is_localhost;

if (
	'POST' === $_SERVER['REQUEST_METHOD'] &&
	isset( $_POST['hub_settings_nonce'] ) &&
	wp_verify_nonce( $_POST['hub_settings_nonce'], 'hub_save_settings' ) &&
	current_user_can( 'manage_options' )
) {
	if ( isset( $_POST['hub_active_profile'] ) ) {
		$new_profile_id = sanitize_key( $_POST['hub_active_profile'] );
		\Hub\Core\ProfileManager::get_instance()->set_active_profile( $new_profile_id );
	}

	if ( $is_licensed ) {
		$settings['crm_enabled'] = isset( $_POST['hub_crm_enabled'] ) ? 1 : 0;
	} else {
		$settings['crm_enabled'] = 0;
	}
	update_option( 'hub_settings', $settings );
	$notice_success = 'Configurações atualizadas com sucesso.';
}

$profile_manager = \Hub\Core\ProfileManager::get_instance();
$active_profile  = $profile_manager->get_active_profile();
$all_profiles    = $profile_manager->get_profiles();

// Valores atuais
$crm_enabled = isset( $settings['crm_enabled'] ) ? ! empty( $settings['crm_enabled'] ) : false;

if ( $notice_success ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_success ); ?></p></div>
<?php endif; ?>

<form method="post" action="">
	<?php wp_nonce_field( 'hub_save_settings', 'hub_settings_nonce' ); ?>
	<div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
		<h2>Perfil de Uso e Módulos</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="hub_active_profile"><?php esc_html_e( 'Perfil de Uso (Profissão)', 'hub' ); ?></label>
				</th>
				<td>
					<select id="hub_active_profile" name="hub_active_profile">
						<?php foreach ( $all_profiles as $profile_id => $profile_obj ) : ?>
							<option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $active_profile->get_id(), $profile_id ); ?>>
								<?php echo esc_html( $profile_obj->get_name() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">Altera as nomenclaturas (ex: Paciente vs Cliente), ícones e estágios.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="hub_crm_enabled">Módulos CRM</label>
				</th>
				<td>
					<label>
						<input type="checkbox" name="hub_crm_enabled" id="hub_crm_enabled" value="1" <?php checked( $crm_enabled ); ?> <?php disabled( ! $is_licensed ); ?>>
						<strong>Habilitar recursos comerciais (Leads, Empresas, Pipeline e Agenda)</strong>
					</label>
					<?php if ( ! $is_licensed ) : ?>
						<p class="description" style="color: #d63638;"><strong>Atenção:</strong> O uso do CRM requer uma licença ativa ou autorizada no menu Área VitAgência.</p>
					<?php else : ?>
						<p class="description">Desmarque se o cliente for utilizar o VitPress apenas para funções básicas (ex: Privacidade e Formulários simples).</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<p class="submit" style="margin-bottom: 0;">
			<?php submit_button( __( 'Salvar Configurações', 'hub' ), 'primary', 'submit', false ); ?>
		</p>
	</div>
</form>