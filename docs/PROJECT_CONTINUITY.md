# Software gestão de alojamento local — continuidade

Este registo reúne decisões disponíveis do projeto Management Hub em 21 de setembro de 2026. É uma síntese de continuidade, não uma exportação integral das conversas nem uma certificação da instalação real. Verificar o código atual e a revisão a utilizar antes de alterar um módulo.

## Estado e próximos passos

| Área | Ponto de continuidade | Limite ou próximo passo |
| --- | --- | --- |
| Hub PHP/MySQL | Código existente, utilizadores/perfis, espaços, listas, itens, intervalos e calendário. | Preservar dados, permissões e contratos comuns. |
| Tradução PT/EN | Catálogo comum, tradução persistente de conteúdo natural e auditorias documentadas em `I18N.md`. | Verificar casos alterados nos dois idiomas; a existência dos testes não confirma todos os dados da base real. |
| Atribuições | Governanta pode criar intervalos; um item tem apenas uma data e uma empregada dentro do mesmo intervalo. | Preservar validação no servidor e feedback comum. |
| WhatsApp | Módulo e programação existentes no Hub. | Verificar cron, configuração privada, aprovação de templates e entrega real antes de declarar operação concluída. |
| My2N | Painel integrado no Hub. | Confirmar modelo real de morada/local/porta e possibilidades do serviço; não assumir que uma campainha aceita a mesma associação a apartamento que um telefone. |
| Faturas e Portais | Código do módulo e executor Windows preparado na PR #72. | Instalação, HTTPS real, emparelhamento, reinício e portais/2FA continuam por validar. |
| Automação Cloudbeds → ZKAccess | Configuração no Hub; fonte V5.1 agora disponível em `reference-sources/`. | Fonte anterior não significa integração operacional; verificar versão instalada antes de substituições. V5.0 é referência histórica. |
| Gerador de códigos | Google Apps Script e interface agora disponíveis em `reference-sources/`. | Código mantém aplicação independente. Configurar Sheets/propriedades privadamente; não fundir automaticamente no Hub. |
| Referência ZKTeco | Código técnico fornecido pelo proprietário, com endereço privado removido. | Ler as notas antes de adaptar; a importação não valida o sistema de controlo de acesso. |
| Proposta Invoicees | Proposta móvel recuperada em `reference-sources/Invoicees.html`, com cinco áreas e decisões guardadas. | Ler `INVOICEES_MIGRATION_NOTES.md`; dados são de demonstração e implementação/validação continuam pendentes. |

## Decisões transversais

- Preservar o projeto existente e todos os módulos. Não iniciar uma aplicação nova para cada pedido.
- Permissões verificadas no servidor, proteção CSRF nas alterações e acesso obrigatório do Gerente conforme o código. Usar a matriz existente para os outros perfis.
- Toda a aplicação deve suportar português de Portugal e inglês, incluindo futuras áreas, listas e módulos. Texto natural persistido usa os dois valores; APIs localizam apenas mensagens para pessoas.
- Literais técnicos entre aspas, nomes, identificadores, endereços, códigos e credenciais mantêm-se canónicos. Seguir `I18N.md`; não criar dicionários ou exceções locais para contornar o contrato comum.
- Menus fecham ao clicar fora ou noutro menu principal. Preservar scroll e contexto nas operações indicadas em `save-context.md`.
- Ordem das ações comuns: New, Delete, Edit. Reutilizar diálogos, validação e componentes partilhados.
- Para decisões de navegação/usabilidade, o proprietário pediu revisão e segunda opinião. Distinguir uma proposta aprovada de código já implementado.

## Faturas e infraestrutura

O Hub mantém controlo, fila, documentos, histórico e permissões no alojamento PHP/MySQL. A arquitetura escolhida para executar Chrome é o agente Windows com sandbox, descrito em `WINDOWS_INVOICE_AGENT.md`. Documentação antiga sobre Chrome no alojamento é contexto histórico.

O computador inicia a ligação HTTPS autenticada ao Hub. A automação de faturas é independente da automação ZKTeco. Não parar nem modificar serviços existentes para preparar a passagem ao Codex.

O módulo deve permitir recolha programada e apresentar estado, última execução, falhas e ações necessárias ao responsável. A lista de portais prevista inclui Booking, Hostelworld, Expedia, Airbnb e Stripe. Suporte estrutural não comprova autenticação, 2FA ou recolha real de cada portal.

## Publicação e validação

- Base de continuidade: `codex/management-hub`; base de desenvolvimento anterior: `agent/room-item-assignments`. `main` é antiga.
- Não integrar automaticamente a PR #72 nem outras branches pendentes.
- A autorização para transferir o projeto para Codex não publica uma nova versão no servidor nem instala/ativa software no Windows.
- Validar apenas o necessário para a mudança concreta. Não executar uma bateria de testes para uma simples leitura de continuidade.
- Configurações, sessões, faturas, base real e credenciais continuam fora do Git. Utilizar apenas meios protegidos ao configurar os ambientes reais.

## Limites da passagem

O repositório reúne código, documentação e cópias das fontes anteriores adequadas ao desenvolvimento. O histórico completo de chats, as credenciais/configurações reais e a instalação Windows não são transferidos por um commit. O contexto privado complementar fica na conversa Codex. A proposta visual Invoicees foi recuperada em 22 de setembro de 2026 e incluída como referência de desenvolvimento, com identificação pessoal substituída. Os cinco anexos disponibilizados estão agora representados no repositório; isso não implementa a proposta visual nem valida a operação real.

Antes de comunicar que um ficheiro ou funcionalidade existe, confirmar diretamente a sua presença ou estado. Quando o acesso remoto estiver indisponível, informar a limitação e trabalhar apenas sobre a revisão identificada na tarefa.
