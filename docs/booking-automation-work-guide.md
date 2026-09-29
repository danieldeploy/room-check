# Guia de trabalho — recolha automática do Booking

Autorizado por Daniel em 29/09/2026. Atualizar este guia em cada entrega, com
evidência separada de implementação, publicação e teste real.

## Objetivo e regras

- Recolher sem intervenção habitual, mesmo quando o Chrome dedicado foi fechado.
- Reutilizar o perfil persistente, o cofre cifrado do Hub e o canal SMS existente.
- Manter permissões de Gerente, validação do mapa, identificação da propriedade,
  paginação, validação dos PDFs e prevenção de duplicados.
- O dia 5 às 04:00 de Lisboa recolhe faturas **emitidas no mês anterior**.
  Em 05/10/2026, o período é setembro de 2026, independentemente do serviço.
- Não guardar credenciais, códigos, cookies, documentos ou URLs privadas neste
  guia, no Git ou nos registos. Não enviar mensagens de teste sem autorização.

## Passos de implementação

| Ordem | Melhoria e comportamento esperado | Critério de aceitação | Estado |
| --- | --- | --- | --- |
| 1 | Antes de cada tarefa, verificar o Chrome dedicado e abri-lo se estiver fechado; preservar o perfil. | Browser aberto reutilizado; fechado reaberto sem reiniciar o agente; repetição nas duas casas sem duplicados. | Implementado; validação e publicação registadas no PR #144 |
| 2 | Reutilizar sessão válida e recuperar sessão expirada com cofre e SMS; identificar o passo de intervenção quando houver desafio. | Testar sessão expirada, escolha do canal, envio e receção SMS, código inválido/expirado e desafio humano, com tentativas limitadas. | Pendente |
| 3 | Monitorizar ligação do agente e recuperação após reinício do Windows. | Estado offline visível; reconexão recupera tarefas pendentes; teste após reinício e entrada na sessão Windows. | Pendente |
| 4 | Recuperar falhas transitórias com espera e limite, preservando a mesma tarefa. | Sem tarefas/documentos duplicados, sem ciclos infinitos e sem bloquear a outra propriedade. | Pendente; já existe uma repetição após 2 minutos |
| 5 | Alertas úteis por WhatsApp, com propriedade, passo e motivo e sem dados sensíveis. | Configuração/ativação confirmada, destinatário verificado, supressão de alertas repetidos e entrega verificada. | Pendente; alertas atualmente desativados |
| 6 | Testar o percurso completo nas duas propriedades e registar resultados. | Chrome aberto/fechado, reinício, sessão expirada e repetição sem duplicados. | Parcial; sessão aberta validada em 29/09 |
| 7 | Estabilizar arquivo no Drive e submissão ao TOConline depois da recolha. | Configuração externa disponível; rastreio por documento, repetição segura e confirmação do destino. | Posterior; Drive não configurado e TOConline desligado |

## Primeira entrega: supervisão do Chrome

- **Comportamento:** cada tarefa Booking da conta 1 com o perfil controlado pede
  ao lançador Windows que prepare o Chrome antes de executar o percurso.
  O lançador abre apenas o perfil fixo da recolha; um Chrome pessoal não o substitui.
- **Utilizadores e permissões:** continua a usar a conta Windows já emparelhada
  e as permissões existentes do Hub. Não altera credenciais nem o agendamento.
- **Entradas e dados:** o canal privado entre agente e lançador contém apenas
  identificador aleatório e estado. Perfil, executável e destino inicial são fixos.
- **Falhas:** preparação com prazo limitado, estado `browser_unavailable` e
  repetição já existente do Hub. Nunca matar um Chrome pessoal nem apagar cookies.
  Falha de uma tarefa não impede o agente de contactar o Hub.
- **Aceitação:** testes automatizados do protocolo e do lançador real em Windows;
  testes de Chrome aberto, encerrado entre tarefas, perfil preservado e processo
  independente do worker; recolha real nas duas propriedades, com repetição.

## Evidência e limites conhecidos

- A evidência da primeira entrega — resultados de CI, commit publicado e pedidos
  reais — fica na descrição do [PR #144](https://github.com/danieldeploy/room-check/pull/144).
  Distinguir sempre código implementado, versão instalada e cenário comprovado.
- Base publicada: `57dbff7bd342fa337fa845804a075700b159ecae`.
- Pedidos reais #6 e #7 de agosto de 2026 concluídos em 29/09: ambas as casas,
  zero novas faturas e duas já existentes por pedido. Validam navegação e deduplicação
  com sessão aberta; não validam o arranque com Chrome fechado.
- Em 29/09, a tarefa Windows está configurada para **entrada na sessão**, com
  principal interativo. Reiniciar o PC sem entrar no Windows não abre este browser.
  Não configurar entrada automática no Windows implicitamente.
- A resolução de CAPTCHA atualmente configurada aplica-se ao teste de login.
  A recolha identifica o desafio e requer intervenção; esse percurso não está
  validado como recuperação automática.
- Testar reinício real apenas quando não interromper os restantes serviços do PC.
