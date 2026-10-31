# Management Hub — current project state

Last reviewed: 2026-10-29 (Europe/Lisbon)

This file records the current project state, not an immutable history. Durable
behavioral decisions live under `docs/decisions/`; detailed workflows live
under `docs/flows/`.

## Production and release

- Production branch: `agent/room-item-assignments`.
- Required PR checks: `validate` and `windows-agent`.
- Production deployment is guarded and automatic after an authorized merge, as
  documented in `docs/AUTOMATED_DEPLOYMENT.md`.
- “Integrated”, “deployed”, and “verified” are separate states and must be
  reported separately.
- Production secrets remain outside the repository and outside `public_html`.

## Repository-backed project memory

- Repository documentation is the durable technical memory for this project.
- `docs/PROJECT_MEMORY.md` defines the read/update protocol.
- `docs/flows/` contains end-to-end orientation maps; `docs/decisions/`
  contains durable ADRs.
- `docs/project-memory.json` maps architecture-sensitive runtime paths to the
  documents that must change with them.
- Pull-request CI runs `tools/project_memory_guard.py` so mapped runtime changes
  cannot silently bypass their state/flow documentation.
- Test-only and visual/static-only changes are intentionally exempt unless they
  also alter mapped runtime behavior.

## Development workflow tooling

- `AGENTS.md` now defines selective use of developer-side Superpowers,
  Ponytail review and Grill Me light. These tools are not application runtime
  dependencies and do not override repository/project decisions.
- Routine authorized work remains direct; full brainstorming, full Ponytail and
  full Grill Me are not automatic gates.
- Independent second-opinion/reviewer calls are not a standing project rule.
- A codebase-memory/code-graph MCP may be used as an optional developer index if
  already available, but Management Hub does not depend on one for build,
  deployment or production operation.

## Management Hub / Room Check

- PHP/MySQL application hosted at `check.welcomehostel.pt`.
- Existing room management, role permissions, bilingual behavior, invoice
  automation, Hub OAuth/MCP connector and paired Windows automation remain in use.
- Application operations use `Europe/Lisbon`.

## Cloudbeds invoices

- Cloudbeds invoice collection by email is an existing production workflow.
- Invoice month classification follows invoice issue date.
- The Airbnb CSV/export-month behavior remains the documented exception until
  explicitly changed.

## ZKTeco MySQL backups — independent Drive archiving

Status: **Independent worker integrated in GitHub; deployment blocked on cPanel Git readiness**.
The ZKTeco-only retention has been changed from 30 to **10 days** in the current feature branch; still awaiting publication.
The two newest verified Drive backups are protected if fewer fresh copies exist.
The existing 04:00 Europe/Lisbon AccesControl MySQL dump, encrypted Hub intake,
Google Drive destination remains unchanged; retention is 10 days for ZKTeco backups only (preserve at least two verified copies).

The current production design chains ZKTeco Drive delivery after
`cron/invoices.php`. This can delay archiving when the invoice worker is busy.
The scoped correction adds an independent five-minute cPanel cron, with
idempotent WHM provisioning after guarded deploy, and removes backup handling
from invoice cron. The existing dedicated MySQL advisory lock still serializes
the paired Windows intake and the Drive upload.

The private ZKTeco manifest now records verified complete receipt, upload attempt
times/counts, sanitized failure codes/times and Drive read-back confirmation.
Old entries retain their existing evidence; missing historical timestamps are
not fabricated. The manager page shows the new timeline.
See `docs/flows/zkaccess-backup.md`.

Acceptance remains: required CI, production commit confirmed, one exact cron
entry verified in WHM, and a successful archive cycle executed by that cron.
This change does not fix the separate cPanel disk-quota pressure.

The 2026-10-29 protected read-only inspection of the installed clone
`ee8c516c` confirmed nine *untracked* entries (root 1, deploy 4, docs 1,
tests 3), zero missing tracked entries and no active deployment. A one-off,
reversible private quarantine guarded by exact inventory, expected old SHA,
file type and path validation is prepared to clean the staging clone before
ordinary deployment; it must not touch live application, secrets or existing
backups. Deployment and independent cron remain unverified until the WHM
operation succeeds.

