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

Não basta guardar um token: a ativação só fica concluída depois de um diagnóstico
autenticado e de uma publicação real verificada.

## Garantias do fluxo

- O job `deploy` depende do job `validate` no mesmo workflow e só corre num push
  da branch exata de produção, com a ativação explícita acima.
- O checkout de publicação usa o SHA que passou nos testes e não guarda a
  credencial GitHub no checkout. A credencial WHM só entra no passo de publicação.
- Existe uma única fila de publicação, sem cancelar um deployment em execução.
- Antes de alterar o alojamento, o cliente confirma o HEAD remoto, a origem Git,
  a branch, o estado das tarefas e que o avanço é fast-forward. Volta a confirmar
  antes de publicar. Um commit ultrapassado por outro não é publicado por este fluxo.
- Uma versão já publicada faz apenas verificação de saúde. Uma tarefa anterior
  falhada, cancelada ou incerta exige inspeção; não há repetição cega de mutações.
- A `.cpanel.yml` chama uma única tarefa, `deploy/release.sh`, que mantém um bloqueio
  durante toda a publicação e termina ao primeiro erro. O backup antecede qualquer
  migração ou cópia. A sua falha impede os passos seguintes. A API acompanha o ID
  exato de deployment.
- A verificação final exige HTTPS válido e o formulário de login esperado. Não
  substitui testes funcionais autenticados dos módulos.

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

## Migrações e manutenção

`deploy/project_migrations.php` gere todos os módulos através de `schema_migrations`.
Na primeira execução regista `002–029` como `baseline`, **sem executar esse SQL**.
Isto adota a instalação existente; não demonstra que cada migração histórica foi
executada e não serve para instalar uma base vazia. O manifesto imutável
`deploy/migration-baseline.json` fixa os nomes e hashes dessas migrações.

As novas migrações começam em `030_nome.sql`, com número único e crescente. Depois
dos testes e do backup, o runner executa apenas as pendentes, por ordem. Guarda
checksum, estado e duração; rejeita alterações de ficheiros já registados,
ficheiros desaparecidos, migrações fora de ordem e tentativas de repetição após
falha/interrupção. Um bloqueio MySQL serializa runners de migração. Não editar nem
eliminar migrações aplicadas; escrever uma nova migração corretiva após diagnóstico.

DDL MySQL não permite rollback geral de uma migração: uma falha pode deixar
alterações parciais. O deployment para antes da cópia de código e não tenta
restaurar a base nem repetir o SQL automaticamente. Migrações devem manter
compatibilidade com a aplicação e workers ainda em execução, terminar quaisquer
transações abertas e ter teste de dados e recuperação específico quando necessário.
O bloqueio das migrações não coloca a aplicação inteira em manutenção.

O CI testa o runner e executa as migrações futuras numa base descartável construída
com `database.sql` e os complementos históricos ainda ausentes desse esquema.
Não existe endpoint público para SQL ou comandos arbitrários.

## Cron jobs do projeto

`deploy/cron-jobs.json` é o manifesto versionado: adicionar um worker privado,
alterar o horário ou retirar um job desse manifesto produz a alteração no próximo
deployment autorizado. `my2n-scheduler.php` permanece fora do manifesto: é apenas
um esqueleto e não executa a agenda My2N.

A auditoria da conta `welcome` em 22/09/2026 encontrou apenas três jobs, todos do
Hub e executados a cada minuto: WhatsApp, traduções e faturas. O manifesto preserva
o executável Node de faturas em `nodevenv/booking-vault-agent/22/bin/node`.
Os horários de negócio permanecem nos módulos (Europe/Lisbon); estes três cron
jobs apenas invocam os workers a cada minuto, sem alterar o timezone de outros jobs.

`sync_cron.php --check` lê e calcula o resultado sem escrever. Corre antes do backup
e das migrações. Após copiar os workers, `--apply` cria um backup privado `0600`
do crontab, confirma que não mudou, instala o bloco delimitado do Hub e verifica
a leitura final. Entradas fora desse bloco são preservadas byte a byte. Na adoção
inicial, apenas as três linhas **exatas** auditadas são removidas dos locais antigos.
Uma variante desconhecida que invoque o mesmo worker interrompe a sincronização
para revisão, em vez de ser eliminada ou duplicada.

O lock serializa este gestor; a comparação antes da escrita deteta muitas alterações
concorrentes, mas `crontab` não oferece compare-and-swap. Não editar o crontab no
cPanel durante o deployment. Uma falha de verificação exige inspeção: não há
restauro automático que possa sobrescrever uma edição entretanto efetuada.

## Preparação de repositório privado

O cliente aceita apenas as origens HTTPS existentes e as duas formas SSH do mesmo
repositório `danieldeploy/room-check`. Integrar e validar este suporte antes de
trocar o remoto usado pelo cPanel.

1. Disponibilizar SSH/Terminal para a conta `welcome`. Na auditoria de 22/09/2026,
   o cPanel não apresentava essas ferramentas e o WHM mostrava `Shell Access`
   desativado e não editável para a revenda. A disponibilização deve ser feita
   pelo administrador do alojamento; não alterar o pacote das outras contas.
2. Gerar uma chave dedicada no servidor, guardar a chave privada fora de
   `public_html` e registar **apenas a pública** como Deploy Key sem escrita no GitHub.
3. Configurar a identidade SSH apenas para este repositório, verificar a identidade
   do host GitHub e testar leitura com `git ls-remote` usando essa chave.
4. Atualizar o remoto cPanel, confirmar a branch e o SHA, e testar `Update from
   Remote` sem publicar. Só depois tornar o repositório privado e repetir a leitura.
5. Verificar que o plano GitHub suporta o ambiente protegido e os seus segredos em
   repositórios privados. Nunca mover o token WHM para um segredo de PR.

O objetivo é restringir o acesso futuro. Tornar privado não apaga clones ou forks
públicos anteriores. O procedimento está descrito na
[documentação cPanel](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-access-to-private-repositories/)
e os efeitos na [documentação GitHub](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/managing-repository-settings/setting-repository-visibility).

Para suspender a automação, retirar `HUB_DEPLOY_ENABLED=true`; isso não cancela uma
publicação já iniciada. A rotação da credencial é feita no WHM e no segredo do
ambiente GitHub, sem alterar o código nem pedir login cPanel a cada atualização.

Referências: [ambientes de deployment](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments),
[segredos](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets),
[eventos de workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows).
