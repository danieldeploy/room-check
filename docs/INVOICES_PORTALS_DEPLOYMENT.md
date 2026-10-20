# Faturas e Portais — implantação e validação

## Estado desta entrega

- Interface PHP/MySQL, permissões, fila, histórico, download autenticado, cofre cifrado e execução Node implementados.
- Primeiro portal: Booking. Welcome Guest House `1140306`; City Center Guest House `539828`.
- Airbnb e envio ao TOConline ficam fora desta fase.
- A recolha considera o **mês de emissão** da fatura; não presume que corresponda ao mês das estadias.
- **O conector ainda não foi validado no Booking real nem no cPanel.** O mapa vem vazio e desativado, de propósito: preencher apenas com seletores/URLs observados na conta autenticada. Não ativar a recolha antes da validação final abaixo.
- O browser de trabalho não respondeu durante a preparação desta entrega. Não foram guardadas credenciais, executados logins Booking nem alterada produção.

## Arquitetura

O Hub grava pedidos numa fila MySQL. Um cron PHP privado reserva um bloqueio MySQL e executa até dois pedidos por invocação. O Node é um processo privado chamado por esse worker: não abre uma porta HTTP nem um endpoint público. É possível sair do Hub durante a execução e voltar para consultar o resultado.

O cPanel recebe o Node em `/home/welcome/room-check-private/invoice-runner` e o cron em `/home/welcome/room-check-private/cron/invoices.php`. Faturas, chave e cofre ficam em `/home/welcome/room-check-private/invoices`, com pasta `0700` e ficheiros `0600`. Credenciais e cookies são cifrados com AES-256-GCM, com contexto por ficheiro. Os PDFs ficam privados, servidos exclusivamente por PHP autenticado; não são cifrados neste desenho. A chave deve integrar uma cópia de segurança privada e protegida, separada do repositório. A cifragem não protege contra comprometimento completo da mesma conta do servidor.

O Chrome usa um perfil temporário privado e remove-o ao terminar. Não há screenshots, HARs nem logs de respostas do portal. Uma paragem forçada do sistema pode deixar uma pasta `.browser-*`; removê-la apenas quando não há worker/browser ativo. Não copiar esses perfis para backups públicos.

## Permissões e preservação

- `invoices.view`: ver faturas, histórico e descarregar PDFs de ambas as casas.
- `invoices.run`: iniciar recolhas; inclui consulta.
- Ambas obrigatórias para Gerente, sem concessão automática a outros perfis.
- Atribuição segue a matriz existente por perfil. Não cria permissões individuais.
- Configuração, credenciais, diagnóstico e teste de login verificam também o papel `gerente` no servidor; não são permissões delegáveis.
- Não altera tabelas de quartos, PINs, My2N, WhatsApp ou traduções existentes. Adiciona textos PT/EN ao catálogo comum.

## Preparação no servidor

1. Confirmar a branch realmente usada no cPanel e o commit instalado. A base desta entrega é `agent/room-item-assignments`, commit `e1b0813ec41c7b8664b36b14f2702a6ddd5a1cf4`; `main` contém uma versão inicial. Não trocar o cPanel para `main`.
2. Fazer cópia de segurança privada da aplicação e da base de dados. Importar `migrations/026_invoices_portals.sql` no phpMyAdmin da base correta. É aditiva e pode ser repetida.
3. Publicar o código apenas após autorização. `.cpanel.yml` copia o código Node para a pasta privada. Não importa o SQL, instala dependências, cria a chave nem ativa o cron automaticamente.
4. No Terminal cPanel, a partir da cópia do repositório, executar com o PHP CLI da conta:

   ```sh
   php deploy/setup_invoices.php /home/welcome/room-check-private/invoices
   ```

   Este comando preserva uma chave existente. Não substitua nem elimine `master.key` quando existirem dados cifrados.

5. Usar **Node 22.12 ou superior** e instalar na pasta privada:

   ```sh
   cd /home/welcome/room-check-private/invoice-runner
   npm ci
   npx puppeteer browsers install chrome
   cp invoice-runtime.example.json ../invoices/invoice-runtime.json
   cp booking-map.example.json ../invoices/booking-map.json
   chmod 600 ../invoices/invoice-runtime.json ../invoices/booking-map.json
   ```

   O Puppeteer está fixado em `25.11.0` no lockfile. A instalação precisa de acesso à rede e de espaço para Chrome. Se já existir um Chrome compatível, indicar o caminho absoluto em `invoice-runtime.json`. Não adicionar `--no-sandbox`: caso o alojamento não suporte o browser com sandbox, parar e decidir outra arquitetura antes de guardar credenciais.

