<?php
declare(strict_types=1);

require_once __DIR__ . '/Translator.php';

/**
 * Shared site-wide PT/EN catalogue.
 *
 * Existing pages can keep their Portuguese source markup: boot() registers the
 * English equivalents with Translator, whose output layer translates visible
 * HTML and DOM content. New modules should prefer text() for new static UI
 * strings so both languages are declared together at the point of use.
 *
 * User-authored/persistent content must not be added here. It belongs in paired
 * database columns and must use ContentTranslator + Translator::localized().
 */
final class SiteTranslations
{
    private static bool $registered = false;

    public static function boot(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        foreach (self::catalog() as $portuguese => $english) {
            Translator::registerDynamic($portuguese, $english);
        }
    }

    public static function text(string $portuguese, string $english): string
    {
        Translator::registerDynamic($portuguese, $english);
        return Translator::localized($portuguese, $english);
    }

    public static function format(string $portuguese, string $english, array $replacements = []): string
    {
        $text = self::text($portuguese, $english);
        return $replacements === [] ? $text : strtr($text, $replacements);
    }

    public static function catalog(): array
    {
        $catalog = [
            // Shared shell / portal.
            'Sessão e navegação principal' => 'Session and main navigation',
            'Minha agenda' => 'My schedule',
            'O que pretende gerir?' => 'What would you like to manage?',
            'Sem módulos atribuídos' => 'No modules assigned',
            'O seu perfil está ativo, mas ainda não tem acesso a nenhum módulo.' => 'Your profile is active, but it does not have access to any modules yet.',
            'Módulos disponíveis' => 'Available modules',
            'Abrir módulo →' => 'Open module →',
            'Automação de códigos' => 'Code automation',
            'Operações de verificação' => 'Inspection operations',
            'Verificação dos espaços dos alojamentos e respetivas listas.' => 'Inspection of accommodation spaces and their respective lists.',
            'Equipa de limpeza' => 'Housekeeping team',
            'Campainha' => 'Bell',
            'Campainhas' => 'Bells',
            'Disponível' => 'Available',
            'Desligada' => 'Disabled',
            'Por configurar' => 'Not configured',
            'Ativa · dry-run' => 'Active · dry-run',
            'Ativa · live' => 'Active · live',
            'Consulta read-only' => 'Read-only',
            'Configuração e estado da automação Cloudbeds → ZKAccess V5.1 Direct POST.' => 'Cloudbeds → ZKAccess V5.1 Direct POST automation configuration and status.',

            // Common administration.
            'Painel administrativo' => 'Administration panel',
            'Administração' => 'Administration',
            'Ação inválida.' => 'Invalid action.',
            'Existe texto incorretamente escrito em português. Quer corrigir ou anular a edição?' => 'There is text incorrectly written in English. Do you want to correct it or cancel the edit?',
            'Tem uma edição não guardada. Quer continuar a editar ou anular a edição?' => 'There is an unsaved edit. Do you want to continue editing or cancel the edit?',
            'Continuar a editar' => 'Continue editing',
            'Anular edição' => 'Cancel edit',
            'Não tem permissão para esta ação.' => 'You do not have permission to perform this action.',
            'Não tem permissão para consultar tarefas.' => 'You do not have permission to view tasks.',
            'Configuração' => 'Configuration',
            'Atualizar' => 'Refresh',
            'Última leitura' => 'Last read',
            'Modo' => 'Mode',
            'Ativa' => 'Active',
            'Desativada' => 'Disabled',
            'Desativado' => 'Disabled',
            'Desativar' => 'Disable',
            'Ativar' => 'Enable',
            'Alterar' => 'Change',
            'Guardar configuração' => 'Save configuration',
            'Configuração guardada.' => 'Configuration saved.',
            'A carregar…' => 'Loading…',
            'A carregar...' => 'Loading...',
            'Importe ' => 'Import ',
            ' para ativar esta configuração.' => ' to enable this configuration.',

            // Initial setup.
            'Configuração inicial' => 'Initial setup',
            'Configuração única' => 'One-time setup',
            'Primeiro Gerente' => 'First Manager',
            'Conta criada. Remova a chave de instalação do servidor.' => 'Account created. Remove the installation key from the server.',
            'Ir para o login' => 'Go to sign in',
            'Esta página deixa de funcionar assim que a primeira conta for criada.' => 'This page stops working as soon as the first account is created.',
            'Chave de instalação' => 'Installation key',
            'Password (mínimo 12 caracteres)' => 'Password (minimum 12 characters)',
            'Criar Gerente' => 'Create Manager',
            'A configuração inicial já foi concluída.' => 'Initial setup has already been completed.',
            'Chave de instalação inválida.' => 'Invalid installation key.',
            'O utilizador deve ter 3–64 caracteres: letras, números, ponto, hífen ou underscore.' => 'The username must contain 3–64 characters: letters, numbers, dot, hyphen or underscore.',
            'Indique um nome válido.' => 'Enter a valid name.',
            'Não foi possível bloquear a configuração inicial. Tente novamente.' => 'Could not lock initial setup. Try again.',

            // My2N / Bell.
            'Controlo My2N' => 'My2N Control',
            'APENAS CONSULTA' => 'VIEW ONLY',
            'ALTERAÇÕES BLOQUEADAS' => 'CHANGES BLOCKED',
            'ALTERAÇÕES AUTORIZADAS' => 'CHANGES ENABLED',
            'Login da conta My2N' => 'My2N account login',
            'Conta técnica utilizada pelo portal. A password fica num ficheiro privado fora de ' => 'Technical account used by the portal. The password is stored in a private file outside ',
            'CONFIGURADO' => 'CONFIGURED',
            'POR CONFIGURAR' => 'NOT CONFIGURED',
            'Login My2N guardado e validado.' => 'My2N login saved and validated.',
            'Não tem permissão para gerir o login My2N.' => 'You do not have permission to manage the My2N login.',
            'Login atual:' => 'Current login:',
            'Login My2N' => 'My2N login',
            'Password My2N' => 'My2N password',
            'Validar e guardar' => 'Validate and save',
            'Use uma conta My2N própria para a integração e sem MFA. A password nunca volta a ser mostrada.' => 'Use a dedicated My2N account for the integration without MFA. The password is never shown again.',
            'Site' => 'Site',
            'Campainhas e destinatários' => 'Bells and recipients',
            'Campainhas, apartamentos e telemóveis são lidos automaticamente da My2N sempre que atualizar.' => 'Bells, apartments and mobile devices are read automatically from My2N whenever you refresh.',
            'Ap. campainha' => 'Bell apt.',
            'Telemóvel' => 'Mobile',
            'Ap. telemóvel' => 'Mobile apt.',
            'Recebe chamadas' => 'Receives calls',
            'Carregue os dados My2N para gerir os destinatários.' => 'Load My2N data to manage recipients.',
            'Guardar destinatários' => 'Save recipients',
            'A seleção está disponível para preparação, mas a gravação continua bloqueada no servidor até ao primeiro teste autorizado.' => 'The selection can be prepared, but saving remains blocked on the server until the first authorised test.',
            'NUNCA REGISTADO' => 'NEVER REGISTERED',
            'NÃO REGISTADO' => 'NOT REGISTERED',
            'DESATIVADO' => 'DISABLED',
            'SEM LICENÇA' => 'UNLICENSED',
            'DESCONHECIDO' => 'UNKNOWN',
            'Push: configurado' => 'Push: configured',
            'Push: não configurado' => 'Push: not configured',
            'Atende' => 'Answers',
            'Não atende' => 'Does not answer',
            ' atende ' => ' answers ',
            'associação(ões)' => 'association(s)',
            'associação(ões) ativa(s)' => 'active association(s)',
            'associação(ões) campainha–telemóvel selecionada(s)' => 'bell–mobile association(s) selected',
            'campainha(s)' => 'bell(s)',
            'telemóvel(is)' => 'mobile device(s)',
            'membro(s)' => 'member(s)',
            'com alterações por guardar' => 'with unsaved changes',
            'ainda sem associação' => 'still unassigned',
            'Não foi encontrada nenhuma campainha com destination group neste site.' => 'No bell with a destination group was found on this site.',
            'Não foi encontrado nenhum telemóvel MOBILE_VIDEO neste site.' => 'No MOBILE_VIDEO mobile device was found on this site.',
            'A consultar campainhas, apartamentos e telemóveis na My2N…' => 'Loading bells, apartments and mobile devices from My2N…',
            'Não foi possível consultar My2N.' => 'Could not query My2N.',
            'Não foi possível carregar as associações.' => 'Could not load the associations.',
            'Cada campainha deve manter pelo menos um telemóvel destinatário.' => 'Each bell must keep at least one recipient mobile device.',
            'Guardar alterações em' => 'Save changes to',
            'A guardar e confirmar as associações na My2N…' => 'Saving and confirming associations in My2N…',
            'Não foi possível guardar os destinatários.' => 'Could not save the recipients.',
            'Associações atualizadas e confirmadas na My2N.' => 'Associations updated and confirmed in My2N.',
            'A lista foi atualizada para mostrar o estado confirmado.' => 'The list was refreshed to show the confirmed state.',
            'Grupo SIP' => 'SIP group',

            // ZKAccess.
            'Estado da integração' => 'Integration status',
            'O portal não guarda nem apresenta utilizadores, passwords ou sessões do Cloudbeds/ZKAccess.' => 'The portal does not store or display Cloudbeds/ZKAccess usernames, passwords or sessions.',
            'Automação' => 'Automation',
            'Executor privado' => 'Private runner',
            'Configuração válida detetada' => 'Valid configuration detected',
            'Não ligado' => 'Not connected',
            'Estado do runner' => 'Runner status',
            'Ficheiro de estado detetado' => 'Status file detected',
            'Sem comunicação' => 'No communication',
            'Parâmetros operacionais' => 'Operating parameters',
            'Horário interpretado em Europe/Lisbon. A ativação só é aceite quando existe uma configuração privada fora de ' => 'Schedule is interpreted in Europe/Lisbon. Activation is only accepted when a private configuration exists outside ',
            'Hora diária' => 'Daily time',
            'Termo dos quartos' => 'Room search term',
            'Simula as alterações sem guardar códigos no ZKAccess.' => 'Simulates changes without saving codes in ZKAccess.',
            'Ativar automação' => 'Enable automation',
            'Disponível apenas depois de o executor privado estar configurado.' => 'Available only after the private runner is configured.',
            'Confirmo o modo live' => 'I confirm live mode',
            'Obrigatório em cada gravação quando o dry-run estiver desativado.' => 'Required on every save when dry-run is disabled.',
            'Versão preparada' => 'Prepared version',
            'Leitura das chegadas do dia no Cloudbeds e obtenção do PIN nas notas.' => 'Reads today’s Cloudbeds arrivals and obtains the PIN from the notes.',
            'Atualização Direct POST no ZKAccess, com fallback visual.' => 'Direct POST update in ZKAccess, with visual fallback.',
            'O agendamento real depende de Python, Chromium e sessão Cloudbeds com MFA no servidor privado.' => 'Real scheduling depends on Python, Chromium and a Cloudbeds MFA session on the private server.',
            'Não tem permissão para configurar a automação.' => 'You do not have permission to configure the automation.',
            'A tabela de configuração ainda não existe. Importe a migração 004_portal_permissions.sql.' => 'The configuration table does not exist yet. Import migration 004_portal_permissions.sql.',
            'Indique uma hora válida.' => 'Enter a valid time.',
            'O termo de pesquisa deve ter 1–40 caracteres válidos.' => 'The search term must contain 1–40 valid characters.',
            'O executor privado ainda não está configurado; a automação não pode ser ativada.' => 'The private runner is not configured yet; automation cannot be enabled.',
            'Confirme explicitamente a saída do modo dry-run.' => 'Explicitly confirm leaving dry-run mode.',
            'Configuração da automação guardada.' => 'Automation configuration saved.',
            'O seu perfil permite consultar esta página, mas não alterar a configuração.' => 'Your profile can view this page but cannot change the configuration.',

            // Users.
            'Novo utilizador' => 'New user',
            'Contas existentes' => 'Existing accounts',
            'Password inicial' => 'Initial password',
            'Nova password' => 'New password',
            'Utilizador criado.' => 'User created.',
            'Dados do utilizador atualizados.' => 'User details updated.',
            'Estado da conta atualizado.' => 'User status updated.',
            'Password atualizada.' => 'Password updated.',
            'Email inválido.' => 'Invalid email.',
            'Telemóvel inválido.' => 'Invalid mobile number.',
            'Utilizador inválido.' => 'Invalid username.',
            'Nome inválido.' => 'Invalid name.',
            'Nome preferido' => 'Preferred name',
            'Nome preferido inválido.' => 'Invalid preferred name.',
            'Utilizador não encontrado.' => 'User not found.',
            'Não pode desativar a própria conta.' => 'You cannot disable your own account.',
            'Esse nome de utilizador ou email já está associado a outra conta.' => 'That username or email is already associated with another account.',

            // Permissions.
            'Permissões dos perfis' => 'Role permissions',
            'Permissões atualizadas.' => 'Permissions updated.',
            'A tabela de permissões ainda não existe. Importe a migração 004_portal_permissions.sql.' => 'The permissions table does not exist yet. Import migration 004_portal_permissions.sql.',
            'Importe migrations/004_portal_permissions.sql para ativar a configuração persistente. Até lá, a aplicação usa a matriz padrão abaixo.' => 'Import migrations/004_portal_permissions.sql to enable persistent configuration. Until then, the application uses the default matrix below.',
            'Matriz de acesso' => 'Access matrix',
            'Permissões de alteração incluem automaticamente a permissão de consulta do mesmo módulo.' => 'Change permissions automatically include view permission for the same module.',
            'Permissão' => 'Permission',
            'Obrigatória' => 'Required',
            'Guardar permissões' => 'Save permissions',
            'Gestão de Quartos' => 'Space Management',
            'Consultar quartos' => 'View spaces',
            'Alterar quartos' => 'Edit spaces',
            'Consultar automação' => 'View automation',
            'Configurar automação' => 'Configure automation',
            'Consultar campainha' => 'View bell',
            'Gerir login My2N' => 'Manage My2N login',
            'Alterar destinatários' => 'Change recipients',
            'Configurar horários' => 'Configure schedules',
            'Executar rollback' => 'Run rollback',
            'Gerir utilizadores' => 'Manage users',
            'Gerir permissões' => 'Manage permissions',
            'Consultar auditoria' => 'View audit log',
            'Tarefas dos Quartos' => 'Room Tasks',
            'Atribuir itens a verificar' => 'Assign inspection items',
            'Consultar tarefas próprias' => 'View own tasks',
            'Gestão dos Espaços' => 'Space Management',
            'Gestão dos espaços' => 'Space management',
            'GESTÃO DOS ESPAÇOS' => 'SPACE MANAGEMENT',
            'Gerir áreas' => 'Manage areas',

            // Verification areas (technical identifiers retain the historical category name).
            'Áreas' => 'Areas',
            'Área' => 'Area',
            'Áreas da gestão dos espaços' => 'Space management areas',
            'Listas' => 'Lists',
            'Escolha a lista.' => 'Choose the list.',
            'Escolha uma lista válida.' => 'Choose a valid list.',
            'Área criada e adicionada ao menu.' => 'Area created and added to the menu.',
            'Nome da área atualizado.' => 'Area name updated.',
            'Área apagada com {lists} e {items}.' => 'Area deleted with {lists} and {items}.',
            'O nome da área deve ter entre 1 e 80 caracteres.' => 'The area name must contain between 1 and 80 characters.',
            'Área não encontrada.' => 'Area not found.',
            'Não foi possível criar a área.' => 'Could not create the area.',
            'Não foi possível guardar a área.' => 'Could not save the area.',
            'Já existe uma área com esse nome.' => 'An area with that name already exists.',
            'A tabela de áreas ainda não existe. Importe a migração 022_verification_categories.sql.' => 'The areas table does not exist yet. Import migration 022_verification_categories.sql.',
            'Importe migrations/022_verification_categories.sql para ativar a gestão de áreas.' => 'Import migrations/022_verification_categories.sql to enable area management.',
            'Não é possível apagar. Outro utilizador já atribuiu um item a um empregado.' => 'Cannot delete. Another user has already assigned an item to an employee.',
            'A área “{name}” contém {lists} e {items}. Todo esse conteúdo será apagado. Quer continuar?' => 'The “{name}” area contains {lists} and {items}. All of this content will be deleted. Do you want to continue?',
            'Quer apagar a área “{name}”?' => 'Do you want to delete the “{name}” area?',
            'Continuar e apagar' => 'Continue and delete',
            'Fechar' => 'Close',
            'Criar nova área' => 'Create new area',
            'Nome da área' => 'Area name',
            'Ex.: Escadas' => 'E.g. Stairs',
            'Criar área' => 'Create area',
            'Áreas existentes' => 'Existing areas',
            'Conteúdo' => 'Content',
            'Ações' => 'Actions',
            'Ainda não existem áreas.' => 'There are no areas yet.',
            'lista' => 'list',
            'listas' => 'lists',
            'itens' => 'items',
            'Com itens atribuídos' => 'Contains assigned items',
            'Apagar esta lista?' => 'Delete this list?',
            'Apagar este item?' => 'Delete this item?',
            'Apagar o intervalo “{name}”? Todas as atribuições deste intervalo também serão apagadas. Esta ação não pode ser anulada.' => 'Delete the “{name}” interval? All assignments in this interval will also be deleted. This action cannot be undone.',
            'Guardar alterações em {count} campainha(s)?' => 'Save changes to {count} bell(s)?',
        ];

        $coverage = require __DIR__ . '/SiteTranslationsCoverage.php';
        return array_replace($catalog, is_array($coverage) ? $coverage : []);
    }
}
