# Publicação automática do Management Hub

O fluxo preparado é: alteração revista na branch de produção → testes completos
no GitHub → backup privado no alojamento → atualização/publicação pelo WHM →
verificação HTTPS. Depois de ativado, a publicação habitual não exige abrir o
cPanel. Falhas interrompem o processo e ficam registadas no GitHub Actions.

## Ativação

1. Integrar esta preparação na branch `agent/room-item-assignments`, depois de CI
   aprovada. `main` continua a ser uma branch antiga; o fluxo não usa `workflow_run`
   nem depende de mudar a branch principal do repositório.
2. Configurar o ambiente GitHub `management-hub-production` com **Selected branches
   and tags** e uma regra do tipo **Branch**, exatamente `agent/room-item-assignments`.
   Não autorizar tags, PRs ou outras branches. Não configurar um revisor obrigatório
   por deployment se o objetivo é publicação sem intervenção rotineira.
3. Guardar `WHM_API_TOKEN` como **environment secret** nesse ambiente, nunca como
   ficheiro, variável pública, segredo de PR ou segredo na aplicação. A credencial
   deve ter apenas as ACLs necessárias documentadas em `CPANEL_API_DEPLOYMENT.md`.
   O alcance nativo de um token WHM é maior do que o destino fixado no cliente.
   A autorização para o guardar neste destino e a validade precisam de estar
   definidas antes da ativação. O token temporário de testes não é uma solução
   permanente; uma credencial sem expiração exige revogação/rotação quando necessário.
4. Começar por um diagnóstico UAPI apenas de leitura num executor autorizado.
   O workflow `Hosting connectivity` testa DNS, TCP, TLS e HTTPS **sem credenciais**;
   o seu sucesso não valida permissões UAPI.
   Para testar pelo job protegido, definir a variável do repositório
   `HUB_DIAGNOSTIC_ENABLED=true` e manter `HUB_DEPLOY_ENABLED` ausente/falsa.
   O job usa `--diagnostic`, faz apenas consultas e não publica detalhes privados
   do diagnóstico nos logs. Executa num push da branch de produção após CI.
5. Depois de validar a credencial e os requisitos de backup, definir a variável
   **do repositório** `HUB_DEPLOY_ENABLED=true`. Ausente ou com outro valor, o job
   de publicação é ignorado. A variável não é um segredo e não pertence ao ambiente,
   pois a condição do job é avaliada antes de o ambiente ser carregado.
6. Publicar um commit revisto na branch de produção e confirmar o resultado do
   primeiro ciclo. Guardar a proteção de branch com PR e CI obrigatórios conforme
   a política escolhida para o projeto; o fluxo não deve receber pushes não revistos.

Nota operacional: uma execução `pull_request` após a integração por API valida o código, mas o job `deploy` só corre com o evento `push` na branch de produção. Confirmar o tipo de evento na execução da CI antes de atribuir uma omissão de deploy às variáveis de configuração.

Não basta guardar um token: a ativação só fica concluída depois de um diagnóstico
autenticado e de uma publicação real verificada.

## Garantias do fluxo

- O job `deploy` depende do job `validate` no mesmo workflow e só corre num push
  da branch exata de produção, com a ativação explícita acima.
- O checkout de publicação usa o SHA que passou nos testes e não guarda a
  credencial GitHub no checkout. A credencial WHM só entra nos passos protegidos
  de inspeção e publicação.
- Existe uma única fila de publicação, sem cancelar um deployment em execução.
- As pastas públicas do leitor PDF.js recebem explicitamente permissões 0755;
  os ficheiros recebem 0644. O teste de preparação usa a mesma umask 077 da
  publicação, incluindo a reparação de pastas já existentes. Isto não altera
  as permissões do armazenamento privado.
- O primeiro passo protegido usa o diagnóstico de leitura existente para
  confirmar o acesso autenticado antes da inspeção/reparação do clone. Recusas
  HTTP 401/403 aparecem como códigos fixos, sem mensagens do servidor ou segredos.
  Qualquer falha impede os passos seguintes; as garantias de publicação mantêm-se.
