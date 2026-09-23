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

## Integração de execução

A chamada deve ocorrer no computador Windows, dentro da pasta privada do agente,
depois de uma autenticação autorizada pelo Hub e antes de importar documentos.
O resultado tem de ficar numa pasta privada e de ser revisto por um Gerente ou
por um diagnóstico automatizado que comprove a conta, o alojamento, a emissão,
a paginação, a transferência e os estados vazios. Cada portal pode exigir
navegação própria, mantendo a captura e os limites comuns.

Esta alteração cria **o núcleo de inspeção e as suas regras permanentes**.
Ainda não liga um comando remoto de diagnóstico ao Hub, não automatiza a descoberta
do login inicial e não cria um mapa Booking validado. O mapa real continua privado
e com `validated: false` até ao teste autenticado de ambas as propriedades.
O agente de recolha existente não pode usar estas pistas como mapa de produção.
