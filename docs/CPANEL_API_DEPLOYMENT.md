# Management Hub — publicação controlada pela API do cPanel

O cliente `deploy/cpanel_api.py` permite consultar o repositório, executar **Update
from Remote** e pedir **Deploy HEAD Commit** através da UAPI. Corre no computador
de desenvolvimento ou num executor autorizado com Python 3.9+, sem bibliotecas
adicionais. Não é instalado em `public_html`, não cria endpoints HTTP no Hub e não
ativa publicações automáticas do GitHub.

## Estado verificado em 22 de setembro de 2026

- A base de produção/desenvolvimento é `agent/room-item-assignments`, com HEAD remoto
  `9a3cc9be6ffe01cacf9703eb9f4156caf65f5c0b` nesta verificação. Confirmar novamente
  antes de cada operação. `main` é antiga; `codex/management-hub` contém também o
  trabalho Windows ainda em validação. Este cliente não troca essas branches.
- O pacote `fazenda_welcome` usa a lista `default`, que a conta revendedora `fazenda`
  não consegue consultar ou editar. A lista de teste criada no WHM apresenta a opção
  **API Tokens**, mas está vazia e não está associada à conta. Não a atribuir a
  `welcome`: retiraria as funcionalidades atuais.
- A base de dados e o utilizador MySQL da aplicação têm ambos o nome
  `welcome_roomcheck`. O utilizador já tem **ALL PRIVILEGES**, incluindo `ALTER`,
  `CREATE` e `UPDATE`; não foi necessário aumentar privilégios.
- Ainda não foi criado um token nem validada uma ligação HTTPS autenticada por
  token. O cliente foi testado apenas com respostas simuladas. Não houve update,
  deployment ou migração em produção por este cliente.
- A tentativa de consultar a configuração com o UAPI local do alojamento falhou
  por falta do executável interno `/usr/local/cpanel/cpanel`. O cron temporário foi
  removido. Isso não comprova uma falha da UAPI HTTPS; é uma limitação observada do
  ambiente local da conta.

Para concluir a configuração, é necessária uma cópia exata da lista `default`
com **API Tokens** acrescentado, ou a ativação equivalente feita pela Claus Web.
Preservar as restantes funcionalidades da conta. Depois, criar o token próprio de
`welcome`, guardá-lo por um meio protegido e começar pela consulta `status`.

## Credencial e configuração

Usar um **token cPanel da conta `welcome`**, nunca o token administrativo do WHM.
O token permite operar com os privilégios da conta; tratá-lo como uma password.
Injetá-lo na variável de ambiente `CPANEL_API_TOKEN` a partir de um gestor de
segredos ou formulário privado do executor. Não o colocar em argumentos, URLs,
ficheiros do repositório, logs, mensagens ou histórico de comandos.

As restantes variáveis não são segredos:

| Variável | Valor por defeito / regra |
| --- | --- |
| `CPANEL_ORIGIN` | `https://server50.romania-webhosting.com:2083`; é a única origem permitida nesta versão. |
| `CPANEL_USER` | `welcome`; tem de corresponder ao dono do token. |
| `CPANEL_REPOSITORY_ROOT` | `/home/welcome//home/welcome/repositories/room-check`, conforme o caminho devolvido pelo cPanel. |

O caminho invulgar acima é deliberado. Confirmar na conta; não o corrigir por
suposição. Para comparar respostas, o cliente normaliza apenas barras repetidas;
rejeita `.` e `..`. O caminho enviado à API conserva o valor configurado.

A origem é fixada no código para impedir envio acidental da credencial para outro
servidor. Uma migração de alojamento exige rever esse destino. TLS e certificado
são verificados; não existe opção de desativar essa verificação. Redirecionamentos
e proxies definidos no ambiente não são seguidos. O executor tem de alcançar
diretamente o servidor na porta 2083.

## Operações

Antes de escrever, confirmar que o commit está revisto, que o backup necessário
existe e que ninguém está a fazer push, update ou deploy em simultâneo. A aprovação
de uma versão do Hub continua necessária conforme o README e o pedido em curso.

### 1. Consultar — não altera o servidor

```sh
python3 deploy/cpanel_api.py
```

Equivale a `status`. Mostra branch, HEAD do repositório cPanel, último commit
publicado, indicador de possibilidade de deployment e estados das tarefas.
O token tem de estar no ambiente. A saída é JSON; `ok: true` aqui significa
consulta concluída, não aprovação da versão nem teste funcional do Hub.

### 2. Atualizar a cópia Git — não publica a aplicação

Substituir `SHA_ATUAL` e `SHA_APROVADO` pelos hashes completos, de 40 caracteres:

```sh
python3 deploy/cpanel_api.py update --expected-current-commit SHA_ATUAL --expected-commit SHA_APROVADO
```

O cliente confirma a branch `agent/room-item-assignments`, a origem GitHub
`danieldeploy/room-check`, o HEAD atual, a ausência de tarefas pendentes e o
indicador `deployable` do cPanel. Só depois chama `VersionControl/update`,
especificando a mesma branch. Sem o parâmetro `branch`, essa API não faz pull.

Depois confirma que o HEAD resultante é exatamente o aprovado. Se o remoto avançar
para outro commit, a cópia Git pode já ter sido atualizada, mas a operação termina
com erro e não faz deploy. Não corrige automaticamente a branch nem faz reset.

### 3. Publicar o commit aprovado

```sh
python3 deploy/cpanel_api.py deploy --expected-commit SHA_APROVADO
```

