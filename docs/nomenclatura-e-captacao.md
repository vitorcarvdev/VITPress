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
- `WhatsApp de Destino` e `Mensagem Automática`: ficam na **página do Atendente** (card "Redirecionamento do Atendente (WhatsApp)"), salvos junto com as demais configurações dele; o Atendente os usa no redirecionamento final.
- `E-mails para Notificação`: campo da própria página do Atendente (`hub_lead_collector.notification_emails`). Recebem os dados do lead caso ele não chame no WhatsApp; vários e-mails separados por vírgula; em branco usa o e-mail do administrador do WordPress. Não usa o antigo `forms.receive_email`, que nenhum fluxo ativo lê.
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

## 3. Licença em desenvolvimento local
Quando o domínio registrado do site (host de `site_url()`, configurado no WordPress; nunca o cabeçalho Host da requisição) é exatamente `localhost`, a licença é considerada ativa (`licensed_localhost`), sem e-mail de autorização e sem gravar nada. Subdomínios (`x.localhost`), IPs e domínios que apenas contêm `localhost` continuam exigindo a licença assinada. Para desligar: `add_filter( 'hub/auto_license_localhost', '__return_false' );`.

## 4. Página de Conversão Limpa (removida)
Removidos: o botão **Gerar Página de Conversão**, o template `templates/page-clean-conversion.php`, a ação AJAX `hub_generate_page`, o uso do template em `/novo-lead`, o shortcode exibido na tela e os campos de e-mail interno, confirmação ao visitante e depoimento. Páginas `/novo-lead` já criadas passam a usar o template do tema e o `[hub_form]` não renderiza nada. As opções `receive_email`, `send_confirm` e `testimonial_*` **não são apagadas** do banco (apenas deixam de ser exibidas e de ser sobrescritas ao salvar).

## 5. Backup completo e restauração em outro WordPress
Fluxo (menu **VitPress > Backup e Restauração**, disponível a quem administra o site): no site de origem, **Backup Completo**; num WordPress novo com o VitPress instalado, **Restaurar Site** > enviar o `.zip` > Iniciar restauração > gerar o link de recuperação > Aplicar > Confirmar (ou Reverter).
- O backup tem o banco (`database.sql`) e `wp-content`. Na restauração o prefixo das tabelas (`wph_` para `wpt_`, por exemplo, inclusive nos metadados de usuário) é ajustado e a URL de origem é trocada pela do destino em **todas** as tabelas e colunas de texto (conteúdo, títulos, comentários, links, termos, opções, metadados e tabelas de plugins, como `hub_leads`). Formatos cobertos: URL simples (http e https), JSON com barras escapadas (`http:\/\/`, usado pelo Elementor), codificada (`%3A%2F%2F`) e sem protocolo (`//host`), inclusive dentro de dados serializados e de serializado dentro de serializado (tamanhos recalculados, sem instanciar classes). Não são trocados: outra pasta com o mesmo começo (`/Site2`), outra porta no mesmo host, nem caminhos de disco.
- **O destino é sempre https.** A URL gravada (`home` e `siteurl`) e todas as URLs trocadas ficam `https://`, mesmo que o WordPress de destino ainda responda em http (filtro `hub/restore_force_https`, só para testes sem certificado). Ao final, as regras de permalink são renovadas no primeiro acesso ao painel.
- **Pasta do plugin com outro nome:** se o site de origem tinha o VitPress numa pasta diferente da do destino (por exemplo `VITPress` em vez de `hub-vitagencia`), a cópia que vem no backup **não é restaurada**: a pasta em uso permanece, e a lista de plugins ativos do banco restaurado é ajustada para ela. Antes disso o site ficava com erro fatal (`Cannot redeclare activate_hub_plugin()`) por carregar o plugin duas vezes.
- A geração do backup remove o limite de tempo de execução, eleva a memória e lê as tabelas em lotes de 500 linhas.
- Depois da troca, os usuários e senhas passam a ser os do backup. Backup e restauração valem para quem tem `manage_options` (administradores) e para a equipe com `hub_vitagencia_manage_internal`; o link de recuperação funciona sem login. A Área VitAgência (usuário `vcsis`) segue restrita e guarda as chaves do Amazon S3 e do SES na aba **Amazon** (antes "E-mails"; o S3 saiu da antiga aba Backup, que deixou de existir).
- Não há mais a tela "Escolha seu Perfil" ao ativar o plugin: a Área VitAgência (Backup/Restaurar) já existe num WordPress novo. A área de atuação (perfil de uso) é escolhida na aba **Marketing**, card "Área de Atuação"; até lá vale o perfil padrão (Saúde). Os módulos comerciais (Indicadores, Pipeline etc.) seguem a chave única da aba Geral.

## 6. Link cego do Atendente (`#atendente`)
- `https://seusite.com.br/#atendente` (ou `#Atendente`) abre o Atendente automaticamente: ao carregar a página, ao mudar a âncora e ao clicar em qualquer link para a própria página com essa âncora (sem recarregar). Basta apontar o botão (ex.: "Agende sua consulta agora mesmo!") para `/#atendente`.
- **Páginas antigas do formulário:** `/novo-lead/` (com ou sem barra, qualquer caixa) redireciona (302) para a página inicial com `#atendente`, **mantendo a query string inteira** (`gclid`, `utm_*`...), então anúncios e links antigos continuam funcionando sem edição. Outros endereços antigos podem ser incluídos pelo filtro `hub/legacy_lead_form_paths`.
- O redirecionamento só ocorre com o Atendente ativo e presente na página inicial; não vale para POST, pré-visualização do editor, admin, AJAX e REST. Funciona com o site em subpasta.
- O lead passa a registrar a página inicial (`/`) como página de captação, e não mais `/novo-lead/`.
- **Salvar o Atendente nunca apaga o que já estava salvo:** se o servidor entregar o formulário incompleto (firewall, `max_input_vars`), nada é gravado e a página avisa quantos campos chegaram, em vez de regravar os padrões.
