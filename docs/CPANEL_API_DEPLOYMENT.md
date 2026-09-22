# Management Hub — publicação controlada pela UAPI, através do WHM ou cPanel

O cliente `deploy/cpanel_api.py` permite consultar o repositório, verificar as
funcionalidades e os privilégios MySQL, executar **Update from Remote** e pedir
**Deploy HEAD Commit** através da UAPI. Pode autenticar diretamente no cPanel ou
encaminhar as operações pelo WHM do revendedor. Corre no computador
de desenvolvimento ou num executor autorizado com Python 3.9+, sem bibliotecas
adicionais. Não é instalado em `public_html` e não cria endpoints HTTP no Hub.
A automação opcional do GitHub tem configuração própria em `AUTOMATED_DEPLOYMENT.md`.

## Requisitos

A base de produção é `agent/room-item-assignments`. Confirmar o seu HEAD antes de
cada operação. O cliente não muda de branch. Não atribuir uma lista de
funcionalidades de teste à conta: preservar as funcionalidades atuais.

O diagnóstico consulta os privilégios MySQL, sem os aumentar. A existência de
uma opção na interface não comprova uma chamada UAPI autenticada. Validar
conectividade, autenticação, leituras e publicação separadamente. Não registar
valores de credenciais nem detalhes operacionais de tokens neste repositório.

O transporte WHM usa um token do revendedor, pelo que a ausência da opção de
criar tokens próprios no cPanel de `welcome` não impede, por si só, este caminho.
A disponibilidade real das funções continua sujeita às permissões e restrições
do servidor. Não modifica a lista `default`, não concede `edit-account` e não
contorna funcionalidades desativadas ou restrições do sistema operativo.

Para utilizar o transporte direto do cPanel, continua a ser necessário ativar
**API Tokens** em `welcome`: pedir à Claus Web uma cópia exata da lista `default`
com essa opção acrescentada, ou uma ativação equivalente que preserve as restantes
funcionalidades. O token direto pertence apenas à conta `welcome`.

## Credencial e configuração

### Opção WHM para a configuração atual

Usar um token de `fazenda` com os privilégios **`cpanel-api` e `list-accts`**.
O quadro oficial associa `uapi_cpanel` a `list-accts`; `cpanel-api` permite executar
as APIs do cPanel através do WHM. Esta combinação ainda precisa de ser testada no
servidor. Não acrescentar `all`, `manage-api-tokens`, `create-user-session`,
`passwd` ou `edit-account`. O diagnóstico implementado não precisa de
`basic-whm-functions`.

**O token WHM não tem uma restrição documentada por conta cPanel.** A API de
criação permite selecionar ACLs, validade e IPs de origem, mas não uma conta de
destino. `cpanel.user=welcome` escolhe o destino de um pedido; não limita a
credencial no servidor. Tratar este token como capaz de atuar nas contas
pertencentes ao revendedor. A proteção no cliente
contra outras contas não substitui essa restrição no servidor.

Para a primeira validação, preparar um token com apenas
as duas ACLs acima e validade curta, confirmando a hora do servidor. Se existir um IP
fixo do executor, restringir a esse IP depois de o confirmar. A ativação deve
identificar expressamente o alcance da credencial e a sua validade.

Guardar o token exclusivamente no gestor de segredos do executor e injetá-lo
como `WHM_API_TOKEN`. Não o instalar na aplicação Hub nem no seu
`config.local.php`. Não o colocar em argumentos, URLs, ficheiros do repositório,
logs, mensagens ou histórico de comandos.

O cliente usa `GET /json-api/uapi_cpanel` em HTTPS/2087, autenticação no cabeçalho
`Authorization: whm fazenda:TOKEN` e os parâmetros fixos `api.version=1` e
`cpanel.user=welcome`. O módulo e a função vêm de uma lista fechada de operações.
Os argumentos UAPI mantêm os nomes normais. O cliente exige sucesso nas duas
camadas: `metadata.result=1` e `data.uapi.status=1`, sem erros UAPI.

### Opção direta cPanel

Usar um token cPanel da conta `welcome`, injetado como `CPANEL_API_TOKEN` a partir
do mesmo tipo de armazenamento protegido. Esta é a opção por defeito; selecionar
WHM explicitamente com `--transport whm`. Os tokens dos dois transportes não são
intercambiáveis e não existe fallback automático entre eles.

As restantes variáveis não são segredos:

