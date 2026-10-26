# VitPress: nome oficial e captação pelo Atendente

## 1. Nome oficial
O produto se chama **VitPress**. "Hub", "Hub VitAgência", "Hub VCSis" e variações são nomes anteriores.
Foram atualizados: cabeçalho do plugin (`Plugin Name`, descrição, `@package`), textos administrativos, mensagens,
e-mails de teste/aviso, nomes de agendamentos exibidos, comentários e esta documentação.

### Identificadores legados PRESERVADOS (não renomear sem migração)
Renomear qualquer item abaixo quebraria sites instalados, backups antigos ou a integração com o VitAds.

| Identificador | Onde | Por que fica |
|---|---|---|
| `hub.php`, pasta `hub-vitagencia`, `hub-vitagencia/hub.php` | arquivo/slug do plugin | O WordPress guarda o plugin ativo por este caminho; o atualizador do VitAds responde por ele |
| Namespace `Hub\...`, classes `Hub*` (ex.: `HubPipelineConnector`), prefixo do autoloader | PHP | Contrato interno; renomear exige reescrever todos os `use`/autoload |
| Constantes `HUB_VERSION`, `HUB_PATH`, `HUB_FILE`; text domain `hub` | PHP | Usadas em todo o plugin e em traduções |
| Opções `hub_settings`, `hub_lead_collector`, `hub_db_version`, `hub_atendimento_start_date`, `hub_consent_preferences` (cookie) etc. | banco / navegador | Persistidos nos sites |
| Tabelas `{prefixo}hub_*` (`hub_leads`, `hub_history`, ...) | banco | Persistidas; renomear exige migração |
| Hooks `hub/lead_created`, `hub/pipeline_client_won`, `hub/security_event`...; ações `admin_post_hub_*`, `wp_ajax_hub_*` | PHP | Outros códigos e formulários dependem deles |
| Rotas REST `hub/v1/...` (`leads`, `collector/submit`, `collector/redirected`, `pipeline/stage`) | REST | O site, o PWA e o Atendente chamam estas rotas |
| Shortcode `[hub_form]` | páginas publicadas | Se deixasse de existir, o texto `[hub_form]` apareceria na página |
| Prefixo de token `hubva_`, plataforma `HUB_VITAGENCIA`, `event_id` `hubva:<id>:form_submit` | contrato com o VitAds | O VitAds identifica a origem e deduplica por estes valores |
| Capability `hub_vitagencia_manage_internal`, metadado `hub_authorized_agency_user`, usuário/rota `vcsis` e `/vcsis/` | acesso | Permissões e login oculto já em uso |
| Gerador `Hub VitAgencia` e formato `hub_legacy_content` no validador de backup | restauração | Backups antigos precisam continuar sendo aceitos |
| Marcadores `# BEGIN/END Hub PHP Settings` no `.htaccess` | arquivo do site | Já gravados nos sites; mudar duplicaria o bloco |
| Rótulos `Hub VitAgência`, `hub_connector`, `Hub Form Submission`, `Hub VitAgência Admin` enviados ao Pipeline Central (VCSis) | integração externa | Contrato de um sistema que não está neste repositório |
| Prefixos de log `[HUB FORM]`, `[HUB VITADS]`, `[HUB PIPELINE]`... | logs | Rotinas de monitoramento procuram por eles |
| `vcsis_connect_*`, transient `hub_vcsis_connect_check`, nó `hub_vcsis` da barra de admin | configuração interna | Chaves persistidas/seletores |
| E-mails e URLs `vcsis.com.br` | contatos | São endereços reais |

Mudança de gravação (sem impacto em leitura): o cabeçalho dos **novos** dumps do banco passa a ser
`-- VitPress Database Backup (Raw SQL)`; nenhum leitor depende dessa linha.

## 2. Captação pelo Atendente
**Regra:** a captação do lead e o evento de conversão correspondente (`FORM_SUBMIT`, `hub/lead_created`) têm origem no
Atendente (`LeadCollectorService`, rotas `hub/v1/collector/*`).

### Bloqueado por padrão (classe `LeadCapturePolicy`)
- Shortcode `[hub_form]` (`FrontendFormService` e `FormService`): não renderiza o formulário (o shortcode segue registrado).
- Rota REST `POST hub/v1/leads`: responde **410** `standalone_form_disabled` antes de qualquer processamento.
- Ação AJAX `hub_submit_form` (público e logado): responde **410** antes de ler a requisição e sem consumir o nonce.
- Em todos os casos: nenhum lead é gravado, nenhum e-mail é enviado, nenhum contato é registrado no pipeline e nenhum
  `FORM_SUBMIT`/`hub/lead_created` é emitido.

### Preservado
- O Atendente e o seu `FORM_SUBMIT` (uma vez, só para lead novo, depois da confirmação no pipeline).
- A página `/obrigado-contato` e o template de agradecimento.
- O cadastro manual de pessoas no painel (`PersonsModule`) e as demais telas administrativas.
- Formulários que **não** são do VitPress (por exemplo, formulários do Elementor) não passam pelo plugin e não são afetados.

### Reabertura temporária (rollback controlado, somente por código)
```php
add_filter( 'hub/allow_standalone_lead_forms', '__return_true' );
```
Não há opção na interface de propósito: a regra é que a captação seja do Atendente.

### Antes de publicar em um site que usa o formulário
Se o site capta leads pelo `[hub_form]` (por exemplo, a página `/novo-lead/`), **ative e teste o Atendente primeiro**
(Ferramentas > Captador); sem isso a captação por este plugin cessa na atualização.
