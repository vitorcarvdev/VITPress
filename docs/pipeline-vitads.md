# Pipeline antigo → portal do VitAds

Quando o site está ligado ao VitAds (integração ativada, com token e endpoint), o acesso **humano** ao pipeline antigo vai para `https://vitads.vitagencia.com.br/portal/login` (ou o host do endpoint configurado). Sem a ligação, tudo continua como antes.

## O que muda
| Ponto | Comportamento |
|---|---|
| `/pipeline/` e `/pipeline` | `302` para o login do portal (só GET/HEAD). Manifest (`/pipeline/manifest.webmanifest`) e service worker (`/pipeline/sw.js`) continuam respondendo, então instalações antigas do app não quebram; ao abrir o ícone antigo, a pessoa cai no portal. |
| Menu do admin | O item "Pipeline" some do menu. A página `hub-pipeline` continua registrada e, ao ser aberta por favorito ou link velho, redireciona ao portal. |
| E-mail de novo lead | O botão "Visualizar no Pipeline Central" aponta para o portal. |
| REST (`hub/v1/pipeline/stage`), integração de leads, `PipelineContactSync`, `PipelineModule::change_stage` | **Sem alteração.** Nenhum endpoint ou rota interna é redirecionado. |

Desligar em emergência: `add_filter( 'hub_pipeline_legacy_redirect', '__return_false' );`.

## Sincronização das etapas sem abrir o pipeline antigo
Antes, as etapas do VitAds só chegavam ao WordPress quando alguém abria o `/pipeline/` ou o kanban (`pull_board`). Agora o WP-Cron roda `hub_pipeline_pull_worker` a cada 5 minutos (`hub_five_minutes`, o mesmo intervalo do restante do plugin) e chama `PipelineContactSync::pull_cycle()`:
- percorre os leads vinculados (`shared_contact_id`) em páginas de até 200, até 5 páginas por execução, com cursor (`hub_pipeline_pull_cursor`) para retomar de onde parou;
- trava contra execuções simultâneas (`hub_pipeline_pull_lock`, 4 min), e falha de rede mantém o cursor;
- só **lê** do VitAds (`/pipeline/contacts/pull`) e aplica a etapa remota apenas quando é mais nova, com origem `vitads_sync` (que não devolve nada ao VitAds: sem laço);
- nunca dispara conversão (`change_stage` não aciona hooks de conversão) nem chama Google/Meta.
Os Indicadores continuam lendo `stage` de `hub_leads`, que o ciclo mantém atualizado. Se o site usa WP-Cron desativado, é preciso um cron do sistema chamando `wp-cron.php`.

## Perfil do lead enviado ao VitAds (campo opcional `contact`)
`POST /pipeline/contact/resolve` passa a levar, quando existem: `name`, `email`, `source` (rótulo, ex.: "Google Ads", sem identificadores de clique) e `received_at` (`created_at` em ISO 8601 UTC). Finalidade: o cliente e a equipe identificarem o lead e avaliarem o atendimento no portal. Não saem observações, valores, IP, user-agent, gclid/fbclid nem cookies de anúncio. Vai só para o VitAds do próprio cliente (token do site) e só com a integração ligada. O VitAds ignora o que não conhece, então não há quebra com versões antigas.

## O que continua indisponível
`first_contact_at`, `attempts_count` e `hub_lead_contact_attempts` só são preenchidos por `ContactAttemptService::record_attempt()`, que **nunca é chamado** (desde a versão 1.9.0), e `hub_history` não é gravada: não existe registro de quando a clínica respondeu ao lead. Por isso o portal mostra "Não disponível" para o tempo até o primeiro contato do atendimento.

## Leads antigos
A importação dos leads anteriores à integração (com dry-run, sem duplicar e sem conversões) está em docs/backfill-leads-vitads.md (comando manual wp vitpress backfill-leads).
