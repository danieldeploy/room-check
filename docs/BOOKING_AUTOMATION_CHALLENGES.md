# Booking: permissões locais e teste CAPTCHA

1. **Comportamento:** negar `loopback-network` nos três domínios Booking usados
   pelo agente, antes de navegar. Opção ativa por defeito. Acrescentar um adaptador
   reutilizável Anti-Captcha/AWS WAF, desativado por defeito, disponível apenas no
   teste manual de acesso (`login`). Recolhas agendadas não criam tarefas pagas.
2. **Utilizadores:** apenas Gerente altera as opções, por HTTPS, com CSRF e os
   bloqueios de manutenção existentes. Não altera credenciais, sessões, validação
   de acesso ou agendamento ao guardar estas opções.
3. **Dados:** chave do fornecedor num ficheiro cifrado separado no cofre. O agente
   recebe-a apenas no teste de acesso. O fornecedor recebe o domínio público e
   os parâmetros temporários do desafio AWS (`key`, `iv`, `context`, scripts AWS).
   Nunca recebe password, OTP, cookies existentes, URL de login com tokens ou
   faturas. Segredos e soluções permanecem fora de diagnósticos/recibos.
4. **Falhas:** no máximo uma criação paga por execução; polling limitado a 120 s;
   sem repetição de criação após timeout. Só aplica uma solução se a página e o
   desafio continuarem iguais. Confirma que o desafio desapareceu; isso, por si
   só, não valida login. Falhas mantêm `human_verification` e a aba persistente.
   Formatos desconhecidos, incluindo widgets sem `gokuProps`, não são enviados.
   Se o portal reiniciar o login depois de consumir um SMS, termina em `needs_auth`
   para obter um novo código num novo teste; nunca reutiliza o código consumido.
5. **Aceitação:** testes determinísticos de API, timeout, resposta inválida,
   orçamento, proteção de dados e cofre; Chrome real Windows com páginas
   sintéticas para verificar a permissão e a continuação do login. CI obrigatória
   e publicação do commit validado. Teste pago real depende de chave com saldo;
   sucesso sintético não comprova aceitação no Booking.

## Operação

Portais e contas → Booking → Opções de automação. O campo da chave nunca devolve
o valor guardado; vazio preserva-o e existe uma opção de remoção. Ativar o teste,
guardar e usar «Testar acesso à conta». Cada clique pode criar uma tarefa paga.
Desativar o teste impede novas chamadas; não reembolsa uma tarefa já criada.
O bloqueio de permissão é reaplicado pelo agente; não requer manter abas abertas.
Não altera flags de segurança, perfis, proxies nem a identidade do navegador.

O primeiro adaptador usa a variante AWS WAF `gokuProps`. O contrato separa
extração, fornecedor e aplicação/verificação, permitindo adicionar variantes
documentadas sem alterar o fluxo de autenticação. Não há promessa de que o
desafio atualmente apresentado pelo Booking exponha esta variante.

Referências técnicas: [Anti-Captcha AmazonTaskProxyless](https://anti-captcha.com/apidoc/task-types/AmazonTaskProxyless),
[createTask](https://anti-captcha.com/apidoc/methods/createTask),
[getTaskResult](https://anti-captcha.com/apidoc/methods/getTaskResult),
[Chrome 145: separação das permissões locais](https://developer.chrome.com/release-notes/145),
[CDP Browser.setPermission](https://chromedevtools.github.io/devtools-protocol/tot/Browser/#method-setPermission).