- Antes de alterar o alojamento, o cliente confirma o HEAD remoto, a origem Git,
  a branch, o estado das tarefas e que o avanço é fast-forward. Volta a confirmar
  antes de publicar. Um commit ultrapassado por outro não é publicado por este fluxo.
- Uma versão já publicada faz apenas verificação de saúde. Uma tarefa anterior
  falhada, cancelada ou incerta exige inspeção; não há repetição cega de mutações.
- Cada publicação começa com um passo separado `--inspect-state`, apenas de
  leitura, que verifica conta, origem, branch, estado do repositório e histórico
  de tarefas. Só apresenta os SHAs confirmados e o ID/estado da última tarefa.
  Se o WHM devolver 403 ou o estado não for seguro, o passo de publicação não
  corre. Esta inspeção usa a ligação e o segredo já existentes no GitHub Actions;
  não exige mudar variáveis nem autenticar nas definições pelo navegador.
  Depois da inspeção, o publicador repete as verificações e mantém os bloqueios
  de backup, commit exato, fast-forward e tarefas pendentes/falhadas. A inspeção
  não autoriza ignorar uma falha nem comprova permissão de escrita.
- A `.cpanel.yml` chama uma única tarefa, `deploy/release.sh`, que mantém um bloqueio
  durante toda a publicação e termina ao primeiro erro. O backup antecede qualquer
  migração ou cópia. A sua falha impede os passos seguintes. A API acompanha o ID
  exato de deployment.
- A verificação final exige HTTPS válido e o formulário de login esperado. Não
  substitui testes funcionais autenticados dos módulos.
- A comparação GitHub consulta a segunda página, que mantém os dados globais de
  ancestralidade sem os patches de ficheiros enviados na primeira. Isto permite
  publicar dependências maiores sem aumentar o limite de resposta de 512 KiB.
  Mantém a mesma branch protegida, os SHAs exatos e a exigência de fast-forward;
  erros ou dados incompletos bloqueiam a publicação. Os testes cobrem comparação
  válida com lista de commits vazia, divergência e metadados inválidos.

## Backup e recuperação

`deploy/prepare_release_backup.php` corre apenas por CLI, antes de cada publicação.
Guarda uma cópia nova da aplicação instalada, das pastas privadas `cron`,
`invoice-runner` e `migrations` existentes, e da base de dados. Cada execução tem
um diretório único, permissões privadas e um manifesto de checksums. O ficheiro
temporário de autenticação MySQL é eliminado mesmo se o dump falhar.

Requer Bash, `flock`, `proc_open`, `git`, `tar`, `mysqldump`, PDO MySQL, permissões de leitura,
espaço e quota. O dump usa uma transação para tabelas InnoDB e o helper recusa
tabelas não transacionais. Não devem existir alterações DDL concorrentes. O
bloqueio do worker de faturas só protege esse worker durante o backup; não
suspende todas as operações web, traduções ou WhatsApp.

Os backups ficam fora da pasta pública e não são enviados para GitHub. Esta
versão não elimina backups antigos automaticamente. A utilização de quota deve
ser acompanhada; falta de espaço interrompe a publicação. Uma política de retenção
deve abranger apenas backups próprios e ser definida antes de eliminar históricos.

A cópia da aplicação existente continua a usar vários comandos e não é atómica.
Não executar update/deploy manual em simultâneo com o fluxo automático. A API
cPanel não aceita um SHA como condição atómica na criação da tarefa.

Não há restauração automática da base: isso poderia apagar operações posteriores
ao backup. Após falha, consultar a tarefa e validar o estado. Para código compatível,
preferir um commit de reversão revisto. Recuperações de dados exigem decisão e
verificação específicas. Um manifesto confirma os ficheiros criados, não um teste
de restauração; testar a recuperação num ambiente descartável antes de confiar no
processo para alterações destrutivas.

## Compactação dos dumps privados

