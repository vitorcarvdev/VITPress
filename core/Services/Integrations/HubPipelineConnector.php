<?php
namespace Hub\Core\Services\Integrations;

use Hub\Core\Database;
use VCSis\PipelineCentral\Application\Services\PipelineService;
use VCSis\PipelineCentral\Infrastructure\Auth\AuthContext;
use VCSis\PipelineCentral\Infrastructure\Auth\AuthService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conector Oficial do VitPress com o Pipeline Central VCSis
 * 
 * Responsabilidades:
 * 1. Escutar a criação de novos leads no VitPress (hook `hub/lead_created`).
 * 2. Mapear e traduzir os dados reais de contato e tráfego do VitPress para o contrato genérico de ingestão.
 * 3. Garantir idempotência com chave baseada na submissão real (`hub_lead:{tenant}:{lead_id}`).
 * 4. Isolar falhas: se o Pipeline Central falhar, a submissão do formulário conclui 100% com sucesso para o visitante.
 * 5. Permitir retentativas manuais ou automáticas via `retry_lead()`.
 */
class HubPipelineConnector {

    private static ?HubPipelineConnector $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action( 'hub/lead_created', [ $this, 'handle_lead_created' ], 20, 1 );
    }

    /**
     * Listener disparado quando um novo lead é salvo no VitPress
     *
     * @param int $lead_id ID do registro em wp_hub_leads
     * @return array Resultado da sincronização
     */
    public function handle_lead_created( int $lead_id ): array {
        global $wpdb;

        if ( $lead_id <= 0 ) {
            return [ 'success' => false, 'error_code' => 'invalid_lead_id', 'message' => 'ID do lead inválido.' ];
        }

        // 1. Busca os dados completos do lead gravados no VitPress
        $table_leads = Database::table( 'leads' );
        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_leads} WHERE id = %d LIMIT 1", $lead_id ), ARRAY_A );

        if ( ! $lead ) {
            return [ 'success' => false, 'error_code' => 'lead_not_found', 'message' => 'Lead não localizado na tabela do VitPress.' ];
        }

        // 2. Resolução do Tenant Context Oficial.
        // AuthService pertence ao Pipeline Central. A chamada fica isolada para
        // não transformar ausência dessa classe em HTTP 500 do formulário público.
        try {
        if ( ! class_exists( AuthService::class ) ) {
            error_log( '[HUB FORM] exception=Error AuthService missing' );
            $wpdb->update(
                $table_leads,
                [
                    'pipeline_sync_status' => 'isolated',
                    'pipeline_sync_error'  => 'Modo Isolado Ativo (Sem Vínculo)',
                    'pipeline_sync_at'     => current_time( 'mysql' )
                ],
                [ 'id' => $lead_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return [ 'success' => true, 'message' => 'Lead salvo localmente em modo isolado.' ];
        }

        $tenant_id = AuthService::get_or_create_default_tenant_id();

        if ( empty( $tenant_id ) ) {
            // Modo Isolado ativo: Não sincronizar com Pipeline Central.
            $wpdb->update(
                $table_leads,
                [ 
                    'pipeline_sync_status' => 'isolated',
                    'pipeline_sync_error'  => 'Modo Isolado Ativo (Sem Vínculo)',
                    'pipeline_sync_at'     => current_time( 'mysql' )
                ],
                [ 'id' => $lead_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return [ 'success' => true, 'message' => 'Lead salvo localmente em modo isolado.' ];
        }

        $auth = new AuthContext( $tenant_id, 'Hub VitAgência', 'hub_connector', 'Hub Form Submission', true );

        // 3. Montagem do Payload de Ingestão Genérica
        $payload = [
            'source_system'     => 'hub_form',
            'external_id'       => (string) $lead_id,
            'hub_lead_id'       => (int) $lead_id,
            'name'              => $lead['name'] ?? '',
            'phone'             => $lead['phone'] ?? '',
            'email'             => $lead['email'] ?? '',
            'city'              => $lead['city'] ?? null,
            'state'             => $lead['state'] ?? null,
            'monetary_value'    => isset( $lead['revenue_value'] ) ? (float) $lead['revenue_value'] : 0.00,
            // NOTA ARQUITETURAL: Não forçamos operational_stage_id hardcoded.
            // O Pipeline Central resolve automaticamente o estágio inicial configurado para o tenant.
            'attribution'       => [
                'utm_source'         => ! empty( $lead['utm_source'] ) ? $lead['utm_source'] : ( $lead['first_touch_utm_source'] ?? null ),
                'utm_medium'         => ! empty( $lead['utm_medium'] ) ? $lead['utm_medium'] : ( $lead['first_touch_utm_medium'] ?? null ),
                'utm_campaign'       => ! empty( $lead['utm_campaign'] ) ? $lead['utm_campaign'] : ( $lead['first_touch_utm_campaign'] ?? null ),
                'utm_content'        => ! empty( $lead['utm_content'] ) ? $lead['utm_content'] : ( $lead['first_touch_utm_content'] ?? null ),
                'utm_term'           => ! empty( $lead['utm_term'] ) ? $lead['utm_term'] : ( $lead['first_touch_utm_term'] ?? null ),
                'gclid'              => ! empty( $lead['gclid'] ) ? $lead['gclid'] : ( $lead['first_touch_gclid'] ?? null ),
                'gbraid'             => $lead['gbraid'] ?? null,
                'wbraid'             => $lead['wbraid'] ?? null,
                'fbclid'             => ! empty( $lead['fbclid'] ) ? $lead['fbclid'] : ( $lead['first_touch_fbclid'] ?? null ),
                'fbp'                => $lead['_fbp'] ?? null,
                'fbc'                => $lead['_fbc'] ?? null,
                'landing_url'        => $lead['landing_url'] ?? null,
                'referrer_url'       => $lead['external_referrer'] ?? null,
                'conversion_page'    => $lead['conversion_page'] ?? null,
                'client_ip'          => $lead['client_ip_address'] ?? null,
                'user_agent'         => $lead['user_agent'] ?? null,
                'custom_payload'     => $lead['custom_data'] ?? null,
            ]
        ];

        // Chave de idempotência estável baseada na submissão
        $idempotency_key = "hub_lead:{$tenant_id}:{$lead_id}";

        // 4. Despacho Seguro para o Pipeline Central com Isolamento de Falhas
            $pipelineService = new PipelineService();
            $res = $pipelineService->ingestOpportunity( $auth, $payload, $idempotency_key );

            if ( $res['status_code'] === 200 && ! empty( $res['data']['success'] ) ) {
                $central_id = $res['data']['central_pipeline_id'];

                $wpdb->update(
                    $table_leads,
                    [
                        'pipeline_central_id' => $central_id,
                        'pipeline_sync_status' => 'synced',
                        'pipeline_synced_at'   => current_time( 'mysql' ),
                        'pipeline_sync_error'  => null,
                    ],
                    [ 'id' => $lead_id ]
                );

                Database::log_history( 'lead', $lead_id, 'pipeline_synced', "Lead sincronizado com sucesso ao Pipeline Central (UUID: {$central_id})" );

                return [
                    'success'             => true,
                    'central_pipeline_id' => $central_id,
                    'server_version'      => $res['data']['server_version'] ?? 1,
                    'is_duplicate'        => $res['data']['is_duplicate'] ?? false,
                ];
            } elseif ( $res['status_code'] === 409 && ( $res['data']['error_code'] ?? '' ) === 'ambiguous_match' ) {
                // Conflito de ambiguidade: telefone e e-mail colidem com contatos distintos
                $error_msg = $res['data']['message'] ?? 'Conflito de ambiguidade detectado no Pipeline Central.';

                $wpdb->update(
                    $table_leads,
                    [
                        'pipeline_sync_status' => 'ambiguous_conflict',
                        'pipeline_sync_error'  => $error_msg,
                    ],
                    [ 'id' => $lead_id ]
                );

                Database::log_history( 'lead', $lead_id, 'pipeline_ambiguous', "Ambiguidade no Pipeline Central: {$error_msg}" );

                return [
                    'success'    => false,
                    'error_code' => 'ambiguous_match',
                    'message'    => $error_msg,
                ];
            } else {
                // Outro erro de validação ou processamento
                $error_msg = $res['data']['message'] ?? 'Falha ao processar oportunidade no Pipeline Central.';

                $wpdb->update(
                    $table_leads,
                    [
                        'pipeline_sync_status' => 'failed',
                        'pipeline_sync_error'  => $error_msg,
                    ],
                    [ 'id' => $lead_id ]
                );

                Database::log_history( 'lead', $lead_id, 'pipeline_failed', "Falha ao sincronizar com Pipeline Central: {$error_msg}" );

                return [
                    'success'    => false,
                    'error_code' => $res['data']['error_code'] ?? 'pipeline_error',
                    'message'    => $error_msg,
                ];
            }
        } catch ( \Throwable $e ) {
            // Isolamento Crítico: Erro/Exceção NUNCA quebra a experiência do formulário
            $technical = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $e->getMessage() );
            error_log( '[HUB FORM] exception=' . get_class( $e ) . ' ' . $technical . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
            $error_msg = $e->getMessage();

            $wpdb->update(
                $table_leads,
                [
                    'pipeline_sync_status' => 'failed',
                    'pipeline_sync_error'  => $error_msg,
                ],
                [ 'id' => $lead_id ]
            );

            Database::log_history( 'lead', $lead_id, 'pipeline_exception', "Exceção na sincronização com Pipeline Central: {$error_msg}" );

            return [
                'success'    => false,
                'error_code' => 'exception',
                'message'    => $error_msg,
            ];
        }
    }

    /**
     * Reprocessa a sincronização de um lead que falhou anteriormente
     *
     * @param int $lead_id
     * @return array
     */
    public function retry_lead( int $lead_id ): array {
        return $this->handle_lead_created( $lead_id );
    }
}