| Variável | Valor por defeito / regra |
| --- | --- |
| `WHM_ORIGIN` | `https://server50.romania-webhosting.com:2087`; única origem permitida no transporte WHM. |
| `WHM_USER` | `fazenda`; único revendedor permitido pelo cliente. |
| `CPANEL_ORIGIN` | `https://server50.romania-webhosting.com:2083`; única origem permitida no transporte direto. |
| `CPANEL_USER` | `welcome`; destino fixo no transporte WHM. No direto, tem de corresponder ao dono do token. |
| `CPANEL_REPOSITORY_ROOT` | `/home/welcome//home/welcome/repositories/room-check`, conforme o caminho devolvido pelo cPanel. |

O caminho invulgar acima é deliberado. Confirmar na conta; não o corrigir por
suposição. Para comparar respostas, o cliente normaliza apenas barras repetidas;
rejeita `.` e `..`. O caminho enviado à API conserva o valor configurado.

A origem é fixada no código para impedir envio acidental da credencial para outro
servidor. Uma migração de alojamento exige rever esse destino. TLS e certificado
são verificados; não existe opção de desativar essa verificação. Redirecionamentos
e proxies definidos no ambiente não são seguidos. O executor tem de alcançar
diretamente o servidor na porta 2087 (WHM) ou 2083 (cPanel).

## Alcance das operações

Os comandos implementados são `doctor`, `status`, `update`, `deploy` e `wait`.
O cliente não é um encaminhador genérico: recusa outros módulos/funções e
parâmetros de alteração do destino. As restantes funções abaixo documentam
possibilidades da plataforma, sujeitas à versão e às funcionalidades ativas;
não são comandos já implementados ou validados neste cliente.

| Necessidade | Caminho e limite |
| --- | --- |
| Diagnóstico | Implementado: `Features/list_features_like`, `Mysql/get_privileges_on_database` e consultas Git/deploy. Só leitura. |
| Atualização e publicação de código | Implementado: `VersionControl/update`, `VersionControlDeployment/create` e `retrieve`. O código chega pelo Git; não é necessário upload UAPI. |
| Alterações SQL | Migrações privadas e versionadas executadas pelo deployment. A UAPI MySQL gere bases, utilizadores e privilégios; não executa SQL arbitrário. |
| Gestão de privilégios MySQL | `Mysql/set_privileges_on_database` existe, mas substitui a lista de privilégios. Não implementado nem necessário: `welcome_roomcheck` já tinha todos os privilégios. |
| Edição de ficheiros de texto | `Fileman/get_file_content` e `save_file_content` existem. Não implementados; manter alterações de código no Git. |
| Envio de ficheiros | `Fileman/upload_files` é explicitamente incompatível com `uapi_cpanel`. Exige um transporte separado, como a UAPI direta do cPanel com autenticação própria. |
| Cron | `Cron` pertence à API 2, sem equivalente UAPI para `add_line`. Exige o encaminhamento API 2 separado. Não implementado; conservar os três agendamentos atuais. |
| Backups | `Backup/fullbackup_to_homedir` inicia um backup e devolve um PID; não comprova conclusão. Não implementado; validar existência e recuperação antes de usar como proteção de uma migração. |
| PHP | `LangPHP/php_get_vhost_versions` e `php_set_vhost_versions` consultam/selecionam versões já instaladas. Não instalam bibliotecas de sistema. Não implementado. |
| SSL | Consultas e início de AutoSSL existem, sujeitos às funcionalidades disponíveis. Iniciar a tarefa não comprova emissão do certificado. Não implementado. |
| Quotas | `Quota/get_quota_info` consulta utilização/limites. Aumentar quotas exige permissões WHM diferentes. Não implementado. |
| Chrome, sandbox e Windows | Não são resolvidos por UAPI. Mantêm-se a recusa da Claus Web para o sandbox no shared hosting e a validação pendente do worker Windows. |

O âmbito preparado cobre publicação por Git e migrações integradas num deployment
revisto. Não representa administração irrestrita do servidor nem valida todas as
operações futuras do Management Hub.

## Operações

Antes de escrever, confirmar que o commit está revisto, que o backup necessário
existe e que ninguém está a fazer push, update ou deploy em simultâneo. A aprovação
de uma versão do Hub continua necessária conforme o README e o pedido em curso.

### 1. Consultar — não altera o servidor

```sh
python3 deploy/cpanel_api.py doctor --transport whm
python3 deploy/cpanel_api.py status --transport whm
```

`doctor` consulta os identificadores das funcionalidades habilitadas, os
privilégios do utilizador `welcome_roomcheck` na base com o mesmo nome e o estado
Git/deploy. Não cria tokens nem altera permissões. A saída limita-se a campos
selecionados e assinala `write_operations_validated: false`.

`status` mostra branch, HEAD do repositório cPanel, último commit
publicado, indicador de possibilidade de deployment e estados das tarefas.
O token tem de estar no ambiente. Para usar o transporte direto, omitir
`--transport whm` e fornecer `CPANEL_API_TOKEN`. Sem comando, o cliente executa
`status`. A saída é JSON; `ok: true` aqui significa
consulta concluída, não aprovação da versão nem teste funcional do Hub.

