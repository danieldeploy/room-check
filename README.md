# Room Check

Aplicação PHP/MySQL para registar a verificação dos quartos do City Center Guest House e Welcome Guest House.

## Requisitos

- PHP 8.1 ou superior, com PDO MySQL e mbstring
- MySQL 5.7+ ou MariaDB 10.3+
- Apache com `.htaccess`

## Instalação no cPanel

1. Em **MySQL Databases**, crie uma base de dados e um utilizador.
2. Associe o utilizador à base de dados com **ALL PRIVILEGES**.
3. No **phpMyAdmin**, selecione a base de dados e importe `database.sql`.
4. Em `$HOME/public_html/check`, copie `config.local.example.php` para `config.local.php`.
5. Edite `config.local.php` e indique o nome da base de dados, utilizador e password.
6. Confirme que o subdomínio `check.welcomehostel.pt` usa `/public_html/check` como Document Root.
7. Ative **Force HTTPS Redirect** quando o certificado SSL estiver emitido.

`config.local.php` contém credenciais e está excluído do Git.

## Deployment pelo cPanel

O ficheiro `.cpanel.yml` publica os ficheiros do repositório em:

`$HOME/public_html/check`

No **Git Version Control**, abra o repositório e clique em **Update from Remote** e depois **Deploy HEAD Commit**.

## Dados

Cada combinação de alojamento e quarto mantém separadamente:

- problema identificado para cada item;
- estado `Wrong`, `Ok` ou sem seleção;
- data da última atualização.

O City Center Guest House disponibiliza os quartos 1–6. O Welcome Guest House disponibiliza os quartos 1–15.

## Painel My2N (branch de desenvolvimento)

O painel My2N é integrado sem reconstruir nem alterar as regras da gestão de quartos. `index.php` e `api.php` apenas recebem autenticação/autorização; a lista, os quartos, os estados e o modo de gravação permanecem iguais.

### Estado da implementação

- consulta read-only de configurações `MOBILE_VIDEO`;
- consulta dos membros atuais do grupo da campainha;
- apresentação de `REGISTERED` / `NOT_REGISTERED`;
- remoção defensiva de `sipPassword`, tokens e passwords;
- modo dry-run por defeito;
- nenhuma operação `PUT` exposta na interface read-only;
- estrutura de snapshots, auditoria e agendamentos criada mas ainda inativa.

### Autorização My2N

O painel e a API read-only exigem a permissão `my2n.view`. As futuras operações de alteração manual, agendamento e rollback têm permissões próprias e ficam reservadas ao Gerente. A existência dessas permissões não ativa escritas My2N.

Sem sessão, `/admin/my2n.php` redireciona para o login e `/admin/api/my2n-status.php` devolve 401. Um utilizador autenticado sem `my2n.view` recebe 403.

### Segredos My2N

Nunca coloque credenciais My2N em `config.local.php`, no Git ou em `public_html`.

Crie, por exemplo, `$HOME/room-check-private/my2n-secrets.json` com permissões `0600`:

```json
{
  "identifier": "IDENTIFICADOR_MY2N",
  "password": "PASSWORD_MY2N"
}
```

Configure externamente:

```text
MY2N_SECRETS_FILE=/home/CPANEL_USER/room-check-private/my2n-secrets.json
```

As escritas permanecem desativadas enquanto `MY2N_ALLOW_WRITES` não for exatamente `1`. Ativar essa variável, por si só, não expõe botões de escrita na fase read-only.

### Base de dados

Para atualizar uma instalação existente, importe `migrations/002_my2n.sql` pelo phpMyAdmin. As novas tabelas não alteram `room_checklist_values`.

### Testes

```bash
php tests/run.php
```

Os testes usam dados sanitizados e não fazem chamadas de rede nem operações PUT.

## Autenticação e perfis

A aplicação possui quatro perfis fixos:

- `gerente` — Gerente;
- `governanta` — Governanta;
- `tecnico_manutencao` — Técnico Manutenção;
- `empregada_andares` — Empregada de Andares.

As permissões são definidas centralmente em `Auth::ROLE_PERMISSIONS` e verificadas no servidor, incluindo nas APIs. A interface usa a mesma matriz apenas para mostrar os links disponíveis.

| Função | Gerente | Governanta | Técnico Manutenção | Empregada de Andares |
| --- | :---: | :---: | :---: | :---: |
| Consultar gestão de quartos | Sim | Sim | Sim | Sim |
| Alterar gestão de quartos | Sim | Sim | Sim | Sim |
| Consultar estado My2N | Sim | Sim | Sim | Não |
| Gerir utilizadores | Sim | Não | Não | Não |
| Alterar destinatários My2N (futuro) | Sim | Não | Não | Não |
| Agendar modos My2N (futuro) | Sim | Não | Não | Não |
| Executar rollback My2N (futuro) | Sim | Não | Não | Não |
| Consultar auditoria (futuro) | Sim | Não | Não | Não |

As funções My2N marcadas como futuras já têm autorização definida, mas continuam sem interface ou endpoint de escrita e com `MY2N_ALLOW_WRITES` desativado por defeito.

### Ativação numa instalação existente

1. Importe `migrations/003_auth.sql` no phpMyAdmin.
2. Em `config.local.php`, defina uma chave de instalação longa e aleatória em `auth.setup_key`.
3. Faça o deployment da branch aprovada.
4. Abra `/setup.php` e crie a primeira conta Gerente.
5. Remova imediatamente `auth.setup_key` de `config.local.php` depois da criação.
6. Use `/login.php` para entrar e `/admin/users.php` para criar as restantes contas.

Não existe registo público. A página `setup.php` devolve 410 depois de existir o primeiro utilizador.

### Proteções

- passwords com `password_hash()` e atualização automática de hash;
- sessão regenerada no login, cookie `HttpOnly`, `SameSite=Strict` e `Secure` em HTTPS;
- expiração por inatividade;
- CSRF em login, logout, instalação e gestão de contas;
- bloqueio após cinco falhas durante quinze minutos por combinação de utilizador/IP anonimizada;
- auditoria de login e alterações de contas;
- contas desativadas em vez de eliminadas.