On 2026-10-30, the protected UAPI Fileman operation moved and verified all
nine previously untracked regular files into the private quarantine. A
subsequent read-only clone scan found **zero untracked and zero missing**
entries, yet cPanel still reported `deployable=false`. The next diagnosis
checks only tracked Git **file-size metadata** against the installed SHA
and the presence of Git lock filenames; it never reads file contents or
changes the clone. Do not retry moves or force a Git update while the
additional dirty-tree condition remains unidentified.

The 2026-10-29 guarded recovery reached the cPanel Fileman move but failed with
`quarantine_move_unconfirmed`. The subsequent read-only diagnostic still found
all nine untracked objects in the staging clone; no successful publication was
recorded. The follow-up recovery first inspects the existing private recovery
folder, refuses any unknown/nonempty entries, then uses UAPI
`Fileman::move_file` with an exact target filename and verifies source absence,
destination presence and identical size/mtime before progressing. It never
blindly repeats a partial move, deletes files or bypasses the protected deploy
gate. Independent cron and 10-day ZKTeco retention remain unverified on server. This recovery does **not** reclaim cPanel disk quota.
The protected read-only repository diagnostic now compares the cPanel clone
with its actual installed SHA, avoiding false reports of missing files when
GitHub contains newer commits. Recovery may proceed only after this independent
check proves the specific dirty-tree cause; no forced pull, reset or deletion.

## Cloudbeds → ZKAccess guest PIN automation

Status: **Prepared / not integrated**.

- Owner priority: City Center Guest House.
- Cloudbeds Property ID for City Center has been verified as `7541`.
- Cloudbeds property API access has been requested and is still pending activation.
- Draft PR #256 prepares the first Cloudbeds webhook/ZKAccess foundation but is
  not part of the production branch at the time of this review.
- City Center ZKAccess levels and controller/door mappings for Rooms 1–6 were
  verified against the live ZKAccess 5.3 database.
- Direct read-only Pull SDK communication with a C3-400 was successfully tested.
- No production guest PIN has been written by the new automation.
- No Cloudbeds webhook or API key is currently enabled for this new flow.

Target flow:

`Cloudbeds webhook → authoritative reservation validation → generate PIN →
program ZKAccess → read/confirm write → write PIN note to Cloudbeds →
send guest message`

Important current decisions:

- Revoking guest access means rotating/replacing the PIN on the old room rather
  than relying on a separate “revoke” operation.
- On a room change, rotate the PIN on the old room and reuse the guest's existing
  PIN on the new room.
- The Cloudbeds reservation note is written only after ZKAccess confirms the
  PIN programming.
- Webhook retries and duplicate events must not create duplicate PIN operations
  or duplicate guest messages.
- Multi-room reservations must reconcile the affected accommodation, not blindly
  cancel the entire reservation.

See `docs/flows/cloudbeds-zkaccess.md` and ADRs 002–003.

## External blockers / pending work

- Cloudbeds property API/webhook access approval.
- Final Cloudbeds room/accommodation identifiers and scopes must be verified
  against the activated API before enabling the ZKAccess workflow.
- ZKAccess write/reconciliation and final guest-message delivery remain to be
  completed and acceptance-tested before this workflow can be called verified.


## cPanel disk-quota recovery (pending verification)

- Owner request 2026-10-29: automatically recover quota so email delivery is not interrupted, without deleting mail or necessary recoverable backups.
- Protected WHM credentials already exist in GitHub environment `management-hub-production`.
- In-flight PR introduces quota-triggered cleanup only for disposable `.npm/_cacache/`, `.cache/pip/` and `.cache/uv/` files older than 14 days; backup retention is audit-only pending verified recovery; never mail, backups or application data. See `docs/CPANEL_DISK_MAINTENANCE.md`.
- The GitHub scheduled bridge is maintained separately on `main`; **both workflows need integration**. This mechanism has not yet been verified against the live WHM UAPI or via a received test email.
- Recorded cPanel usage on 2026-10-29: 6000.04 MB / 6000 MB. Actual disk recovery remains unconfirmed.

- Storage inventory read-only diagnostic handles cPanel Fileman empty size fields and is added to scheduled cleanup; it reports backup age counts and first-level cache entries; no automatic deletion of backup directories.

- Puppeteer cache inventory read-only added for identifying browser versions without touching browser downloads or automation sessions.

- Puppeteer installer archives can be reclaimed only after age and exact metadata checks and confirming a matching extracted browser directory; installed browser versions remain protected.

