<?php
namespace Hub\Core\Services;

/**
 * Regras puras (sem WordPress) do Atendente Virtual: telefone, reaproveitamento de contato, mensagem do WhatsApp,
 * atribuição enviada ao pipeline e os três estados do atendimento. Testável fora do WordPress.
 */
class AttendantIntake {

	/** Chaves de atribuição que seguem ao VitAds. gbraid e wbraid são independentes de gclid. */
	const ATTRIBUTION_KEYS = array(
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
		'gclid', 'gbraid', 'wbraid', 'fbclid', 'fbp', 'fbc',
	);

	/**
	 * Telefone brasileiro normalizado, mesmo padrão do VitAds e do VitZap: DDD + número, com 55.
	 * Retorna '' quando não for possível normalizar com segurança (nada é adivinhado).
	 */
	public static function normalize_phone( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( 0 === strpos( $digits, '55' ) && ( 12 === strlen( $digits ) || 13 === strlen( $digits ) ) ) {
			return $digits;
		}
		if ( 10 === strlen( $digits ) || 11 === strlen( $digits ) ) {
			return '55' . $digits;
		}
		return '';
	}

	/**
	 * Procura o contato já existente. Um único candidato com o mesmo telefone normalizado é reaproveitado;
	 * mais de um é ambiguidade e NÃO é mesclado automaticamente.
	 *
	 * @param array<int,array{id:int|string,phone:string}> $candidates
	 * @return array{status:string,id:int}
	 */
	public static function pick_existing( array $candidates, $normalized ) {
		if ( '' === $normalized ) {
			return array( 'status' => 'none', 'id' => 0 );
		}
		$matches = array();
		foreach ( $candidates as $candidate ) {
			if ( self::normalize_phone( $candidate['phone'] ?? '' ) === $normalized ) {
				$matches[] = (int) $candidate['id'];
			}
		}
		if ( 1 === count( $matches ) ) {
			return array( 'status' => 'single', 'id' => $matches[0] );
		}
		if ( count( $matches ) > 1 ) {
			return array( 'status' => 'ambiguous', 'id' => 0 );
		}
		return array( 'status' => 'none', 'id' => 0 );
	}

	/** Referência curta do atendimento, usada na mensagem do WhatsApp. */
	public static function reference( $lead_id ) {
		return 'ATD-' . (int) $lead_id;
	}

	/**
	 * Mensagem inicial do WhatsApp: só nome e referência do atendimento. Nunca atribuição, tokens ou dados técnicos.
	 */
	public static function whatsapp_message( $template, $name, $reference ) {
		$name = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $name ) );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
		$template = trim( (string) $template );
		if ( '' === $template ) {
			$template = 'Olá, sou {nome} e gostaria de saber mais.';
		}
		$message = str_replace( '{nome}', '' !== $name ? $name : 'um visitante do site', $template );
		return trim( $message ) . ' (Ref. ' . $reference . ')';
	}

	public static function whatsapp_url( $number, $message ) {
		$digits = preg_replace( '/\D+/', '', (string) $number );
		if ( '' === $digits ) {
			return '';
		}
		return 'https://api.whatsapp.com/send?phone=' . $digits . '&text=' . rawurlencode( (string) $message );
	}

	/**
	 * Atribuição da interação: só o que existe. Nada é inventado e valores vazios são descartados.
	 *
	 * @param array<string,mixed> $source
	 * @return array<string,string>
	 */
	public static function attribution( array $source ) {
		$out = array();
		foreach ( self::ATTRIBUTION_KEYS as $key ) {
			$value = isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( (string) $source[ $key ] ) : '';
			if ( '' !== $value ) {
				$out[ $key ] = substr( $value, 0, 255 );
			}
		}
		return $out;
	}

	/**
	 * Interação enviada ao pipeline. A chave de idempotência é por submissão: repetir a requisição não duplica.
	 *
	 * @param array<string,string> $attribution
	 * @return array<string,mixed>
	 */
	public static function interaction( $submission_uuid, array $attribution, $consent, $occurred_at_utc ) {
		return array(
			'key'                  => 'hubva-attendant:' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $submission_uuid ),
			'source'               => 'attendant',
			'occurred_at'          => $occurred_at_utc,
			'attribution'          => $attribution,
			'ad_user_data_consent' => in_array( $consent, array( 'granted', 'denied' ), true ) ? $consent : 'unknown',
		);
	}

	/**
	 * Estados separados do atendimento. O redirecionamento ao WhatsApp NÃO prova mensagem enviada:
	 * conversation_started é sempre falso aqui; só o VitZap pode observá-lo.
	 *
	 * @param string $pipeline confirmed|ambiguous|not_required|unavailable
	 * @return array<string,mixed>
	 */
	public static function stages( $pipeline, $whatsapp_url ) {
		$registered = in_array( $pipeline, array( 'confirmed', 'ambiguous', 'not_required' ), true );
		return array(
			'attendance_completed' => true,
			'pipeline'             => $pipeline,
			'redirect_allowed'     => $registered && '' !== $whatsapp_url,
			'conversation_started' => false,
		);
	}
}
