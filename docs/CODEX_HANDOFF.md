# Management Hub — continuidade no Codex

Preparado em 21 de setembro de 2026.

## Repositório e branches
- Repositório: https://github.com/danieldeploy/room-check
- Branch preparada para continuidade: `codex/management-hub`.
- Base do desenvolvimento existente: `agent/room-item-assignments`.
- `main` contém a versão inicial antiga.
- Esta branch parte do commit `3d4b8e53f1bfb3f47328063380cf95c87a187f7a`, verificado na PR #72, e acrescenta apenas documentação de continuidade.
- Antes de editar, confirmar o estado atual das branches e preservar trabalho ainda em curso.

## Leitura inicial
1. `AGENTS.md`: convenções do projeto.
2. `README.md`: arquitetura e instalação existentes.
3. `docs/I18N.md`: regras comuns de tradução.
4. `docs/save-context.md`: preservação de contexto da interface.
5. `docs/WINDOWS_INVOICE_AGENT.md`: trabalho da PR #72.
6. `.github/workflows/ci.yml`: ambiente e comandos de validação.

A PR #72 continua como trabalho pendente: https://github.com/danieldeploy/room-check/pull/72. Verificar o seu estado atual antes de continuar ou integrar alterações.

## Desenvolvimento
- Preservar os módulos existentes; o âmbito é todo o Management Hub.
- Reutilizar os componentes e serviços comuns.
- A interface é bilingue PT/EN, com as regras documentadas em I18N.
- Não substituir o projeto existente por uma aplicação nova.
- Reportar o que foi alterado, o que foi verificado e o que ainda depende de validação.
- Esta preparação não cria por si só um ambiente na conta Codex. A criação/seleção deve ser confirmada na interface.

## Contexto complementar
O proprietário dispõe de um documento privado, `Management_Hub_Continuidade_Codex.md`, com o estado detalhado e os próximos passos. Esse documento e os anexos originais não integram este repositório público. Consultá-los quando forem disponibilizados na conversa Codex.

## Pedido inicial
“Continuar o Management Hub nesta branch. Lê AGENTS.md, docs/CODEX_HANDOFF.md e o contexto privado disponibilizado. Verifica as branches e a PR #72 e apresenta os próximos passos, preservando os módulos e as regras comuns.”