- Puppeteer archive inventory walks only parent directories and skips extracted browser trees; this prevents the guarded scanner from rejecting ordinary installed-browser files.

## Storage growth investigation (2026-10-29)

- The deployment gate creates a new complete backup on each production release. There is currently no retention/deletion phase. The latest WHM inventory showed 172 release directories, creating an ongoing quota-growth risk.
- Daily protected GitHub Actions maintenance now includes a separate **read-only** report of account directory usage, backup age cohorts and extracted browser-version sizes through scoped WHM API queries. It does not read mail or backup contents.
- Existing automatic deletion remains limited to explicitly allowlisted caches; backup deletion and installed-browser deletion are **not** enabled without recovery/in-use evidence.
- Acceptance remains quota and real incoming email verification after any future cleanup.

- Daily maintenance prioritizes the largest explicitly allowlisted disposable cache files (bounded scan of 400 and at most 40 deletions), avoiding runs that previously reclaimed only ~30 KB.


## Backup compaction without deleting recovery points

- Historical snapshots contain uncompressed database.sql dumps. A private post-release compactor converts up to 50 completed dumps older than 48 hours to database.sql.gz per deployment, preserving all snapshot directories and other archives.
- It verifies the original SHA-256 hashes and gzip roundtrip against original SQL, atomically updates manifest format 2, and only then removes redundant plaintext. Failures preserve the original.
- Legacy format 1 remains readable. Full MySQL restoration remains a separate acceptance check, and actual quota improvement must be measured in production.

- The WHM daily audit now reads deployment readiness and recursive log sizes, and estimates same-day backup redundancy; only aggregated metadata is printed, no private files or credentials are read.

## Protected backup recovery verification (2026-10-29)

- A new WHM retention planner preserves the latest 3 snapshots, one per calendar day (Europe/Lisbon) for 45 days, one per week to 180 days, one per month to 365 days, then one per quarter, plus the oldest recovery point.
- The protected daily GitHub maintenance workflow performs a **read-only first pass**: checks retained manifest file structure and imports the latest uncompressed SQL backup into an ephemeral MySQL 8 container, without touching the live database or outputting SQL content.
- Permanent deletion remains disabled while the protected daily workflow validates restore readiness, limited to 25 snapshot directories and 200 MiB per run, only after a successful temporary MySQL import. Files are permanently removed only via the existing fixed-target WHM API, with **no unlisted files inside a deleted backup directory**; roster changes abort.
- **Open acceptance:** the initial protected dry run 40962862993 failed before restore due to release-backup.lock being misclassified; the matcher is corrected and requires a fresh successful dry-run; application archives remain untested. Do not claim backups were deleted or production deployment recovered until actually verified.

- After 2026-10-29 retention validation, daily cache deletion has an explicit 95-second limit; timeout is surfaced as incomplete without automatic retries, while protected backup restore diagnostics continue independently.

## Daily WHM backup retention — activation after restore acceptance (2026-10-29)

- Protected first dry run `40964594901` returned `ok=true`, SQL restore `passed`, 172 snapshots / 23 retained, 148 candidates, 0 removed. This establishes SQL recovery on disposable MySQL, not full application archive restore.
- WHM-only protected daily maintenance now requests `--apply` after fresh manifest checks and SQL import. A run deletes at most 25 redundant release directories / 200 MiB; retains three latest, daily 45d, weekly 180d, monthly 365d, quarterly older, and oldest. Any unexpected file in a directory blocks deletion. Operations fail closed on unexpected roster changes.
- Deletion is irreversible. Actual quota decrease and remaining snapshots must be verified from the live job log before claiming recovery; avoid replaying uncertain deletions.

## Read-only hosting recovery diagnostics (2026-10-30)

- A scoped diagnosis verifies remaining modified tracked metadata and unexpected .github entries in the cPanel staging clone against the installed SHA before any further repair. Unknown server filenames and contents are never printed.
- A separate metadata-only helper can check recent mailbox file timestamps without opening emails. It has no deletion capability and requires live delivery verification.
- The existing private quarantine containing previous recovered clone files must be preserved. Deployment remains blocked until the dirty working tree is reconciled.

## Maintenance rule

Every relevant PR that changes one of the states above must update this file in
the same PR. Do not place passwords, tokens, OTPs, private invoice contents or
guest personal data here.

## cPanel Git repository readiness incident (2026-10-29)