Após a publicação, o auxiliar `deploy/compress_release_dumps.php` compacta até 50 backups históricos com mais de 48 horas, sem eliminar diretórios ou pontos de recuperação. Verifica os hashes SHA-256 existentes, comprime cada `database.sql`, confirma que a descompressão reproduz exatamente os bytes originais e atualiza atomicamente `complete.json` para formato 2 (`database.sql.gz`). Só depois retira o SQL redundante.

Falhas na compactação deixam o backup original intacto e não bloqueiam a publicação. A restauração do formato 2 requer descompressão antes da importação MySQL; o teste real de restauro de base de dados continua a ser obrigatório para futuras eliminações de backups inteiros.

## Migrações e manutenção

A investigação pontual de logs concluiu com sucesso no deployment 384 e no
relatório protegido 41940619898. A chamada temporária no fim do release foi
retirada; os respetivos marcador e relatório privados são preservados.
A verificação incremental diária usa o cron existente, conforme
`CPANEL_DISK_MAINTENANCE.md`, sem adicionar execução de logs ao deploy.

O fluxo mantém apenas a migração específica já integrada pelo projeto. Não executa
automaticamente todos os ficheiros SQL antigos. Cada migração futura deve ser
versionada, testada numa base descartável e adicionada explicitamente ao deployment.
Não existe endpoint público para SQL ou comandos arbitrários.

Para suspender a automação, retirar `HUB_DEPLOY_ENABLED=true`; isso não cancela uma
publicação já iniciada. A rotação da credencial é feita no WHM e no segredo do
ambiente GitHub, sem alterar o código nem pedir login cPanel a cada atualização.

Referências: [ambientes de deployment](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments),
[segredos](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets),
[eventos de workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows).

## Read-only diagnosis when cPanel repository is not deployable

When the protected deploy preflight fails, the production-only CI job may
execute `deploy/cpanel_repo_readiness.py`. It consults only the fixed welcome
Git clone via existing WHM credentials in the protected environment, reads
allowlisted Fileman directory metadata, compares names against the GitHub
**installed commit** (not the latest HEAD), and prints only counts and safe
classifications of untracked items. It makes **no changes** to Git files or
server settings. This diagnostic does not authorize forcing updates, deleting
untracked files or bypassing `repository_not_deployable`. A confirmed cause
must be corrected through a separate reviewed and guarded operation.

### Guarded one-time recovery of the current dirty staging clone

The reviewed `deploy/cpanel_git_worktree_recovery.py` runs only in the
protected production GitHub environment, before the ordinary inspect/update/
deploy gate. It is bound to the **previously inspected** welcome clone at
commit `ee8c516c` and checks a fixed inventory of exactly nine untracked,
regular files (one at repository root, four under deploy, one under docs,
three under tests); it rejects any missing tracked file, directory, symlink,
protected name, concurrent deployment or changed SHA. The old SHA must be a
verified fast-forward ancestor of the current reviewed production HEAD.

Instead of deleting or overwriting files, it moves them into a private
`0700` quarantine **outside both the staging clone and web root**, preserving
names and metadata. It then requires cPanel to report a clean deployable Git
working tree. Failure stops the normal release and requires fresh read-only
inspection before retry. Once the repository is clean, this one-off recovery
is a no-op; the original backup gate, SHA checks and deploy verification remain
mandatory. No mail, database, original backup, `.git` contents or live PHP is
modified by this operation.

The first guarded attempt on 2026-10-29 did **not** confirm the API2 Fileman
`fileop` move (`quarantine_move_unconfirmed`). A subsequent protected read-only
scan still reported the original nine untracked entries, so no Git release was
attempted. The follow-up starts with an explicit read-only inspection of the
pre-existing private quarantine: only exactly the four known, empty subfolders
are reusable. A nonempty or unknown quarantine aborts for investigation. It
then uses documented UAPI `Fileman::move_file` with explicit `source` and
`destination`; each move is followed by readback of source/destination and
metadata. No automatic retry is allowed after an uncertain response.

