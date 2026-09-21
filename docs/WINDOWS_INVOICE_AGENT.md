# Ligação Windows → Management Hub

Esta alteração permite executar o Chrome no computador Windows 11 e manter a fila,
as permissões, o cofre, as faturas e o histórico no Hub. Não instala nem altera o
ZKTeco/Cloudbeds. O computador inicia todas as ligações HTTPS; não necessita de
portas abertas no router, IP fixo, acesso remoto à base de dados ou password do cPanel.

## Antes da instalação

- Publicar esta branch apenas depois de autorização explícita e backup privado.
  A base correta continua a ser `agent/room-item-assignments`, não `main`.
- Manter as migrações existentes 026–029 instaladas. Esta alteração não requer uma
  migração nova nem altera os documentos já guardados.
- Confirmar certificado HTTPS válido em `check.welcomehostel.pt`, PHP 8.1+, PDO MySQL,
  OpenSSL, pasta privada e chave do cofre existentes. O endpoint novo é
  `https://check.welcomehostel.pt/invoice-agent.php`.
- O cron privado `cron/invoices.php` continua necessário: em modo Windows mantém
  recuperação de tarefas, arquivo Drive e notificações. Não arranca Chrome no cPanel.
- No computador, verificar Windows 11 x64, RAM livre, disco, ligação à Internet,
  suspensão e comportamento do ZKTeco durante o teste. O instalador não altera energia,
  Windows Update, firewall ou tarefas existentes. Não desativar o sandbox do Chrome.
- Instalar Node.js LTS suportado, pelo menos 22.12, a partir de https://nodejs.org/.
  Usar a mesma conta Windows para instalar e executar o agente.

## Instalar e testar

1. Depois de publicar, abrir **Faturas → Definições → Computador de automação →
   Ligação e chave do computador**. Criar a chave. A recolha fica **em pausa**.
   Guardar a chave diretamente no instalador; nunca no chat, num URL ou no Git.
2. Descarregar o código da branch/revisão aprovada no Windows e extrair o ZIP.
   Se o Windows marcar o ZIP como proveniente da Internet, verificar a origem e usar
   **Propriedades → Desbloquear** nesse ZIP antes de extrair. Não desativar políticas
   de execução globalmente. Se uma política da organização bloquear o script, pedir
   ao administrador que o aprove.
3. Em PowerShell, na pasta extraída, executar:

   ```powershell
   .\invoice-runner\windows\Install.ps1
   ```

   O script instala dependências fixadas pelo lockfile e o Chrome para esta conta;
   pede a chave num campo protegido; guarda-a com DPAPI e permissões privadas; testa
   HTTPS autenticado e o arranque/renderização do Chrome com sandbox.
   A instalação fica em `%LOCALAPPDATA%\ManagementHub\Invoices`.
4. Se o teste passar, executar o script instalado para criar o arranque automático:

   ```powershell
   & "$env:LOCALAPPDATA\ManagementHub\Invoices\app\windows\Register-Task.ps1"
   ```

   O Windows pede a password da conta Windows, **não o PIN do Windows Hello**.
   Só o Agendador de Tarefas recebe essa password; não é gravada num script ou enviada
   ao Hub. Pode ser necessário executar o registo como administrador, sempre com a
   conta que instalou o agente. A tarefa usa permissões normais, arranca no início do
   Windows mesmo sem sessão interativa e reinicia após uma falha. Não usar S4U, que
   não disponibiliza o mesmo acesso a credenciais protegidas.
5. No Hub, confirmar ligação recente e teste aprovado. Selecionar **Computador Windows**
   e guardar. Os agendamentos mensais de cada conta mantêm os seus valores anteriores;
   rever esses valores antes de ativar. Tarefas já pendentes serão processadas.
6. Testar uma conta e um período conhecido, confirmar fatura, alojamento, data e
   repetição sem duplicados. Os mapas de cada portal e a autenticação/2FA ainda precisam
   de validação real. O teste de Chrome não valida um conector Booking/Airbnb/Hostelworld.
7. Reiniciar o Windows, confirmar ligação no Hub e funcionamento do ZKTeco. Só concluir
   a instalação depois deste teste no computador real.

O botão de diagnóstico existente no Hub passa a executar no Windows quando este modo
está ativo. Um computador desligado deixa as tarefas na fila; uma tarefa interrompida
recupera pelo orçamento de tentativas já existente, após expirar a reserva de 20 minutos.

## Protocolo e preservação

- POST JSON por TLS verificado; chave aleatória de 256 bits em `Authorization: Bearer`.
  O Hub guarda só o hash da chave, dentro do cofre. O Windows protege a chave com
  DPAPI `CurrentUser`. Tokens e credenciais não aparecem em argumentos, URLs ou logs.
- O agente recusa HTTP, redirecionamentos, certificados inválidos e execução com
  `NODE_TLS_REJECT_UNAUTHORIZED=0`. O cPanel deve encaminhar Authorization ao PHP;
  `.htaccess` inclui essa regra. Não criar exceções gerais de firewall/ModSecurity.
