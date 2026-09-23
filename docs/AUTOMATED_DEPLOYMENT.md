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

Nota operacional: uma execução `pull_request` após a integração por API valida o código, mas o job `deploy` só corre com o evento `push` na branch de produção. Confirmar o tipo de evento na execução da CI antes de atribuir uma omissão de deploy às variáveis de configuração.\n\nNão basta guardar um token: a ativação só fica concluída depois de um diagnóstico
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