Faz novas verificações, chama `VersionControlDeployment/create` e acompanha o ID
de deployment específico com `VersionControlDeployment/retrieve`. Uma resposta
`queued` ou `active` nunca é apresentada como publicação concluída. O cliente só
devolve `succeeded` depois do timestamp correspondente e da confirmação da branch
e do commit efetivamente publicados. A existência de uma tarefa antiga bem-sucedida
não valida uma tarefa nova.

O deployment executa a `.cpanel.yml` já existente. Depois de sucesso técnico,
verificar o login e as funcionalidades afetadas do Hub: a API não prova o resultado
funcional da aplicação. Não alterar `config.local.php` nem publicar segredos.

**Limite de concorrência:** esta API só recebe `repository_root`; não aceita um
SHA como condição atómica. A documentação também refere atualização `--ff-only`.
As verificações antes/depois detetam divergências, mas não impedem uma alteração
concorrente entre a consulta e a execução. Durante esta janela, suspender outros
pushes/updates/deploys. O cliente não tenta rollback automático de código ou dados.

### 4. Acompanhar uma tarefa existente

```sh
python3 deploy/cpanel_api.py wait --deploy-id ID_DA_TAREFA --expected-commit SHA_APROVADO
```

`wait` é apenas de leitura e não cria uma segunda publicação. O tempo máximo de
espera é, por defeito, 180 segundos, com pedidos de até 20 segundos; cada pedido já
em curso pode prolongar a espera pelo seu timeout. Ambos podem ser ajustados com
`--wait-timeout` (1–600) e `--request-timeout` (1–60).

Em caso de falha/cancelamento, o comando termina com código diferente de zero.
Em caso de timeout, interrupção ou falha de rede, o estado pode ser desconhecido:
consultar `status` e acompanhar a tarefa existente. Não repetir uma mutação sem
essa verificação. Quando a resposta de criação forneceu um ID, os erros de
acompanhamento incluem esse ID. Se a ligação caiu antes dessa resposta, consultar
o histórico no cPanel para encontrar a tarefa.

Os erros usam códigos fixos sem reproduzir corpos de respostas ou exceções HTTP.
Para diagnóstico detalhado, consultar o log da tarefa no cPanel por acesso
autenticado, sem publicar o seu conteúdo bruto.

## Alterações da base de dados

O token cPanel não é uma credencial MySQL. A API MySQL do painel gere bases,
utilizadores e privilégios; não oferece, neste fluxo, um endpoint para enviar
SQL arbitrário. Não abrir acesso remoto à base nem criar um endpoint público para
receber comandos SQL.

A publicação existente já chama, antes de copiar a aplicação:

```sh
/usr/local/bin/php deploy/prepare_invoice_workspace.php "$DEPLOYPATH" "$HOME/room-check-backups"
```

Esse script CLI usa a configuração privada da aplicação. Se faltarem as quatro
tabelas do workspace de faturas, verifica o repositório, obtém o bloqueio do worker,
guarda backup privado da aplicação e da base e aplica a migração aditiva/repetível
`029_invoice_workspace.sql`. Mantém código/configuração e base fora do Git.

**Se as quatro tabelas já existirem, termina imediatamente, sem criar um novo
backup.** Portanto, não é um mecanismo geral de backup por publicação nem um
executor de todas as migrações anteriores. Não voltar a executar indiscriminadamente
ficheiros SQL antigos: várias migrações só devem correr uma vez.

Para uma alteração futura com `ALTER TABLE` ou atualização de dados:

1. Preparar uma migração versionada e específica, com condições e resultado
   verificáveis; confirmar os privilégios estritamente necessários.
2. Testá-la numa base descartável, incluindo repetição/recuperação e preservação
   dos dados; não usar a base real em testes.
3. Preparar backup e recuperação adequados a essa migração. O helper atual não
   garante backup para migrações futuras.
4. Integrar a execução CLI privada no deployment revisto, antes do código que
   depende dela. A execução via API desencadeia essa publicação; não recebe SQL.
5. Validar o estado da base e a aplicação depois do deployment.

Este trabalho acrescenta apenas o cliente e documentação; não altera a
`.cpanel.yml`, o helper 029, privilégios MySQL ou qualquer tabela.

## Validação local

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p test_cpanel_api.py -v
```

Os testes simulam as respostas UAPI e não fazem ligações de rede. Cobrem rejeição
de branch/commit/origem incorretos, repositório não publicável, tarefas pendentes,
update para commit inesperado, conclusão assíncrona pelo ID certo, falha,
cancelamento, timeout, recusa de redirects e proteção da credencial. Aprovação
destes testes não substitui a validação HTTPS real depois de ativar os tokens.

## Documentação oficial consultada

- [Autenticação por token cPanel](https://docs.cpanel.net/knowledge-base/security/how-to-use-cpanel-api-tokens/)
- [VersionControl/retrieve](https://api.docs.cpanel.net/specifications/cpanel.openapi/repository-management/versioncontrol-retrieve)
- [VersionControl/update](https://api.docs.cpanel.net/specifications/cpanel.openapi/repository-management/versioncontrol-update)
- [VersionControlDeployment/create](https://api.docs.cpanel.net/specifications/cpanel.openapi/deployment-settings/versioncontroldeployment-create)
- [VersionControlDeployment/retrieve](https://api.docs.cpanel.net/specifications/cpanel.openapi/deployment-settings/versioncontroldeployment-retrieve)
- [Requisitos de deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/)
