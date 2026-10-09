# Publicação das atribuições por intervalo

## Antes de publicar

1. Crie um backup completo da base de dados no cPanel/phpMyAdmin.
2. Confirme que o backup foi descarregado e pode ser restaurado.
3. No cPanel Git Version Control, confirme:
   - repositório: `danieldeploy/room-check`;
   - branch: `agent/room-item-assignments`;
   - destino da aplicação: `/home/welcome/public_html/check`.
4. Não altere nem substitua `config.local.php`.

## Migrações da base de dados

Numa instalação existente, abra o phpMyAdmin, escolha a base de dados da aplicação e execute, uma única vez e por esta ordem:

1. `migrations/006_room_item_assignments.sql`
2. `migrations/007_room_item_assignment_dates.sql`
3. `migrations/008_room_verification_intervals.sql`

Não volte a executar uma migração que já tenha sido aplicada. A migração 008 conserva as atribuições anteriores num intervalo chamado **Atribuições anteriores**.

## Publicação

1. No cPanel Git Version Control, execute **Update from Remote**.
2. Confirme que o HEAD corresponde ao commit indicado no PR #4.
3. Execute **Deploy HEAD Commit**.
4. Termine a sessão e volte a entrar para atualizar as permissões da sessão.

## Teste funcional

1. Entre como Governanta.
2. Abra **Gestão dos Quartos**.
3. Crie um intervalo com data inicial e final.
4. Escolha alojamento e quarto.
5. Escolha o intervalo, uma empregada e uma data dentro do intervalo.
6. Selecione alguns itens e guarde.
7. Tente mudar a data ou a empregada do mesmo item: a atribuição deve ser substituída, nunca duplicada.
8. Entre como Empregada de Andares e confirme que apenas as tarefas próprias aparecem.
9. Marque um item como concluído.

## Rollback

Se surgir um erro antes de existirem novos dados de produção:

1. publique novamente o commit anterior;
2. restaure o backup da base de dados criado antes das migrações.

Depois de começarem a existir atribuições em vários intervalos, não tente remover manualmente `interval_id`: faça rollback apenas por restauro do backup, para evitar perda ou mistura de atribuições.
