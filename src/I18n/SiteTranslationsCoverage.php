<?php
declare(strict_types=1);

/**
 * Coverage catalogue for static UI text discovered by the source-code audit.
 *
 * Keep machine identifiers, credentials, API enum values and user-authored
 * content out of this file. Dynamic/user-authored content belongs in paired
 * PT/EN database columns; technical values stay untranslated.
 */
return [
    // Shared HTTP / validation messages.
    'Método não permitido.' => 'Method not allowed.',
    'Pedido inválido.' => 'Invalid request.',
    'Operação inválida.' => 'Invalid operation.',
    'Não foi possível aceder à base de dados.' => 'Could not access the database.',
    'Token CSRF inválido.' => 'Invalid CSRF token.',
    'Bootstrap de autenticação indisponível.' => 'Authentication bootstrap unavailable.',
    'Perfil inválido.' => 'Invalid role.',

    // Verification periods / assignments API.
    'Indique um nome para o intervalo (máximo 120 caracteres).' => 'Enter a name for the period (maximum 120 characters).',
    'A data {value} do intervalo é inválida.' => 'The {value} date for the period is invalid.',
    'A data final não pode ser anterior à data inicial.' => 'The end date cannot be earlier than the start date.',
    'O intervalo tem itens atribuídos entre {value} e {value}. As novas datas têm de incluir todo esse período.' => 'The period has items assigned between {value} and {value}. The new dates must include that entire range.',
    'Escolha o alojamento, a lista, a empregada e a data.' => 'Choose the property, list, housekeeper and date.',
    'Esta empregada não tem itens atribuídos nessa data.' => 'This housekeeper has no items assigned on that date.',
    'A empregada não tem telemóvel configurado.' => 'The housekeeper does not have a mobile number configured.',
    'Alterações de atribuição inválidas.' => 'Invalid assignment changes.',
    'Escolha uma data válida para a verificação.' => 'Choose a valid verification date.',
    'Alteração de atribuição inválida.' => 'Invalid assignment change.',
    'Item de atribuição inválido ou repetido.' => 'Invalid or duplicate assignment item.',
    'As instruções de {value} são demasiado longas.' => 'The instructions for {value} are too long.',
    'Escolha um intervalo de verificação válido.' => 'Choose a valid verification period.',
    'A data da verificação tem de ficar dentro do intervalo escolhido.' => 'The verification date must be within the selected period.',
    'O item {value} já está atribuído ou concluído noutra data deste intervalo.' => 'The item {value} is already assigned or completed on another date in this period.',
    'Seleção de itens inválida.' => 'Invalid item selection.',
    'Instruções de verificação inválidas.' => 'Invalid verification instructions.',
    'Dados do checklist inválidos.' => 'Invalid checklist data.',

    // Item-list management.
    'Escolha uma área a verificar válida.' => 'Choose a valid area to verify.',
    'A descrição da verificação não pode ultrapassar 5000 caracteres.' => 'The verification description cannot exceed 5000 characters.',
    'A lista inicial dos quartos não pode ser apagada.' => 'The initial room list cannot be deleted.',
    'Esta lista já tem dados ou atribuições e não pode ser apagada.' => 'This list already has data or assignments and cannot be deleted.',
    'Item não encontrado.' => 'Item not found.',
    'Este item já tem dados ou atribuições e não pode ser apagado.' => 'This item already has data or assignments and cannot be deleted.',
    'Não foi possível guardar a alteração.' => 'Could not save the change.',

    // Rooms / tasks UI.
    'Selecionar alojamento, quarto e atribuição' => 'Select property, room and assignment',
    'Esta aplicação necessita de JavaScript para carregar os dados.' => 'This application requires JavaScript to load the data.',
    'Não tem permissão para atribuir tarefas.' => 'You do not have permission to assign tasks.',
    'Foi selecionada uma empregada inválida ou inativa.' => 'An invalid or inactive housekeeper was selected.',
    'Não tem permissão para concluir tarefas.' => 'You do not have permission to complete tasks.',
    'A tarefa já foi concluída ou não lhe está atribuída.' => 'The task has already been completed or is not assigned to you.',
    'Item marcado como concluído.' => 'Item marked as completed.',
    'Não atribuído' => 'Unassigned',
    'Escolha no calendário um dia assinalado a verde para consultar os itens atribuídos.' => 'Choose a green-marked day on the calendar to view the assigned items.',
    ', quarto' => ', room',

    // Dynamic room-assignment JavaScript UI.
    'Funcionária não identificada' => 'Housekeeper not identified',
    'Todos os itens atribuídos neste intervalo' => 'All items assigned in this period',
    '{value} de {value} itens atribuídos neste intervalo' => '{value} of {value} items assigned in this period',
    'Nenhum item atribuído neste intervalo' => 'No items assigned in this period',
    'Não pode ser posterior à primeira atribuição ({value})' => 'Cannot be later than the first assignment ({value})',
    'Não pode ser anterior à última atribuição ({value})' => 'Cannot be earlier than the last assignment ({value})',
    'A verificar' => 'To verify',
    'já foi concluído' => 'has already been completed',
    'já está atribuído para {value}' => 'is already assigned for {value}',
    'Existem itens atribuídos a outra empregada ou data. Altere apenas as checkboxes disponíveis.' => 'Some items are assigned to another housekeeper or date. Change only the available checkboxes.',
    'Situação das atribuições — escolha a empregada e a data para alterar' => 'Assignment status — choose the housekeeper and date to change',
    'Escolha ou crie um intervalo' => 'Choose or create a period',
    'Erro ao carregar.' => 'Error loading.',
    'A guardar automaticamente…' => 'Saving automatically…',
    'Erro ao guardar automaticamente.' => 'Error saving automatically.',
    'Erro ao carregar alerta.' => 'Error loading reminder.',
    'Erro ao guardar alerta.' => 'Error saving reminder.',
    'A guardar…' => 'Saving…',
    'Alterações por guardar' => 'Unsaved changes',
    'Preencha o nome e as duas datas do intervalo' => 'Enter the period name and both dates',
    'A criar intervalo…' => 'Creating period…',
    'Erro ao criar o intervalo.' => 'Error creating the period.',
    'A guardar intervalo…' => 'Saving period…',
    'Erro ao guardar o intervalo.' => 'Error saving the period.',
    'Apagar o intervalo “{value}”? Todas as atribuições deste intervalo também serão apagadas. Esta ação não pode ser anulada.' => 'Delete the period “{value}”? All assignments in this period will also be deleted. This action cannot be undone.',
    'A apagar intervalo…' => 'Deleting period…',
    'Erro ao apagar o intervalo.' => 'Error deleting the period.',
    'Intervalo apagado ({value} atribuições removidas)' => 'Period deleted ({value} assignments removed)',

    // My2N provider / service errors that can reach the admin UI.
    'A API My2N não devolveu nenhuma campainha com destination group neste site.' => 'The My2N API returned no bell with a destination group on this site.',
    'My2N está em modo dry-run; escritas estão desativadas.' => 'My2N is in dry-run mode; writes are disabled.',
    'Um grupo vazio exige confirmação explícita separada.' => 'An empty group requires separate explicit confirmation.',
    'Member ID inválido.' => 'Invalid Member ID.',
    'A autenticação My2N não devolveu flow_id.' => 'My2N authentication did not return flow_id.',
    'A autenticação My2N não devolveu session_token.' => 'My2N authentication did not return session_token.',
    'Credenciais My2N não configuradas no servidor.' => 'My2N credentials are not configured on the server.',
    'A extensão PHP cURL é necessária.' => 'The PHP cURL extension is required.',
    'Campainha inválida ou já removida do site.' => 'Invalid bell or bell already removed from the site.',
    'A API My2N não devolveu a lista de contactos da campainha.' => 'The My2N API did not return the bell contact list.',
    'A ligação My2N foi configurada, mas ainda falta identificar a empresa e o site.' => 'The My2N connection is configured, but the company and site still need to be identified.',
    'Campainha inválida.' => 'Invalid bell.',
    'Esta campainha tem membros que ainda não foram associados a um telemóvel; a gravação foi bloqueada.' => 'This bell has members that are not yet associated with a mobile device; saving was blocked.',
    'Os destinatários desta campainha foram alterados entretanto. Atualize a lista antes de tentar novamente.' => 'The recipients for this bell changed in the meantime. Refresh the list before trying again.',
    'Foi selecionado um telemóvel que não pertence a este site.' => 'A mobile device that does not belong to this site was selected.',
    'A My2N não confirmou todos os destinatários pedidos.' => 'My2N did not confirm all requested recipients.',
    'Selecione pelo menos um destinatário para a campainha.' => 'Select at least one recipient for the bell.',
    'Não foi possível criar o diretório privado My2N.' => 'Could not create the private My2N directory.',
    'Não foi possível preparar o ficheiro privado My2N.' => 'Could not prepare the private My2N file.',
    'Não foi possível guardar as credenciais My2N.' => 'Could not save the My2N credentials.',
    'Não foi possível ativar as credenciais My2N.' => 'Could not activate the My2N credentials.',
    'O caminho privado das credenciais My2N não está configurado.' => 'The private My2N credentials path is not configured.',
    'As credenciais My2N não podem ficar em public_html.' => 'My2N credentials cannot be stored in public_html.',
    'As alterações My2N estão preparadas, mas continuam bloqueadas no servidor até ao teste autorizado.' => 'My2N changes are prepared but remain blocked on the server until the authorised test.',
    'Já existe outra alteração My2N em curso. Tente novamente.' => 'Another My2N change is already in progress. Try again.',

    // Markup fragments split by technical <code> values.
    'para ativar esta configuração.' => 'to enable this configuration.',
    'para ativar a configuração persistente. Até lá, a aplicação usa a matriz padrão abaixo.' => 'to enable persistent configuration. Until then, the application uses the default matrix below.',
    'Horário interpretado em Europe/Lisbon. A ativação só é aceite quando existe uma configuração privada fora de' => 'Schedule is interpreted in Europe/Lisbon. Activation is only accepted when a private configuration exists outside',
    'Conta técnica utilizada pelo portal. A password fica num ficheiro privado fora de' => 'Technical account used by the portal. The password is stored in a private file outside',
];
