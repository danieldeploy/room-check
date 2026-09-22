# Management Hub — continuidade no Codex

Preparado em 21 de setembro de 2026.

## Repositório e branches
- Repositório: https://github.com/danieldeploy/room-check
- Branch preparada para continuidade: `codex/management-hub`.
- Base do desenvolvimento existente: `agent/room-item-assignments`.
- `main` contém a versão inicial antiga.
- Esta branch parte do commit `3d4b8e53f1bfb3f47328063380cf95c87a187f7a`, verificado na PR #72, e acrescenta documentação de continuidade e cópias de desenvolvimento das fontes anteriores em `docs/reference-sources/`.
- Antes de editar, confirmar o estado atual das branches e preservar trabalho ainda em curso.

## Leitura inicial
1. `AGENTS.md`: convenções do projeto.
2. `README.md`: arquitetura e instalação existentes.
3. `docs/I18N.md`: regras comuns de tradução.
4. `docs/save-context.md`: preservação de contexto da interface.
5. `docs/WINDOWS_INVOICE_AGENT.md`: trabalho da PR #72.
6. `.github/workflows/ci.yml`: ambiente e comandos de validação.
7. `docs/PROJECT_CONTINUITY.md`: decisões, estado por módulo e limites da passagem.
8. `docs/reference-sources/README.md`: fontes dos anexos, configuração privada e inventário.

A PR #72 continua como trabalho pendente: https://github.com/danieldeploy/room-check/pull/72. Verificar o seu estado atual antes de continuar ou integrar alterações.

## Desenvolvimento
- Preservar os módulos existentes; o âmbito é todo o Management Hub.
- Reutilizar os componentes e serviços comuns.
- A interface é bilingue PT/EN, com as regras documentadas em I18N.
- Não substituir o projeto existente por uma aplicação nova.
- Reportar o que foi alterado, o que foi verificado e o que ainda depende de validação.
- Esta preparação não cria por si só um ambiente na conta Codex. A criação/seleção deve ser confirmada na interface.

## Contexto complementar
O proprietário dispõe de um documento privado, `Management_Hub_Continuidade_Codex.md`, com o estado detalhado e os próximos passos. O contexto privado é fornecido na conversa Codex e não deve ser publicado neste repositório. As fontes dos quatro anexos de automação foram incorporadas em `docs/reference-sources/`, com configurações de exemplo e remoção dos valores operacionais privados. Os arquivos originais, com as configurações reais, permanecem privados. Consulte o inventário antes de trabalhar numa automação.

A proposta visual foi recuperada do anexo `Invoicees(1).html` em 22 de setembro de 2026 e está em `docs/reference-sources/Invoicees.html`, com nome/email pessoais substituídos por dados de demonstração. Ler `docs/reference-sources/INVOICEES_MIGRATION_NOTES.md` e a proposta antes de continuar o módulo. A transferência deste quinto anexo está concluída; a implementação da proposta e as validações reais continuam pendentes. As conversas antigas não foram copiadas integralmente: as decisões recuperadas estão resumidas neste repositório e no contexto privado da conversa.

## Pedido inicial
“Continuar o Management Hub nesta branch. Lê AGENTS.md, docs/CODEX_HANDOFF.md e o contexto privado disponibilizado. Verifica as branches e a PR #72 e apresenta os próximos passos, preservando os módulos e as regras comuns.”
