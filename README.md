# Portal de Operações — Active Lines Unip. Lda.

Aplicação PHP/MySQL para `check.welcomehostel.pt`. Depois do login, apresenta apenas os módulos autorizados para o utilizador:

1. configuração da automação Cloudbeds → ZKAccess;
2. gestão dos quartos;
3. configuração/estado da campainha My2N.

Esta branch é de desenvolvimento. O conteúdo só deve ser publicado depois de aprovação explícita do deployment.

## Requisitos

- PHP 8.1+ com PDO MySQL e mbstring;
- MySQL 5.7+ ou MariaDB 10.3+;
- Apache com `.htaccess`;
- para o executor ZKAccess: Python, Playwright/Chromium e sessão Cloudbeds com MFA, todos fora de `public_html`.

## Instalação no cPanel

1. Crie a base de dados e um utilizador com os privilégios necessários.
2. Numa instalação nova, importe `database.sql` no phpMyAdmin.
3. Numa instalação existente, importe por ordem as migrações que ainda faltarem: `002_my2n.sql`, `003_auth.sql`, `004_portal_permissions.sql` e `005_my2n_credentials_permission.sql`.
4. Copie `config.local.example.php` para `config.local.php` em `$HOME/public_html/check` e configure a base de dados.
5. Confirme que `check.welcomehostel.pt` usa `/public_html/check` como Document Root e Force HTTPS Redirect.
6. Defina temporariamente `auth.setup_key`, abra `/setup.php`, crie o primeiro Gerente e remova imediatamente a chave.

`config.local.php` contém configuração local, está excluído do Git e não deve ser partilhado.

O ficheiro `.cpanel.yml` publica em `$HOME/public_html/check`, mas não deve ser executado até existir autorização de deployment.

## Login e portal

- `/login.php` é a entrada pública da aplicação.
- `/index.php` é o portal autenticado.
- `/rooms.php` preserva a gestão de quartos existente.
- `/admin/zkaccess.php` apresenta os parâmetros e o estado da automação ZKAccess.
- `/admin/my2n.php` configura a ligação e as associações entre todas as campainhas e telemóveis do Site My2N.
- `/admin/users.php` gere contas e perfis.
- `/admin/permissions.php` configura a matriz de permissões.

Não existe registo público. `setup.php` devolve HTTP 410 depois de existir o primeiro utilizador.

## Quatro perfis e permissões

Os perfis são fixos; as permissões são configuráveis na base de dados e verificadas no servidor, incluindo nas APIs:

- Gerente;
- Governanta;
- Técnico de Manutenção;
- Empregada de Andares.

Matriz padrão:

| Função | Gerente | Governanta | Técnico de Manutenção | Empregada de Andares |
| --- | :---: | :---: | :---: | :---: |
| Consultar quartos | Sim | Sim | Sim | Sim |
| Alterar quartos | Sim | Sim | Sim | Sim |
| Consultar automação ZKAccess | Sim | Não | Sim | Não |
| Configurar automação ZKAccess | Sim | Não | Não | Não |
| Consultar My2N | Sim | Sim | Sim | Não |
| Gerir login My2N | Sim (obrigatória) | Não | Não | Não |
| Alterar/agendar/rollback My2N | Sim | Não | Não | Não |
| Gerir utilizadores | Sim | Não | Não | Não |
| Gerir permissões | Sim | Não | Não | Não |
| Consultar auditoria | Sim | Não | Não | Não |

O acesso do Gerente a `users.manage`, `permissions.manage` e `my2n.credentials` é obrigatório, para impedir que todos os administradores fiquem bloqueados. A permissão `my2n.credentials` pode ser atribuída adicionalmente à Governanta ou ao Técnico. Permissões de alteração incluem automaticamente a consulta do respetivo módulo. Se a tabela `role_permissions` ainda não existir, a aplicação usa a matriz padrão em código.

## Gestão dos quartos

Cada combinação de alojamento/quarto mantém o problema, estado (`Problema`, `OK` ou vazio) e data de atualização de cada item. Internamente, a API preserva os valores `wrong` e `ok` para manter compatibilidade com os dados existentes. O City Center Guest House tem quartos 1–6 e o Welcome Guest House 1–15. A API exige `room_check.view` para leitura e `room_check.edit` para gravação.

## Automação ZKAccess V5.1

A página de configuração foi preparada a partir da versão existente **V5.1 Direct POST**:

- lê as chegadas do dia no Cloudbeds;
- obtém o PIN nas notas da reserva;
- autentica no ZKAccess, compara o código e usa Direct POST com fallback visual;
- começa desligada e em dry-run;
- usa o fuso `Europe/Lisbon`.

O portal guarda somente `enabled`, `dry_run`, hora e termo de pesquisa. Utilizadores, passwords, endpoints internos, cookies e sessões nunca são guardados na base de dados do portal nem no Git.

Em `config.local.php`, indique apenas os caminhos privados:

```php
'zkaccess' => [
    'private_config_file' => '/home/CPANEL_USER/room-check-private/zkaccess/config.json',
    'runner_status_file' => '/home/CPANEL_USER/room-check-private/zkaccess/status.json',
],
```

A automação não pode ser ativada pela interface enquanto o primeiro ficheiro não existir, não estiver legível e não estiver fora da raiz pública. O executor Python/Playwright e o seu agendamento privado ainda precisam de ser instalados e validados no servidor antes do modo live.

## Painel My2N

- o Gerente pode validar e guardar o login da conta técnica My2N no próprio painel;
- a permissão `my2n.credentials` pode ser atribuída a outros perfis na matriz;
- mantém apenas um Site My2N configurado e descobre dinamicamente todas as campainhas, apartamentos e configurações `MOBILE_VIDEO` desse Site;
- apresenta uma matriz campainha × telemóvel, permitindo que o mesmo telemóvel atenda uma, várias ou nenhuma das campainhas;
- suporta várias campainhas no mesmo apartamento e destination groups independentes;
- utilizadores com `my2n.control` podem preparar a adição ou remoção de telemóveis em cada campainha;
- antes de qualquer escrita guarda snapshot por campainha, verifica se o respetivo grupo não mudou entretanto, executa o `PUT`, relê e confirma o resultado;
- nunca permite guardar um destination group vazio;
- remove defensivamente `sipPassword`, tokens e passwords das respostas e auditorias.

As credenciais ficam em `$HOME/room-check-private/my2n-secrets.json` com permissões `0600`. O caminho pode ser indicado por `MY2N_SECRETS_FILE`; quando `HOME` está disponível, esse caminho privado é usado por defeito. Nunca coloque segredos My2N no Git, JavaScript, `config.local.php` ou `public_html`.

As alterações remotas permanecem bloqueadas enquanto `MY2N_ALLOW_WRITES` não for exatamente `1`. Esta variável só deve ser ativada durante o primeiro teste acompanhado.

## Proteções

- `password_hash()` e atualização automática do hash;
- regeneração de sessão no login, cookie `HttpOnly`, `SameSite=Strict` e `Secure` em HTTPS;
- expiração por inatividade;
- CSRF no login, logout, instalação e ações administrativas;
- bloqueio após cinco falhas em quinze minutos por combinação anonimizada de utilizador/IP;
- auditoria de login, contas, permissões e configuração ZKAccess;
- contas desativadas em vez de eliminadas;
- segredos excluídos das respostas, da auditoria e do repositório.

## Testes

```bash
php tests/run.php
```

Os testes usam dados sanitizados, não fazem chamadas de rede e não executam alterações My2N ou ZKAccess.