### 2. Atualizar a cópia Git — não publica a aplicação

Substituir `SHA_ATUAL` e `SHA_APROVADO` pelos hashes completos, de 40 caracteres:

```sh
python3 deploy/cpanel_api.py update --transport whm --expected-current-commit SHA_ATUAL --expected-commit SHA_APROVADO
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
python3 deploy/cpanel_api.py deploy --transport whm --expected-commit SHA_APROVADO
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
python3 deploy/cpanel_api.py wait --transport whm --deploy-id ID_DA_TAREFA --expected-commit SHA_APROVADO
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

O token do painel não é uma credencial MySQL. A API MySQL do painel gere bases,
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

O fluxo automático usa uma tarefa única `release.sh` na `.cpanel.yml`, que chama
`prepare_release_backup.php` antes das migrações e cópias. O helper 029,
os privilégios MySQL e as tabelas não são alterados por esta preparação. Consultar
`AUTOMATED_DEPLOYMENT.md` para requisitos, limites e recuperação.

## Validação local

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p test_cpanel_api.py -v
```

Os testes simulam as respostas UAPI e WHM e não fazem ligações de rede. Cobrem rejeição
de branch/commit/origem incorretos, repositório não publicável, tarefas pendentes,
update para commit inesperado, conclusão assíncrona pelo ID certo, falha,
cancelamento, timeout, recusa de redirects, proteção da credencial, destino WHM
fixo, validação dos dois níveis da resposta, recusa de operações/parâmetros fora
da lista permitida e diagnóstico sem escrita. Aprovação
destes testes não substitui a validação HTTPS real depois de ativar os tokens.

## Documentação oficial consultada

- [Autenticação por token cPanel](https://docs.cpanel.net/knowledge-base/security/how-to-use-cpanel-api-tokens/)
- [Encaminhamento WHM para cPanel API e UAPI](https://api.docs.cpanel.net/whm/use-whm-api-to-call-cpanel-api-and-uapi)
- [Contrato de uapi_cpanel](https://api.docs.cpanel.net/specifications/whm.openapi/api-execution/cpanel-uapi_cpanel)
- [Autenticação por token WHM](https://api.docs.cpanel.net/guides/guide-to-api-authentication/guide-to-api-authentication-api-tokens-in-whm)
- [ACLs das funções WHM](https://api.docs.cpanel.net/guides/guide-to-whm-plugins/guide-to-whm-plugins-acl-reference-chart)
- [Criação e alcance do token WHM](https://api.docs.cpanel.net/specifications/whm.openapi/api-token-management/tokens-api_token_create)
- [Gestão de tokens e restrições de privilégios](https://docs.cpanel.net/whm/development/manage-api-tokens-in-whm/)
- [Fileman/upload_files e incompatibilidade com uapi_cpanel](https://api.docs.cpanel.net/specifications/cpanel.openapi/manage-files/fileman-upload_files)
- [Cron/add_line e ausência de equivalente UAPI](https://api.docs.cpanel.net/cpanel-api-2/cpanel-api-2-modules-cron/cpanel-api-2-functions-cron-add_line)
- [Substituição dos privilégios MySQL](https://api.docs.cpanel.net/specifications/cpanel.openapi/user-management/mysql-set_privileges_on_database)
- [Início de backup da conta](https://api.docs.cpanel.net/specifications/cpanel.openapi/backup/backup-fullbackup_to_homedir)
- [Seleção de versão PHP instalada](https://api.docs.cpanel.net/specifications/cpanel.openapi/php-settings/langphp-php_set_vhost_versions)
- [Início da verificação AutoSSL](https://api.docs.cpanel.net/specifications/cpanel.openapi/auto-generated-ssl-certificates/ssl-start_autossl_check)
- [Consulta de quotas](https://api.docs.cpanel.net/specifications/cpanel.openapi/disk-quotas/quota-get_quota_info)
- [VersionControl/retrieve](https://api.docs.cpanel.net/specifications/cpanel.openapi/repository-management/versioncontrol-retrieve)
- [VersionControl/update](https://api.docs.cpanel.net/specifications/cpanel.openapi/repository-management/versioncontrol-update)
- [VersionControlDeployment/create](https://api.docs.cpanel.net/specifications/cpanel.openapi/deployment-settings/versioncontroldeployment-create)
- [VersionControlDeployment/retrieve](https://api.docs.cpanel.net/specifications/cpanel.openapi/deployment-settings/versioncontroldeployment-retrieve)
- [Requisitos de deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/)
