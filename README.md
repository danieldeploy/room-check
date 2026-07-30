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
4. Em `/home/welcome/public_html/check`, copie `config.local.example.php` para `config.local.php`.
5. Edite `config.local.php` e indique o nome da base de dados, utilizador e password.
6. Confirme que o subdomínio `check.welcomehostel.pt` usa `/public_html/check` como Document Root.
7. Ative **Force HTTPS Redirect** quando o certificado SSL estiver emitido.

`config.local.php` contém credenciais e está excluído do Git.

## Deployment pelo cPanel

O ficheiro `.cpanel.yml` publica os ficheiros do repositório em:

`/home/welcome/public_html/check`

No **Git Version Control**, abra o repositório e clique em **Update from Remote** e depois **Deploy HEAD Commit**.

## Dados

Cada combinação de alojamento e quarto mantém separadamente:

- problema identificado para cada item;
- estado `Wrong`, `Ok` ou sem seleção;
- data da última atualização.

O City Center Guest House disponibiliza os quartos 1–6. O Welcome Guest House disponibiliza os quartos 1–15.
