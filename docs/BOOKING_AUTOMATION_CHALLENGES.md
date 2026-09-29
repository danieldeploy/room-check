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
   os parâmetros temporários do desafio AWS (`key`, `iv`, `context`, scripts AWS),
   ou a chave pública do widget e o endereço AWS `jsapi.js`.
   Nunca recebe password, OTP, cookies existentes, URL de login com tokens ou
   faturas. Segredos e soluções permanecem fora de diagnósticos/recibos.
4. **Falhas:** no máximo uma criação paga por execução; polling limitado a 120 s;
   sem repetição de criação após timeout. Só aplica uma solução se a página e o
   desafio continuarem iguais. Confirma que o desafio desapareceu; isso, por si
   só, não valida login. Falhas mantêm `human_verification` e a aba persistente.
   Formatos desconhecidos não são enviados. Widgets usam o contrato público
   `AwsWafCaptcha.renderCaptcha`: captura temporária de `apiKey` e continuação
   pelo `onSuccess` registado pelo portal, sem ler callbacks privados.
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

O adaptador suporta AWS WAF `gokuProps` e `widget`. O contrato separa
extração, fornecedor e aplicação/verificação, permitindo adicionar variantes
documentadas sem alterar o fluxo de autenticação. A captura do proprietário de
29/09/2026 confirmou `AwsWafCaptcha.renderCaptcha` e `jsapi.js` no Booking.
O teste 67 anterior não enviou uma tarefa: a variante widget ainda não existia.

## Variante widget

O observador é instalado apenas nos testes de acesso com fornecedor configurado,
antes da navegação, e preserva a função original de renderização. Uma aba antiga
que já tenha renderizado pode fazer um único GET à mesma página de entrada para
capturar uma renderização nova. Não repete POST nem consome outra tarefa paga.
O observador aguarda até cinco segundos por widgets carregados depois do evento
de carregamento da página, antes de considerar o formato não suportado.
A chave é obtida em memória; não é pedida ao proprietário nem gravada em logs.
O pedido documentado é `AmazonTaskProxyless`, `wafType: widget`, `websiteKey`,
`jsapiScript` e a origem pública em `websiteURL`.

Cada renderização tem uma identidade por documento. Navegação mesmo para o mesmo
URL, nova renderização, sucesso humano, timeout, erro e remoção do widget impedem
a aplicação de soluções antigas. O token é entregue uma vez ao callback público
registado pelo portal; cookies não são substituídos para esta variante. É exigido
o desaparecimento do desafio e, separadamente, o login normal. A limpeza remove
os scripts de observação e restaura o SDK sem desligar o Chrome persistente.
Testes Chrome isolados cobrem carregamento novo/tardio, desafio antes/depois da
password, cancelamento, navegação, limpeza e isolamento dos domínios. Nenhuma
requisição desses testes chega ao Booking ou ao fornecedor.

Referências técnicas: [Anti-Captcha AmazonTaskProxyless](https://anti-captcha.com/apidoc/task-types/AmazonTaskProxyless),
[exemplo oficial Widget em Node.js](https://github.com/anti-captcha/anticaptcha-npm#amazon-waf),
[contrato público AWS renderCaptcha/onSuccess](https://docs.aws.amazon.com/waf/latest/developerguide/waf-js-captcha-api-specification.html),
[createTask](https://anti-captcha.com/apidoc/methods/createTask),
[getTaskResult](https://anti-captcha.com/apidoc/methods/getTaskResult),
[Chrome 145: separação das permissões locais](https://developer.chrome.com/release-notes/145),
[CDP Browser.setPermission](https://chromedevtools.github.io/devtools-protocol/tot/Browser/#method-setPermission).