6. Criar um cron a cada minuto. Exemplo, **substituindo os dois executáveis pelos caminhos confirmados no servidor**:

   ```sh
   INVOICES_NODE_BINARY=/absolute/path/to/node /absolute/path/to/php /home/welcome/room-check-private/cron/invoices.php
   ```

   `INVOICES_PRIVATE_DIR` e `ROOM_CHECK_APP_ROOT` permitem adaptar caminhos. Não colocar credenciais na linha de comando, no cron, em JavaScript nem em `public_html`. O cron apenas executa tarefas pedidas; a recolha mensal permanece desativada até ser ligada pelo Gerente.

7. Abrir **Faturas e Portais → Testar browser no servidor**. Confirmar histórico concluído e heartbeat recente. Só então o formulário de credenciais é desbloqueado por 24 horas. O teste verifica arranque/renderização do Chrome, não prova o login nem a compatibilidade do ecrã Booking.

## Configurar e validar o Booking

O `booking-map.json` é privado e deliberadamente não contém seletores inventados. Inspecionar a conta autenticada e preencher:

- `login.url`: URL HTTPS de entrada Booking.
- `identifier`, `password`, `submit`: seletores CSS dos controlos reais; `next` apenas se houver passagem intermédia após o utilizador.
- `challenge`: seletor que identifique pedido de MFA/CAPTCHA/verificação; `authenticated`: seletor só presente após login.
- Por cada propriedade: URL da página de faturas com `hotel_id` correto, seletor das linhas, número, data, ligação PDF, mensagem explícita de ausência de faturas, e marcador visível que contenha o ID da propriedade selecionada.
- `dateFormat`: `YYYY-MM-DD` ou `DD/MM/YYYY`, de acordo com a conta.
- `nextPage`: seletor do botão da página seguinte, se houver paginação. O limite de segurança é de 20 páginas/100 PDFs e aproximadamente 20 MiB de PDFs por execução. Um limite atingido é uma falha visível, nunca sucesso parcial silencioso.
- Usar a listagem completa de faturas sem um filtro de datas oculto. O conector filtra pelas datas lidas. Se a interface real exigir filtros de período, POST para download, iframe ou fluxo diferente, adaptar e testar o conector antes de marcar `validated: true`.

O adaptador suporta atualmente links PDF HTTPS da **mesma origem** da página de faturas. Rejeita redirecionamentos, origens externas, HTML em lugar de PDF, outra propriedade, estrutura desconhecida e paginação que não avança. Não usar uma ligação de PDF copiada com sessão temporária como configuração estática.

Depois de validar o mapa com ambas as propriedades, marcar `validated: true`. No Hub, guardar as credenciais pelo formulário HTTPS do Gerente, testar o acesso, recolher um mês conhecido e verificar número, data e conteúdo de pelo menos um PDF de **cada** casa. O teste de login não é uma validação de ambas as propriedades; as recolhas de teste são obrigatórias.

MFA/CAPTCHA ou rejeição de login resultam em `needs_auth`; não há tentativa de contornar desafios. A recolha mensal é desativada e os outros pedidos pendentes são interrompidos para evitar repetir logins. Se o portal exigir sempre MFA, este fluxo automático de password não fica operacional sem desenhar e validar um passo de autenticação assistida. Não guardar códigos OTP nem pedir passwords em mensagens.

## Agenda, falhas e repetição

- A agenda mensal é configurável (dia 1–28, hora Europe/Lisbon). O valor inicial é dia 5 às 04:00, **desativado**.
- Recolhe faturas emitidas no mês anterior. Um cron atrasado dentro do mês recupera a execução; não faz recuperação automática de meses inteiros em que o cron esteve parado. Usar recolha manual para esses meses.
- Um pedido agendado por mês/propriedade, mesmo após falha; repetir manualmente quando necessário. Um erro não cria um ciclo de tentativas.
- O worker volta a validar o Chrome quando o diagnóstico tem mais de 24 horas.
- Se o processo morrer, o próximo worker marca a execução interrompida; repetir manualmente. PDFs já importados permanecem identificados e não serão duplicados.
- Um número de fatura já existente com conteúdo diferente é sinalizado para revisão. Não substitui o documento anterior.
- Se forem substituídas credenciais, limpa-se a sessão cifrada e desativa-se a agenda até novo teste de acesso. A alteração é recusada enquanto o worker estiver ocupado.

## Validação e reversão

Antes de ativar: migração repetida, permissões por URL direta/POST/download, gerente obrigatório, teste browser, login, duas propriedades, PDF real, repetição sem duplicação, saída/regresso, erro de autenticação, agendamento e hora de verão/inverno. A suite automatizada cobre regras e fixtures; não substitui estes testes no Booking real.

Para suspender, desativar a agenda no Hub e o cron. Reverter o código ao commit anterior se necessário, mantendo intactas as três tabelas e a pasta privada para preservar documentos/histórico. Restaurar código antigo não exige apagar dados nem credenciais.

Referência de instalação: https://pptr.dev/guides/installation
