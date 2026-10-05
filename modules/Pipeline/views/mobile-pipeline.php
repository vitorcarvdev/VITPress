<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$digits = static function ( $phone ) {
	$only = preg_replace( '/\D/', '', (string) $phone );
	if ( strlen( $only ) < 10 ) {
		return '';
	}
	if ( 10 === strlen( $only ) || 11 === strlen( $only ) ) {
		$only = '55' . $only;
	}
	return $only;
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#1b4d3e">
	<meta name="robots" content="noindex, nofollow">
	<title>Pipeline de Leads — VitPress</title>
	<link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
	<link rel="icon" href="<?php echo esc_url( $icon ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
</head>
<body>
	<header class="vp-top">
		<p class="vp-brand">VitPress</p>
		<h1>Pipeline de Leads</h1>
		<p class="vp-status">Pipeline local</p>
	</header>

	<div class="vp-install" id="vp-install" hidden>
		<p id="vp-install-text">Adicionar à tela inicial</p>
		<button type="button" id="vp-install-btn">Adicionar à tela inicial</button>
	</div>

	<main class="vp-list-screen" id="vp-list-screen">
		<label class="vp-search">
			<span class="screen-reader">Buscar lead</span>
			<input type="search" id="vp-search" placeholder="Buscar lead..." autocomplete="off">
		</label>

		<div class="vp-chips" id="vp-chips" role="tablist">
			<?php $first = true; foreach ( $stages as $key => $definition ) : ?>
				<button type="button" class="vp-chip<?php echo $first ? ' is-active' : ''; ?>" data-stage="<?php echo esc_attr( $key ); ?>" role="tab" aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
					<?php echo esc_html( $definition['label'] ); ?>
				</button>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</div>

		<section class="vp-cards" id="vp-cards">
			<?php foreach ( $cards as $card ) : ?>
				<?php $wa = $digits( $card['phone'] ); ?>
				<article
					class="vp-card"
					data-stage="<?php echo esc_attr( $card['stage'] ); ?>"
					data-name="<?php echo esc_attr( $card['name'] ); ?>"
					data-phone="<?php echo esc_attr( $card['formatted_phone'] ); ?>"
					data-email="<?php echo esc_attr( $card['email'] ); ?>"
					data-search="<?php echo esc_attr( strtolower( $card['name'] . ' ' . $card['phone'] . ' ' . $card['email'] ) ); ?>"
				>
					<h2><?php echo esc_html( $card['name'] ); ?></h2>
					<?php if ( ! empty( $card['formatted_phone'] ) ) : ?><p><?php echo esc_html( $card['formatted_phone'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $card['email'] ) ) : ?><p><?php echo esc_html( $card['email'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $card['source_label'] ) ) : ?><p class="vp-meta"><?php echo esc_html( $card['source_label'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $card['formatted_date'] ) ) : ?><p class="vp-meta"><?php echo esc_html( $card['formatted_date'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $card['attendance']['status_text'] ) ) : ?><p class="vp-alert"><?php echo esc_html( $card['attendance']['status_text'] ); ?></p><?php endif; ?>
					<button
						type="button"
						class="vp-open"
						data-id="<?php echo esc_attr( (string) $card['id'] ); ?>"
						data-stage="<?php echo esc_attr( $card['stage'] ); ?>"
						data-name="<?php echo esc_attr( $card['name'] ); ?>"
						data-phone="<?php echo esc_attr( $card['formatted_phone'] ); ?>"
						data-email="<?php echo esc_attr( $card['email'] ); ?>"
						data-wa="<?php echo esc_attr( $wa ); ?>"
					>Abrir cartão</button>
				</article>
			<?php endforeach; ?>
			<p class="vp-empty" id="vp-empty" hidden>Nenhum lead nesta etapa.</p>
		</section>
	</main>

	<section class="vp-detail" id="vp-detail" hidden>
		<button type="button" class="vp-back" id="vp-back">Voltar</button>
		<h2 id="vp-detail-name"></h2>
		<p id="vp-detail-phone"></p>
		<p id="vp-detail-email"></p>
		<p id="vp-detail-wa-wrap" hidden><a id="vp-detail-wa" href="#" target="_blank" rel="noopener">Abrir WhatsApp</a></p>

		<form id="vp-stage-form">
			<h3>Alterar etapa</h3>
			<div class="vp-stage-options" id="vp-stage-options">
				<?php foreach ( $stages as $key => $definition ) : ?>
					<label>
						<input type="radio" name="stage" value="<?php echo esc_attr( $key ); ?>">
						<span><?php echo esc_html( $definition['label'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="vp-error" id="vp-error" hidden></p>
			<button type="submit" id="vp-save">Salvar etapa</button>
		</form>
	</section>

	<script>
		window.vitpressPipeline = <?php echo wp_json_encode( array(
			'endpoint' => $endpoint,
			'nonce'    => $nonce,
			'sw'       => $sw,
			'scope'    => $scope,
		) ); ?>;
	</script>
	<script src="<?php echo esc_url( $js ); ?>"></script>
</body>
</html>
