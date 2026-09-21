# Management Hub — instruções de continuidade

## Começar aqui
Leia `docs/CODEX_HANDOFF.md`, `README.md` e os documentos do módulo a alterar.
Leia também `docs/PROJECT_CONTINUITY.md`. As fontes anteriores do gerador e Cloudbeds/ZKAccess estão em `docs/reference-sources/`; são referências de desenvolvimento com dados privados removidos, não instalações ativas.
O projeto é uma aplicação PHP/MySQL existente. Preserve a arquitetura e as funcionalidades atuais.
Responda ao proprietário em português de Portugal, com explicações curtas e claras.

## Git e publicação
- A base de desenvolvimento do Hub é `agent/room-item-assignments`. `main` contém a versão inicial antiga.
- `codex/management-hub` é o ponto de continuidade preparado para o Codex: inclui o trabalho ainda em rascunho da PR #72 e esta documentação.
- A PR #72 (`codex/windows-invoice-agent`) ainda exige validação no computador Windows e no alojamento reais.
- Confirme o estado remoto antes de editar; não descarte trabalho existente.
- Siga a regra de deployment do README e a autorização presente na conversa. Preparar o projeto no Codex não ativa a automação nem publica no servidor.

## Regras transversais
- Autorização no servidor, incluindo APIs; CSRF nas alterações; preservar o acesso obrigatório do Gerente.
- Tradução PT/EN pelo catálogo e serviços comuns. Persistir os pares bilingues em conjunto; preservar literais técnicos entre aspas, nomes, identificadores, URLs, códigos e credenciais.
- Reutilizar menus, diálogos, validação e recuperação de contexto existentes. Ordem CRUD: New, Delete, Edit. Menus fecham ao clicar fora ou noutro menu principal.
- Dentro de cada intervalo, um item tem uma única data e uma única empregada. O Gerente e a Governanta podem gerir atribuições.
- Usar os contratos em `docs/I18N.md`, `docs/save-context.md` e os testes de CI.

## Ambiente e segurança operacional
- Segredos, `config.local.php`, sessões, bases de dados reais, documentos de hóspedes e faturas ficam fora do Git.
- O Hub permanece no cPanel. O Chrome de recolha de faturas será executado no Windows com sandbox ativo.
- Não interferir com a instalação ZKTeco existente. O executor de faturas é independente.
- Testes que escrevem na base de dados usam apenas bases descartáveis.
- Não executar instaladores, tarefas agendadas ou automações de `docs/reference-sources/` durante a leitura inicial. Não copiar essas referências para `public_html` nem substituir a instalação existente. Ler as notas de migração antes de adaptar os fontes.
- Configurações de exemplo exigem preenchimento privado. Não pedir credenciais, PINs reais ou sessões no chat nem voltar a colocá-los no Git.
- Fonte dos comandos de validação: `.github/workflows/ci.yml`.
- Distinga código implementado, testes automáticos aprovados, instalação real e ativação em produção.
