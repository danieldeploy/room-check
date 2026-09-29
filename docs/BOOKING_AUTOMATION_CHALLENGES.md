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

### Diagnóstico do teste (29/09/2026)

1. **Comportamento:** distinguir falha local de captura (`browser_error`) de
   recusa do fornecedor; guardar a fase categórica em `captcha_stage`.
2. **Permissões:** mantém o teste manual exclusivo do Gerente e o cofre existente.
3. **Dados:** apenas códigos fixos, confirmados pelo par `errorId`/`errorCode`
   documentado. Nunca guardar descrições livres do fornecedor ou valores pedidos.
4. **Falhas:** desconhecidas continuam `provider_error`; sem repetição automática.
   `create_task` indica uma tentativa de pedido, sem provar cobrança ou criação;
   `poll_task` indica que o fornecedor devolveu um identificador válido de tarefa.
5. **Aceitação:** testar recusa na criação e no polling, erro local sem pedido,
   saneamento de códigos desconhecidos e recibos; publicar pelo CI antes do teste real.

Referência: [erros oficiais Anti-Captcha](https://anti-captcha.com/apidoc/errors).

O teste 69 recebeu uma solução mas terminou `stale` na validação, antes de pedir
SMS. O diagnóstico de ciclo de vida acrescenta cinco garantias:
1. **Comportamento:** indicar a causa categórica de invalidação do widget.
2. **Permissões:** manter o teste manual e os mesmos limites de execução.
3. **Dados:** callbacks públicos AWS, presença/visibilidade e identidade do widget;
   apenas motivos fixos nos diagnósticos, sem identificadores ou dados do desafio.
4. **Falhas:** continuar a rejeitar soluções após navegação, expiração, erro,
   conclusão humana ou substituição; não repetir automaticamente tarefas pagas.
5. **Aceitação:** distinguir essas causas em Chrome isolado e sanear os recibos.

### Configuração

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
URL, nova renderização, sucesso humano, erro e remoção do widget impedem
a aplicação de soluções antigas. A expiração do puzzle local tem o tratamento
separado descrito abaixo. O token é entregue uma vez ao callback público
registado pelo portal, depois de guardar `aws-waf-token` apenas no host atual,
com `Secure`, `SameSite=Lax` e caminho `/`. Outros cookies são preservados. É exigido
o desaparecimento do desafio e, separadamente, o login normal. A limpeza remove
os scripts de observação e restaura o SDK sem desligar o Chrome persistente.
Testes Chrome isolados cobrem carregamento novo/tardio, desafio antes/depois da
password, cancelamento, navegação, limpeza e isolamento dos domínios. Nenhuma
requisição desses testes chega ao Booking ou ao fornecedor.

## Continuação depois do widget (29/09/2026)

O teste 71 registou `challenge_cleared`, mas o pedido seguinte ao endpoint de
utilizador voltou a receber 405 e a mostrar CAPTCHA. Isto não validou o login.

1. **Comportamento:** guardar o token AWS no cookie documentado antes de executar
   `onSuccess`, como acontece no fluxo normal do SDK. O desaparecimento visual do
   widget não comprova que o servidor aceitou o pedido protegido seguinte.
2. **Permissões:** só no teste manual Booking já autorizado e configurado.
3. **Dados:** token temporário apenas no host Booking onde o widget foi capturado;
   sem domínio pai, sem logs, sem alterações nos outros cookies ou credenciais.
4. **Falhas:** se o cookie não puder ser guardado ou houver valores ambíguos, não
   continuar o formulário. Mantêm-se as verificações de validade e uma tarefa por teste.
5. **Aceitação:** em Chrome isolado, o servidor sintético só avança quando recebe
   o cookie correto no pedido real; validar isolamento de domínio e preservação
   dos cookies existentes. Confirmar separadamente a aceitação real no Booking.

Referência: [AWS: token nos pedidos protegidos](https://docs.aws.amazon.com/waf/latest/developerguide/waf-js-challenge-api-get-token.html).

## Diagnóstico da captura (teste 72)

O teste 72 parou com `browser_error` antes de chamar o fornecedor. A aplicação do
token da versão anterior não chegou a ser exercitada nesse teste.

1. **Comportamento:** identificar se a falha foi na leitura, recarga, espera pelo
   documento, espera pelo widget ou verificação do desafio; classificar timeout,
   contexto destruído, página fechada e navegação abortada.
2. **Permissões:** manter o teste manual Booking já configurado e autorizado.
3. **Dados:** só categorias fixas nos diagnósticos existentes; nunca mensagens,
   stacks, URLs privadas, valores de campos ou objetos de erro.
4. **Falhas:** manter uma tentativa por tarefa, sem nova chamada ao fornecedor
   nem repetição automática do login para recolher o diagnóstico.
5. **Aceitação:** testes simulam cada categoria com dados privados fictícios;
   confirmar classificação correta e zero chamadas ao fornecedor em falhas locais.

## Token recebido depois da expiração do puzzle (teste 73)

1. **Comportamento:** quando a API devolve um token novo após `onPuzzleTimeout`,
   permitir que o servidor o valide num único GET ao mesmo formulário. Não chamar
   o callback do puzzle expirado nem declarar o token aceite antes do login real.
2. **Permissões:** apenas o teste manual Booking configurado, dentro da tentativa
   já autorizada; sem chamadas adicionais ao fornecedor e sem ativar recolhas.
3. **Dados:** manter domínio, URL, documento, identidade e contentor visível do
   widget capturado. Guardar apenas o cookie temporário nesse host; sem logs do token.
4. **Falhas:** se houver navegação, novo widget, sucesso humano, erro, remoção,
   ocultação ou conflito de cookies, parar. Se o GET repetir o desafio, registar
   `not_accepted`. Sem ciclos de repetição ou reenvio de POST com credenciais.
5. **Aceitação:** testes Chrome com rede interceptada verificam receção do cookie
   no GET, aceitação e rejeição pelo servidor sintético, zero callbacks expirados
   e rejeição de contextos substituídos. A validação de produção exige CAPTCHA,
   login, SMS e recolha real; os testes sintéticos não substituem esse ciclo.

O evento AWS `onPuzzleTimeout` descreve a expiração do puzzle apresentado. Não é
um relatório de validação do token independente devolvido pelo fornecedor. Este
fluxo é experimental e mantém a aceitação do Booking como critério decisivo.

## Timeout de navegação com documento carregado (teste 74)

1. **Comportamento:** se o GET de captura indicar timeout mas o mesmo URL já
   apresentar `document.readyState=complete`, continuar a inspeção desse documento.
2. **Permissões:** manter o teste Booking e as credenciais configuradas.
3. **Dados:** comparar URL apenas em memória; não persistir mensagens de erro,
   tokens ou valores do formulário.
4. **Falhas:** URL diferente, documento incompleto e outros erros continuam a
   interromper. Não fazer um segundo GET nem criar mais tarefas do fornecedor.
5. **Aceitação:** testar os estados completo, incompleto e navegação diferente;
   reproduzir em Chrome um timeout após o GET real e comprovar uma única recarga.

Referências técnicas: [Anti-Captcha AmazonTaskProxyless](https://anti-captcha.com/apidoc/task-types/AmazonTaskProxyless),
[exemplo oficial Widget em Node.js](https://github.com/anti-captcha/anticaptcha-npm#amazon-waf),
[contrato público AWS renderCaptcha/onSuccess](https://docs.aws.amazon.com/waf/latest/developerguide/waf-js-captcha-api-specification.html),
[createTask](https://anti-captcha.com/apidoc/methods/createTask),
[getTaskResult](https://anti-captcha.com/apidoc/methods/getTaskResult),
[Chrome 145: separação das permissões locais](https://developer.chrome.com/release-notes/145),
[CDP Browser.setPermission](https://chromedevtools.github.io/devtools-protocol/tot/Browser/#method-setPermission).