The 2026-10-30 UAPI recovery confirmed all nine file moves and reported zero
untracked and zero missing tracked entries, but the cPanel clone remained
`deployable=false`. An additional protected **read-only** diagnostic,
`deploy/cpanel_tracked_integrity_probe.py`, compares tracked file sizes against
the installed SHA and checks known Git lock filenames. It reads no contents
or credentials and prints only counts and short hashed tracked-path indicators.
Any evidence of changed tracked files requires a separate reviewed recovery
before another release; never `git reset --hard` merely from size metadata.

## Reparação controlada da cópia Git de preparação (30/10/2026)

O diagnóstico protegido apontou dois ficheiros versionados alterados e dois ficheiros extra na pasta `.github` do clone de *staging*, mesmo depois de preservar os nove extras anteriores em quarentena privada. A correção guardada pelo workflow usa um novo diretório privado (0700), separadamente do anterior; valida novamente o SHA instalado, ausência de tarefas ativas, divergências exatas e fast-forward, conserva os originais e extras, e só então repõe `ci.yml` e `PROJECT_STATE.md` a partir dos blobs de GitHub correspondentes ao SHA **instalado**, com comparação do hash Git e releitura no servidor. O conteúdo enviado ao WHM segue num corpo POST, não na URL. Se falhar qualquer etapa, não repete operações incertas e não autoriza o deploy. O fluxo habitual mantém os testes, backup pré-publicação e verificações do commit e da versão instalados.


## WHM alert activation after Meta UI approval (2026-11-05)

The reviewed optional `deploy/activate_whm_alerts_once.php` is fixed to the
welcome Hub and the existing configured manager. After observing the dedicated
Utility/PT template active in Meta, the owner-authorized test is sent through
the existing client. An exclusive private pre-send receipt prevents repetition;
only a confirmed provider message ID enables default incident sending. A failure
does not mask a completed core release and is checked by daily acceptance.
Do not reset an uncertain receipt, expose provider IDs/recipients or equate API
acceptance with handset delivery. No app secret or config.local.php is rewritten.


## One-off inactive-log cleanup — 2026-11-06

The owner-authorized optional CLI `compact_inactive_logs_once.php` runs after
normal release tasks under the existing deploy lock. Its fixed exclusive
private receipt prevents repeats; a failure does not mask a completed core
release. The helper preserves complete historical bytes in verified private
gzip before removing only inactive redundant plaintext. It never changes
active logs, daily cursors or the historical audit marker. Read
`CPANEL_DISK_MAINTENANCE.md` for eligibility, recovery and live acceptance;
never repeat an ambiguous run by resetting its marker.

### Verificação da divisão de scans PDF

Depois do backup e antes das cópias, `deploy/verify_receipt_split.php` carrega apenas
internamente a configuração instalada e verifica o Node configurado com o splitter
novo e um PDF sintético de duas páginas. Usa uma pasta privada própria e remove os
ficheiros de teste; não lê scans nem envia emails. Falha bloqueia a publicação.
Não instala Node/npm/Puppeteer nem altera credenciais. O resultado só apresenta
sucesso/falha e a contagem de páginas.

A falha 390 foi inspecionada em leitura: o teste de PDF parou antes das cópias
porque `/usr/bin/node` não existe; o Node 22 privado já usado pelo cron existe.
A recuperação atual fica limitada a esse ID e ao SHA
`372a1f13a4adf84e1096448e7a908fe57372a887`, exigindo uma versão descendente
corrigida e todas as verificações normais. O divisor e o teste usam a mesma
resolução do Node existente. Qualquer nova falha volta a bloquear a publicação.

### Local receipt decoder dependencies

After the existing backup gate, `prepare_receipt_runtime.php` installs only the
locked `invoice-runner/receipt-tools` package (pdfjs-dist, its canvas runtime and
zxing-wasm) using the configured/inspected Node environment's npm. Lifecycle scripts
are disabled; no browser/Puppeteer is installed. The package-lock checksum skips
unchanged installs; the following runtime probe still runs every release. Both
installation and local inspect/extract are bounded, private and fail before any
application copies. The normal private runner copy includes these dependencies.
A failed installation/probe is not a completed release: inspect installed commit
and the guarded release state before any retry.

