# Importação dos leads antigos para o VitAds (VitPress 1.9.4)

## Importação histórica automática (padrão, sem intervenção)
Ao atualizar/ativar o VitPress 1.9.4 com a integração VitAds configurada, os leads antigos são importados **uma única vez** para o pipeline do VitAds, sozinhos, pelo WP-Cron. Não há tela, WP-CLI, SSH nem cPanel envolvidos. A sincronização normal (novos leads, etapas a cada 5 min) continua independente.

**Quando começa**
- Site com a integração já configurada: no primeiro ciclo do cron depois da atualização (cerca de 1 minuto depois do primeiro acesso ao site; a ativação em si não importa nada).
- Site sem integração (ou token ainda não colocado): fica "pending" e começa sozinho assim que a integração é salva em Configurações → VitAds (o salvamento antecipa o ciclo em ~30 s).

**Como roda:** a cada ciclo (`hub_historical_import_tick`, intervalo `hub_five_minutes` do plugin, e um ciclo extra ~1 min depois enquanto ainda houver leads) processa até 10 lotes de 50 leads por id, no máximo ~20 s, com timeout HTTP de 10 s, e devolve o controle. Nenhuma requisição fica aberta durante a importação inteira. Uma base de 50 mil leads leva da ordem de 1 a 2 h de cron ativo.

**Estados** (`hub_historical_import`, option sem autoload): `pending` (aguardando integração), `running`, `failed_recoverable` (com `last_error`, `attempts` e `next_retry_at`) e `completed`. O cursor fica em `hub_historical_import_cursor` e é gravado a cada lote.

**Importação única:** quando o último lote é processado grava-se `hub_historical_import_done` (data UTC, autoload) e `completed`; o agendamento e o cursor são removidos. Depois disso **nada reinicia a importação**: nem desativar/reativar o plugin, nem atualizar o plugin, nem trocar o token (para reimportar de propósito use o comando manual abaixo). Reativar antes de concluir só retoma do cursor.

**Falhas e retomada:** rede/5xx, 404 (VitAds ainda sem a rota), 401/403, 409, 503 e erro interno viram `failed_recoverable` com espera crescente (5 min a 6 h, conforme o tipo; 404 espera 1 h; 401/403 espera 6 h) e retomam do cursor, sem duplicar: a rota do VitAds é idempotente, então reenviar um lote cuja resposta se perdeu apenas reconhece o que já foi criado. Salvar a integração de novo libera a espera. Erro ao ler a tabela de leads nunca conclui a importação. Se o token for trocado no meio da importação (outro cliente), ela recomeça do primeiro lead para o novo cliente. Uma trava (`hub_backfill_lock`, 15 min) impede dois processos no mesmo lote.

**O que não faz:** não gera conversão, venda, evento, entrega ou interação; não altera etapas locais; não sobrescreve o que o VitAds já tem; não inventa datas (lead fora de Apresentação sem data da etapa é ignorado; em Apresentação usa a data de criação); telefone repetido entre leads é recusado como ambíguo (fica em `hub_backfill_failures`, só id e código, para revisão). Não grava nome, telefone, e-mail, token nem endpoint no estado, nos logs ou nas falhas.

**Limitações reais do WP-Cron:** só roda quando o site recebe visitas (ou com `wp-cron.php` chamado por um cron do sistema). Sites com `DISABLE_WP_CRON` sem cron do sistema não importam nem sincronizam etapas (já era assim para a sincronização do pipeline). Hospedagens que limitam o tempo de execução abaixo de ~30 s podem interromper um lote: a trava expira em 15 min e o ciclo seguinte retoma do cursor.

**Acompanhar (opcional):** `wp vitpress backfill-leads --status` mostra o estado automático em `automatic` (sem dados pessoais).

---

# Importação manual (ferramenta avançada)
Comando opcional, para reimportar de propósito, importar um intervalo (`--after-id`) ou simular antes (dry-run). Não é necessário para o funcionamento normal. Usa um cursor próprio e a mesma trava do fluxo automático.
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