The ZKTeco independent backup archival PR #290 was reviewed, passed the
required `validate` and `windows-agent` checks, and merged as
`3a416b54a56cd8c5dbfdaa19c8ac7ebf7dbcf6da`. The guarded WHM preflight
stopped **before** publishing the new endpoint or provisioning its cron:
`repository_not_deployable`. The immediately previous production commit had
already failed the same preflight. WHM's protected status showed
`deployable=false`, `repository_busy=false`, with the existing installed
commit still `ee8c516cb8393aa8b292005d842dca984c03aac0` at 22:24Z.

A dedicated protected **read-only** diagnostic now checks the cPanel Git clone's
root and known source directories through WHM Fileman metadata, comparing
bounded names against the public checkout. It does not print unknown filenames,
file contents, or credentials, and cannot modify the clone. Its results must
be reviewed before any repair or deployment retry. The ZKTeco replacement cron
is **not** yet confirmed installed; old functioning backups remain untouched.

## cPanel staging-clone tracked repair (2026-10-30)

- Protected CI diagnostic from commit 116280d identified 578 tracked files present, two changed-size tracked files (`.github/workflows/ci.yml`, `docs/PROJECT_STATE.md`), two extra regular files in the `.github` area, and no known Git lockfiles. The nine earlier untracked files were already preserved in a separate private quarantine; do not replay that recovery.
- Prepared a one-time exact repair in `deploy/cpanel_git_tracked_repair.py` (before regular WHM deployment). Only if the installed commit is still ee8c516, the worktree inventory remains exactly as previously diagnosed, and the protected GitHub HEAD is fast-forward: preserve the two changed tracked files and two extras in a new private 0700 quarantine, restore the two tracked files byte-for-byte from GitHub's blob at the **installed** commit and verify Git deployable=true. No changes to `.git`, `public_html`, mail, backups, credentials or databases.
- A partial repair or uncertain WHM response blocks repeated writes until read-only inspection. Restoration is not yet confirmed on the server, and the normal deploy continues to require its original backup, exact commit and health gates.

## cPanel quota recovery and one-off disposable cache (2026-10-30)

- Six owner-authorized, protected WHM backup-retention batches successfully removed **149** redundant release snapshots in total. Latest verified quota was **82.9% of 6 GB**; **24** policy-retained release snapshots remained. Each deletion batch passed the latest SQL restore import in disposable MySQL 8 and respected the existing 25-directories/200 MiB limits. This does not prove full application archive restoration or email delivery.
- Normal scheduled cache cleanup still respects the **90%** quota trigger. Because quota is now below that trigger, disposable caches are not automatically removed.
- New **one-off** protected workflow .github/workflows/cpanel-cache-reclaim-once.yml runs after its own production-branch integration (or deliberate manual dispatch) and only bypasses the quota threshold, never the existing exact-file security checks. It reuses the tested 14-day age, existing allowlisted cache paths, metadata recheck, 40-files/200 MiB budget, protected WHM secret and concurrency group. Installed Puppeteer browsers, mail, databases, logs, active application, and retained backups are never eligible.
- One-off cache result, actual before/after quota, completion or timeout must be observed from its GitHub Actions log. Do not claim cache deleted until the log confirms it. If a run times out, inspect actual state before any retry. Do not expand this cache scope to installed browsers or logs without separate evidence.

## Finalizar cache NPM e auditar logs/Puppeteer sem aceder ao email (2026-10-30)

- O último inventário comprovado depois do segundo lote de cache indicou quota **82.0% de 6 GB**, NPM **9.1 MiB**, logs **150.8 MiB** e Puppeteer **704.8 MiB**. O segundo lote de cache excedeu 780 segundos; parte da NPM foi apagada, mas não foi publicado um contador completo. Não repetir esse lote sem alterar a pesquisa.
- O workflow de uso pontual `.github/workflows/cpanel-npm-finish.yml` utiliza `deploy/cpanel_npm_finisher.py` para fazer **uma única pesquisa recursiva** da cache específica `.npm/_cacache` via Fileman API 2 (legado só para leitura), em vez de centenas de chamadas de `list_files`. Só `--apply` elimina ficheiros individuais de cache, nunca diretórios. Reutiliza validação de caminho, tamanho e mtime imediatamente antes de cada eliminação, limite de **40 ficheiros/200 MiB**, idade mínima **14 dias** e ambiente WHM protegido. A rotina automática de 90% permanece intacta. O estado final depende dos resultados reais do GitHub Actions.
- A leitura de `~/logs` fornece apenas quantidades, tamanhos, classes genéricas e antiguidade dos três maiores ficheiros, **sem ler o conteúdo nem divulgar nomes/domínios**. Esta alteração não elimina logs.
- As três instalações Puppeteer identificadas são `chrome 153.0.8010.36` (391.1 MiB), `headless 153.0.8010.36` (259.7 MiB) e `headless 148.0.7778.97` (52.0 MiB); não é prova de uso ou inutilidade. Não remover browsers instalados sem provar dependências dos processos e testar o fluxo relevante.
- Por decisão expressa do proprietário, manter emails intocados, incluindo limpeza e testes de entrega, até uma fase posterior.