### Diagnóstico do leitor no release400
A instalação npm terminou, mas o teste sintético de execução falhou antes das
cópias. A versão instalada foi comparada por hash. O probe passa a classificar
o stderr apenas em categorias fixas, sem conteúdo bruto. A recuperação fica
vinculada ao ID400 e SHA6697ccd; qualquer falha nova mantém o bloqueio.

O release401 classificou a falha como `wasm_memory` no teste anterior às cópias.
A recuperação seguinte fica limitada ao ID401/d937695. O processo PDF usa a
opção Node `--disable-wasm-trap-handler`, que substitui a grande reserva virtual
por verificações de limites em linha. O teste CI com Node 22.14 e limite virtual
de 4 GB reproduz a restrição; o probe real continua obrigatório antes de publicar.

## Receipt summary acceptance probe (2026-11-14)

`deploy/verify_receipt_summary_once.php` runs after the normal guarded release.
It uses the existing enabled invoice notification setting, active manager and
protected WhatsApp credentials with the neutral receipt template described in
the invoice flow. A private encrypted pre-send receipt makes it once-only even
across a crash or repeated deployment. It prints only a safe acceptance state;
never a phone number, message ID, credentials or invoice data. The labelled
synthetic message does not submit invoices or alter process records. An
uncertain send is not retried, and acceptance is not proof of handset delivery.

The probe is currently guarded by `RECEIPT_SUMMARY_TEMPLATE_APPROVED=false`
because Meta reclassified the template as Marketing/In review. This is not an
application-release failure. Receipt summaries are preserved without sending
until Utility approval is verified and the guarded probe is explicitly enabled
in a reviewed release. An uncertain probe never enables summaries.

## Mobile PDF assets and invoice experiment retirement (2026-11-15)

After the ordinary release backup and pinned receipt dependency preparation,
prepare_receipt_viewer.php copies only PDF.js 6.4.299 browser files, worker,
cmaps/fonts/wasm and license to the versioned public asset directory. It rejects
links, mismatched versions and failed copies/hashes. No private Node tree is
exposed. The published .htaccess supplies JavaScript/wasm MIME types.

After code copies, retire_invoice_diagnostics.php removes the exact old Azure
page/class still left by cp -R, plus azure-document-intelligence.enc,
azure-receipt-tests.enc and azure-diagnostics.lock from the configured vault.
Every removed file first receives a hash-verified 0600 recovery copy inside
0700 room-check-backups/retired-invoice-diagnostics-v1. Links/path escapes,
concurrent diagnostics and mismatching existing recovery copies stop cleanup.
Unfinished/uncertain Azure tests retain all private files. Only aggregate counts
and completion state reach release logs; result.json remains private. Shared
keys, invoice history, invoices, backups and current providers are preserved.
The old deployment-400 diagnostic workflow is removed; current release probes
and protected deployment gates stay active. No Azure cloud resource is deleted.


### Reviewed one-time receipt wait pause — 2026-11-17

After the ordinary backup and source copies, `pause_receipt_wait_once.php` uses
the existing invoice lock and encrypted vault. It is bound to the digest of the
owner's inspected run; it can only stop one archived, unsent, future pacing wait,
with no matching ledger attempt. It atomically retains the previous record and
a once-only marker; subsequent releases do not repeat the suspension. Its only
output is an allowlisted outcome, never invoice data. A failed guard aborts the
release and requires state inspection before retry. Accepted/progressed records
are never rolled back. Manual recovery uses the existing receipt Try again action.


The deployment431 recovery reuses the existing protected recovery entrypoint.
It admits only dc4eb6e/431 after a read-only log check confirms the duplicate
TranslationProtection fatal before a pause result, and the installed InvoiceText
hash matches the reviewed source. The corrected helper loads installed classes
only, preventing mixed clone/application class declarations. All other failures
remain blocked. Successful later deployments use the ordinary release path.


The receipt pause CLI emits only its fixed outcome plus current intake/automatic
booleans, read while holding the invoice lock. The existing protected release-log
reader allowlists those values for acceptance evidence; arbitrary fields or raw
messages are never forwarded. This check is separate from public-login health.