- Um executor e uma tarefa de cada vez. Todos os pedidos e alterações administrativas
  partilham `GET_LOCK('room_check_invoices',0)`. A reserva cifrada identifica tarefa,
  tentativa, processo e prazo fixo. Pedidos repetidos não criam novas reservas;
  respostas antigas são rejeitadas. O agente não executa comandos enviados pelo servidor.
- Documentos enviados em partes de 192 KiB, com limite conjunto de 20 MiB/100 documentos,
  hash e validação final existentes. Uma parte repetida tem de conter os mesmos bytes.
  A confirmação é repetível; uma resposta perdida não duplica o documento nem a contagem.
- Perfil Chrome temporário e pasta privada separados do ZKTeco. Credenciais e sessões
  via stdin; mapas via JSON; Chrome sempre com sandbox. Pastas de intercâmbio são privadas.
- O encaminhamento SMS existente continua no Hub e é ligado à tarefa reservada. Uma
  resposta OTP permanece cifrada até confirmação da receção. CAPTCHA ou um desafio
  inesperado continuam a exigir intervenção.
- As permissões atuais permanecem: só Gerente emparelha, escolhe executor ou revoga;
  consulta/recolha seguem a matriz existente. Textos novos disponíveis em PT/EN.

## Suspender, atualizar e recuperar

Para manutenção normal, aguardar a tarefa atual e selecionar **Recolha em pausa**.
Depois parar apenas **ManagementHub Invoices** no Agendador. Não parar processos
Chrome/Python/ZKTeco indiscriminadamente. A revogação da chave é imediata e deixa a
recolha em pausa; mantém a reserva até ao prazo terminar para impedir duas execuções.

Atualizações são deliberadas: parar a tarefa, conservar `data` e a instalação anterior,
substituir apenas `app` pelo `invoice-runner` da revisão aprovada, executar `npm ci`
e `npm run install-chrome` nessa pasta, executar `Run-Agent.ps1 -TestOnly`, iniciar a
tarefa e reativar no Hub. Não substituir o cofre/chave do servidor. Não atualizar
dependências sem lockfile nem reiniciar o computador enquanto o ZKTeco está ocupado.

Para repetir um teste:

```powershell
& "$env:LOCALAPPDATA\ManagementHub\Invoices\app\windows\Run-Agent.ps1" -TestOnly
```

Parar primeiro a tarefa agendada: o mutex evita duas instâncias. Se o emparelhamento
falhar durante a primeira instalação, guardar a mensagem do teste, corrigir a ligação
e repetir o teste; não gerar outra chave desnecessariamente. Para renovar a chave,
parar o agente, renovar no Hub e executar `Pair-Agent.ps1` na instalação existente.

Uma falha do sistema pode deixar perfis `.browser-*` ou `exchange-*` na pasta privada.
Só os remover com a tarefa parada e sem processo do agente em execução. Não os enviar
em diagnósticos nem incluir em backups públicos. A chave DPAPI só é recuperável na
mesma conta Windows; uma troca de conta exige novo emparelhamento.

Rollback: primeiro pausar/revogar, parar a tarefa Windows e aguardar a reserva expirar.
Só depois voltar ao modo local ou ao código antigo; o código antigo não conhece as
reservas remotas. No shared hosting atual, o modo local continuará bloqueado pelas
dependências do Chrome. Preservar todas as faturas, tabelas e chaves.

## Verificação automatizada e limite de validação

Testes PHP/SQLite e MySQL: autenticação/revogação, ativação condicionada, reserva única,
repetição de pedidos, importação com checksum, conservação do documento, expiração,
troca de executor bloqueada durante execução e entrega/confirmação SMS. Testes Node:
HTTPS obrigatório, redirecionamentos recusados, limites e repetição do mesmo pedido.
CI Windows: sintaxe PowerShell, DPAPI, ACL privada e arranque real do Chrome com sandbox.

Estes testes não confirmam a configuração em produção, a capacidade do computador do
utilizador, o seu reinício automático, a entrega SMS real ou os conectores dos portais.
Não declarar a instalação operacional antes de completar os passos 5–7.

Validação local desta preparação (21/09/2026): 20 testes Node aprovados; 44 verificações
do agente em PHP/SQLite, 35 do executor existente e 32 do espaço de faturas aprovadas;
vistas PT/EN, arquivo Drive, permissões e tradução verificados. A sintaxe dos 104 ficheiros
PHP foi analisada. Runtime local: PHP 8.5.10 e Node 24.19.0. A suite existente emite um
aviso de depreciação de ReflectionMethod::setAccessible() em PHP 8.5; não é uma falha
do agente. Os testes CI em PHP 8.2/MySQL e Windows estão preparados, mas **ainda não
foram executados**: a publicação no GitHub público ficou bloqueada pela revisão
automática até autorização explícita. Nenhuma configuração de produção foi alterada.

Referências: https://pptr.dev/troubleshooting,
https://learn.microsoft.com/en-us/dotnet/standard/security/how-to-use-data-protection,
https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/register-scheduledtask.