## Resultado verificado da limpeza NPM e revisão de logs/Puppeteer (2026-10-30)

- O workflow protegido [41129358547](https://github.com/danieldeploy/room-check/actions/runs/41129358547), integrado através do PR #303, executou **oito lotes seriais concluídos com sucesso**. Eliminou 290 ficheiros regulares com mais de 14 dias de `.npm/_cacache` (~4.826 MiB). O último lote confirmou `files=0`, `eligible_files=0` e `total_mb=0`. Não houve eliminação de diretórios. A pasta `.npm` ainda ocupa aproximadamente 3.8 MiB por elementos fora dessa cache; esses outros elementos não foram apagados.
- A quota no fim era ~82.06% segundo a UAPI, com 4920.6 MiB de 6000 MiB usados segundo a auditoria DiskUsage. A quota pode variar por alterações de outros processos. Os dois indicadores não são perfeitamente idênticos.
- Os **11 logs** analisados totalizam 150.68 MiB. A classificação de metadados (sem ler conteúdo ou imprimir nomes/domínios) identificou 3 ficheiros de tipo erro (~144.78 MiB), incluindo o maior (110.67 MiB, atualizado há 1 dia) e outro de 33.94 MiB (sem alterações há 372 dias). Existem 4 arquivos comprimidos (~5.27 MiB) e 4 outros logs (~0.63 MiB). **Nenhum log foi eliminado.** O log antigo é candidato a avaliação de retenção; não remover o recente sem diagnóstico de rotação/uso.
- A pasta Puppeteer continua com ~704.8 MiB: Chrome 153.0.8010.36 (391.1 MiB), Chrome Headless Shell 153.0.8010.36 (259.7 MiB), Chrome Headless Shell 148.0.7778.97 (52.0 MiB). O projeto fixa Puppeteer 25.11.0/Chrome 153; `headless: true` usa Chrome completo nas versões modernas. Potencial de poupança das duas shells ~311.7 MiB **não é autorização para eliminação sem verificação de processos/dependências**. Nenhuma instalação de browser foi removida.
- Por instrução do proprietário, emails e testes reais de receção ficam fora desta intervenção, para uma fase posterior.

## Limpeza adicional de logs antigos — execução isolada (31/10/2026)

- A manutenção diária protegida [41189983019](https://github.com/danieldeploy/room-check/actions/runs/41189983019) removeu mais 1 backup redundante (~6.98 MB), verificou restauro SQL (passed) e registou quota 82.5% de 6 GB, sem limpeza de emails. O conjunto de 26 backups permaneceu dentro da política, com 25 protegidos.
- Auditoria recente de `~/logs`: ~151 MiB, com um log de erros ainda ativo (~110.7 MiB) e outro ficheiro de erros não alterado há aproximadamente 373 dias (~33.9 MiB). Os logs comprimidos encontrados não cumprem a retenção automática atual.
- A pedido do proprietário, o novo workflow isolado `.github/workflows/cpanel-old-log-reclaim.yml` só pode apagar **um ficheiro de log de erros** diretamente em `~/logs`, regular (não symlink), não comprimido, com **mais de 365 dias sem alteração** e entre **20 e 60 MiB**, após reconfirmar pesquisa, metadata exata e resultado de exclusão. Nenhum nome de domínio/conteúdo de log é incluído nos resultados. Uma candidatura ambígua bloqueia tudo. Os testes obrigatórios de CI devem passar primeiro. Este workflow não altera a retenção diária nem autoriza excluir logs recentes.
- A instalação Chrome 153 (391.1 MiB) e as duas Chrome Headless Shell 153 (259.7 MiB) e 148 (52.0 MiB) continuam preservadas: o runner permite executablePath configurado na runtime e a ausência de referências estáticas não comprova ausência de uso no alojamento. Não apagar sem confirmar a execução real.
- Os emails e os testes de receção ficam para uma fase posterior por instrução do proprietário.
- Estado de aceitação deste trabalho: script e workflow preparados em PR; **eliminação real e quota pós-limpeza só se confirmam pelos logs da execução protegida**.

## Bloqueio de remoção do log muito antigo — 31/10/2026

- O PR #305 integrou a remoção isolada de log de erro com >=365 dias. A execução protegida [41201812000](https://github.com/danieldeploy/room-check/actions/runs/41201812000) identificou um único candidato de 33.94 MiB, mas parou com `uapi_failed` durante verificação ou eliminação. A auditoria posterior confirmou que **o log continua presente** e a quota permanece em ~82.5%; não houve recuperação deste ficheiro.
- Por segurança, **não repetir automaticamente** a operação falhada, nem utilizar métodos alternativos sem conhecer a causa. A nova alteração do mesmo workflow substitui temporariamente o passo destrutivo por `cpanel_log_access_probe.py`, apenas de leitura. Compara acesso UAPI a metadata por caminho relativo/absoluto, API2 statfiles e list_files, relatando somente sucesso/erro, permissões agregadas e correspondência de tamanho/mtime, nunca nomes, domínios, conteúdo dos logs ou credenciais.
- A conclusão depende da execução protegida e da verificação de permissões. O log recente de 110.7 MiB, browsers e emails continuam protegidos.

## Correção de verificação para remoção de log muito antigo (31/10/2026)

- A execução protegida e exclusivamente de leitura [41202204788](https://github.com/danieldeploy/room-check/actions/runs/41202204788) confirmou que **Fileman/get_file_information (UAPI)** recusa tanto o caminho relativo como absoluto em `~/logs`. **Fileman/statfiles (API2)** funciona, reconhece exatamente um ficheiro regular de 33.94 MiB, titular `welcome` e permissão de escrita. `Fileman/list_files` (UAPI) também consegue listar a entrada.
- A rotina pontual de eliminação passa a obter a confirmação de `type`, `user`, `mode` e `mtime` da API2 `statfiles`, sem executar operações de escrita por API2. Continua a exigir tamanho/mtime exatos em duas pesquisas independentes, único ficheiro de erro plano diretamente em `~/logs`, mínimo 365 dias de idade, tamanho 20–60 MiB e confirmação de ausência depois de uma só chamada de `Fileman/delete_file` UAPI.
- O PR reabilita o passo destrutivo sob testes/ambiente de produção protegidos; **o resultado ainda depende da execução real**. Caso a API de eliminação também recuse, nenhum método alternativo deverá ser usado sem auditoria posterior. A restrição de não tocar em emails, browser ou log ativo mantém-se.

## Verificação de dependências de browsers Puppeteer Linux — 31/10/2026

- A eliminação do log muito antigo foi **confirmada** pela execução protegida [41202585352](https://github.com/danieldeploy/room-check/actions/runs/41202585352): uma cópia de 33.94 MiB eliminada, zero candidatos restantes e os logs desceram de ~150.9 para ~117 MiB, com quota UAPI 82.74% -> 82.18%. Nenhuma mensagem de email foi aberta/alterada.
- O inventário Linux contém Chrome 153 (391.1 MiB), Headless Shell 153 (259.7 MiB) e Headless Shell 148 (52.0 MiB). Puppeteer 25.11.0 no repositório usa `headless: true` no runner, que seleciona o Chrome completo, mas também aceita `runtime.executablePath` configurado e outros programas do alojamento podem usar shells diretamente. Ausência de referência no repositório não comprova falta de uso.
- `.github/workflows/cpanel-headless-audit.yml` executa apenas um diagnóstico protegido sem escrita: inventário de versões/execuções e seus atributos de acesso (não fiáveis sob relatime/noatime), mais contagem de tarefas cron com referências explícitas a Shell/Chrome/Node/Puppeteer, sem imprimir comandos, domínios, arquivos privados ou credenciais. O inventário de cron **não** comprova ausência de executáveis usados por processos interativos, jobs encadeados ou variáveis de ambiente.
- **Nenhuma remoção de browsers foi autorizada pela lógica do diagnóstico.** Antes de apagar binários instalados, é necessário obter prova positiva suficiente (configuração e processos ou comportamento funcional, existência de binário alternativo testado) e plano de recuperação; não executar uma eliminação recursiva por suposição. Os emails seguem fora de âmbito.

## Resultado final da auditoria de dependências Headless Shell (31/10/2026)

- O workflow protegido [41203927397](https://github.com/danieldeploy/room-check/actions/runs/41203927397) terminou com sucesso, exclusivamente em leitura.
- Inventário Linux: Chrome completo **153.0.8010.36** (303 ficheiros, **391.08 MiB**); Headless Shell **148.0.7778.97** (3 ficheiros, **52.04 MiB**, último atime registado há **17 dias**); Headless Shell **153.0.8010.36** (287 ficheiros, **259.74 MiB**, atime há **53 dias**).
- Cron da conta: **5 tarefas**; **0** referências explícitas a `chrome-headless-shell`, `puppeteer` ou às versões identificadas; **1** tarefa contém execução Node e **5** são invocações de scripts/wrappers, pelo que as dependências indiretas e o `executablePath` configurado não estão comprovados.
- O servidor SSH recusou ligação pela porta 22 (timeout); processos Linux em execução e ambientes de runtime não puderam ser inspecionados. `atime` não é prova fidedigna de utilização ou falta de utilização por causa das opções relatime/noatime.
- **Decisão de segurança:** nenhuma das duas instalações Headless Shell foi eliminada. Potencial de libertação: **311.78 MiB**, condicionado a prova positiva de não utilização e mecanismo testado de recuperação. A inspeção não atribui autorização de remoção a partir de ausência de referências.
- Auditoria read-only posterior: `~/.cache` ~704.8 MiB, `logs` ~117.1 MiB depois da eliminação confirmada do log antigo, quota DiskUsage ~4934.5/6000 MiB. Emails preservados.

## Verificação adicional pela API WHM protegida (31/10/2026)

- O WHM API token protegido de `management-hub-production` já funciona em `server50.romania-webhosting.com:2087` como revendedor `fazenda`, com inspeção delegada da conta `welcome`. O acesso não depende de SSH e já recuperou backups, cache NPM e um log antigo. Falha SSH na porta 22 **não** equivale a indisponibilidade WHM.
- A auditoria inicial [41203927397](https://github.com/danieldeploy/room-check/actions/runs/41203927397) confirmou cinco tarefas cron, sem referências explícitas a `chrome-headless-shell`, mas uma execução Node.js e scripts intermediários. Falta validar usos indiretos e caminhos `executablePath`.
- O workflow novo, de **leitura apenas**, `.github/workflows/cpanel-whm-runtime-capabilities.yml` interroga a lista pública de funções WHM API1 (filtrando apenas nomes ligados a processos) e os pontos de entrada que as tarefas cron referenciam, através de `Fileman/get_file_content` com caminhos estritamente limitados à árvore de código da conta. Só produz contagens de padrões Chrome/Puppeteer e disponibilidade de função. Não imprime os scripts, comandos, nomes de ficheiros, segredos nem conteúdo de email. Proíbe entrada em pastas de mail, ficheiros de configuração e travessias de diretórios.
- Esse diagnóstico **não elimina browsers nem serviços** e ainda não constitui prova de que as duas versões Headless Shell são dispensáveis; a conclusão depende da execução real protegida e dos resultados do endpoint WHM. Nenhuma eliminação por suposição.


## Corrigir leitura de cron na auditoria WHM (31/10/2026)

- O primeiro diagnóstico protegido `cpanel-whm-runtime-capabilities.yml` [41205480771](https://github.com/danieldeploy/room-check/actions/runs/41205480771) não fez alterações, mas terminou com `invalid_cron_entry`: a lista API2 Cron inclui linhas de metadata sem campo `command`.
- A correção mantém a rejeição de linhas malformadas, mas ignora as que não têm comando, de acordo com a auditoria de cron já existente. Teste de regressão atualizado. O diagnóstico continua **só de leitura**, sem consultar mail, sem devolver comandos ou ficheiros privados e sem eliminar browsers.
- A prova de utilização de Headless Shell continua pendente até uma execução protegida bem sucedida e revisão dos resultados da API.
