# Importação dos leads antigos para o VitAds (VitPress 1.9.4)

Comando manual, nunca automático: `wp vitpress backfill-leads`. Não existe botão, WP-Cron nem rota pública para isso.
Serve para levar ao pipeline do portal os leads que chegaram antes da integração (ou antes do perfil existir), sem duplicar
contatos e **sem gerar nenhuma conversão**.

## Pré-requisitos
1. **VitAds atualizado** com a rota `POST /api/v1/pipeline/contacts/backfill` (branch `feat/backfill-leads-vitpress`, doc `docs/backfill-leads-vitpress.md` do VitAds). VitAds antigo responde 404 e o comando para sem enviar nada (`vitads_unsupported`).
2. Integração VitAds **ligada** no site (Configurações → VitAds) com o token do cliente certo. O token define o cliente: nada vai para outro cliente.
3. WP-CLI no servidor do site (`wp --info`). Rode como o usuário do site, no diretório do WordPress.
4. Backup recente do site (o comando só grava o vínculo `shared_contact_id` nos leads; o resto é do lado do VitAds).

## Passo a passo
```bash
# 1) Simulação (padrão). Nada é gravado em lugar nenhum. Pergunta ao VitAds o que ele faria (dry_run).
wp vitpress backfill-leads

# 1b) Só classificação local, sem falar com o VitAds
wp vitpress backfill-leads --local-only

# 2) Importação real (idempotente; pode ser interrompida e repetida)
wp vitpress backfill-leads --execute

# Janela controlada (ex.: 20 lotes por vez, 60 s), pausa de 500 ms entre lotes
wp vitpress backfill-leads --execute --max-batches=20 --max-seconds=60 --sleep-ms=500

# Estado, falhas e recomeço
wp vitpress backfill-leads --status
wp vitpress backfill-leads --failures
wp vitpress backfill-leads --execute --restart
```
Opções: `--batch=<1..50>` (padrão 50), `--after-id=<id>` (começa depois deste lead), `--skip-linked` (não reenvia leads que já têm vínculo; por padrão eles são enviados só para completar o perfil).

## O que cada lead vira
| Situação | Resultado |
|---|---|
| lead novo para o VitAds | `created`: contato criado com a **etapa e a data da etapa do VitPress**, o perfil (nome, e-mail, origem, data de recebimento) e, fora de Apresentação, uma linha de histórico na data real |
| já existe no VitAds (mesmo `shared_contact_id` ou telefone único do cliente) | `existing`: o VitAds **vence**; etapa, data, histórico, nome, e-mail e origem já preenchidos não mudam. Só entram o vínculo com o lead e campos de perfil que estavam vazios |
| telefone repetido em dois leads, telefone com mais de um contato ou ligado a outro lead | `ambiguous`: nada é gravado; fica em `--failures` para revisão |
| dado inválido (etapa, data, telefone) | `invalid`: não importado; motivo em `--failures` |
| sem telefone | ignorado no site (`no_phone`) |
| fora de Apresentação e sem data da etapa | ignorado no site (`stage_without_date`): **a data não é inventada** |
| Apresentação sem data da etapa | usa a data de criação do lead (a etapa inicial vale desde que o lead chegou) |

Depois de `created`/`existing` o lead do VitPress recebe o `shared_contact_id`; a sincronização por WP-Cron (a cada 5 min) passa a trazer as etapas do VitAds, sem conversão.

## Resumo e contagem
O comando imprime: modo, lotes, leads lidos, último id, contagem por resultado (`created`, `existing`, `ambiguous`, `invalid`, `error`, `would_send`) e os ignorados no site por motivo. O registro de falhas (`hub_backfill_failures`, máx. 500) guarda **só id do lead, status e código** — nunca nome, telefone ou e-mail.

## Segurança e limites
- Dry-run por padrão; só `--execute` grava.
- Uma execução real por vez (trava `hub_backfill_lock`, vence em 15 min se o processo morrer); o VitAds também aceita uma importação por cliente por vez (409 `backfill_in_progress`).
- Lotes de até 50 por requisição, por id crescente, cursor gravado a cada lote (`hub_backfill_state`): se o comando for interrompido, repita o mesmo comando e ele continua do cursor. Uma execução já concluída recomeça do início na próxima vez (sem risco: é idempotente).
- Falha de rede: o mesmo lote é repetido até 3 vezes e então o comando para mantendo o cursor. Códigos que param de imediato: `vitads_unsupported` (404), `vitads_unauthorized` (401/403), `vitads_busy` (409), `vitads_schema_outdated` (503), `vitads_rejected_batch` (422).
- Nada é enviado para Google, Meta ou VitZap; nenhum hook de conversão do VitPress é disparado; a etapa dos leads no VitPress não é alterada pela importação.

## Recuperação
- **Parou no meio:** repita `wp vitpress backfill-leads --execute`.
- **Trava presa:** aguarde 15 min ou apague a option `hub_backfill_lock`.
- **Importou o cliente errado (token trocado):** não há exclusão automática. Pare, anote o intervalo de leads e acione o suporte do VitAds; o vínculo local pode ser limpo com `UPDATE wp_hub_leads SET shared_contact_id = NULL WHERE id BETWEEN ...` somente depois de o VitAds remover os contatos criados.

## Como validar o resultado
1. Dry-run sem `ambiguous`/`invalid` inesperados (ou conferidos em `--failures`).
2. Depois do `--execute`: `created + existing` ≈ leads lidos − ignorados − `ambiguous` − `invalid`.
3. No portal do cliente (pipeline do VitAds) os cartões mostram nome, origem e data de recebimento; as etapas batem com as do VitPress.
4. Painel de conversões do VitAds: **nenhum evento novo** causado pela importação.
5. `wp vitpress backfill-leads --execute` de novo: `created = 0`.