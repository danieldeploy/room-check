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

1. Descarregar a revisão aprovada, verificar a origem e executar no Windows:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy RemoteSigned -File .\invoice-runner\windows\Install.ps1 -PrepareOnly
   ```

   O modo de preparação instala dependências fixadas e testa o Chrome com sandbox,
   sem credenciais, tarefa agendada ou recolha. RemoteSigned aplica-se apenas ao
   processo; políticas da organização continuam a ter precedência. No Toshiba do
   projeto, validar o Windows 10 real, além dos testes de CI em Windows.
2. Na instalação em `%LOCALAPPDATA%\ManagementHub\Invoices`, executar
   `app\windows\Pair-Encrypted.ps1 -Prepare` com a mesma opção de processo.
   Copiar apenas o pedido público apresentado. A chave privada fica protegida por
   DPAPI CurrentUser na pasta privada do agente; não é enviada ao Hub nem ao chat.
3. No Hub, abrir **Faturas → Definições → Computador de automação → Ligação e chave
   do computador**. Colar o pedido público e criar a chave. Esta ação mantém a recolha
   em pausa. O Hub apresenta apenas uma resposta cifrada RSA-OAEP, destinada ao PC.
4. Guardar a resposta cifrada num ficheiro JSON e executar no PC:

   ```powershell
   powershell.exe -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File "$env:LOCALAPPDATA\ManagementHub\Invoices\app\windows\Pair-Encrypted.ps1" -PackageFile <resposta.json>
   ```

   O pedido expira após 24 horas. A importação confirma o destinatário, decifra a
   chave apenas no processo local e guarda a configuração com DPAPI. Elimina a chave
   privada temporária após importar. O endpoint HTTPS é o escolhido localmente na
   preparação, não pode ser substituído pela resposta recebida. Uma resposta inválida
   não substitui a configuração do agente.
5. Executar `app\windows\Run-Agent.ps1 -TestOnly` com RemoteSigned no processo.
   Só depois de testar HTTPS autenticado e Chrome, registar `Register-Task.ps1`.
   O modo padrão pede a password Windows ao Agendador para arrancar sem sessão.
   Num PC com sessão automática já configurada, usar `Register-Task.ps1 -AtLogon`:
   não pede password e não altera o login Windows. Depende de existir sessão após
   reinício. A tarefa usa permissões normais e reinicia após falha.
6. No Hub, confirmar ligação recente e teste aprovado, escolher **Computador Windows**
   e guardar. Rever tarefas pendentes antes de ativar: poderão ser executadas. Os
   agendamentos mensais existentes mantêm os seus valores.
7. Testar uma conta e um período conhecido: acesso/2FA, alojamento, documento, data,
   arquivo e repetição sem duplicados. O teste de Chrome não valida o conector Booking.
   Reiniciar o PC apenas quando autorizado e confirmar o arranque automático e ZKTeco.

O instalador original com entrada manual protegida e `Pair-Agent.ps1` continuam
compatíveis para instalações locais existentes. O Hub deixa de apresentar chaves
novas em texto simples. Não guardar chaves, cookies ou credenciais no Git nem no chat.

Um PC desligado mantém tarefas na fila. Uma execução interrompida recupera depois
 de expirar a reserva de 20 minutos, respeitando o orçamento de tentativas existente.

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
do agente. Os resultados atuais do CI em PHP 8.2/MySQL e Windows ficam registados no
[pedido de revisão 72](https://github.com/danieldeploy/room-check/pull/72), publicado
após autorização do proprietário. A publicação do código não ativa a instalação:
continua a ser necessária a validação no computador real e no alojamento.

Referências: https://pptr.dev/troubleshooting,
https://learn.microsoft.com/en-us/dotnet/standard/security/how-to-use-data-protection,
https://learn.microsoft.com/en-us/powershell/module/scheduledtasks/register-scheduledtask.
