# Diagnóstico transversal de mapas de portais

O módulo `invoice-runner/map-diagnostics.mjs` recebe uma página Puppeteer já aberta
num portal autorizado e produz pistas estruturais para um futuro mapa. O mesmo
código suporta Booking, Hostelworld, Expedia, Airbnb e Hostelsclub através da lista
de domínios permitidos em `portal.mjs`.

`inspectPortalPage(page, portal)` devolve `{ version, portal, validated: false,
location, hints }`. Não guarda passwords, campos preenchidos, códigos 2FA,
cookies, texto de faturas, respostas HTTP, capturas de ecrã ou parâmetros de URL
além do `hotel_id` numérico do Booking. O resultado é apenas uma lista de
candidatos; não é um mapa executável. Nunca atribuir `validated: true`
automaticamente nem enviar um diagnóstico real para GitHub ou logs.

## Execução pelo agente Windows

O Gerente pode pedir **Observar portal e preparar mapa** em Portais e contas.
A tarefa usa o agente Windows já emparelhado e as credenciais guardadas no cofre.
Para Booking, começa em `https://admin.booking.com/`, aceita apenas destinos
Booking, identifica um único campo de utilizador/password e um único campo SMS
reconhecível. Se a estrutura for ambígua, pára com erro; não tenta CAPTCHA nem
faz download de documentos. O rascunho fica cifrado no cofre e é mostrado apenas
ao Gerente. Este diagnóstico não marca o acesso como validado nem liga a agenda.

A inspeção estrutural é comum aos portais permitidos, mas cada portal precisa
de um URL de entrada observado ou configurado. Nesta versão, Booking é o único
portal com endereço inicial configurado; os restantes param sem o respetivo
mapa inicial. Não se envia o rascunho para logs, GitHub ou JavaScript público.

O resultado ainda **não é o mapa executável**. Rever os seletores e os
identificadores de conta e alojamento, observar a página de faturas e validar
datas, paginação e PDF real de cada propriedade antes de criar um mapa privado
com `validated: true`. O agente instalado no PC precisa de receber a revisão
do código aprovada; o deploy do Hub não atualiza automaticamente o PC.
