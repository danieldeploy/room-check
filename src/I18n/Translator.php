<?php
declare(strict_types=1);

final class Translator
{
    private static bool $started = false;
    private static array $dynamicDictionary = [];

    public static function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $requested = strtolower(trim((string) ($_POST['language'] ?? '')));
        if (in_array($requested, ['pt', 'en'], true)) {
            self::setLocale($requested);
        } elseif (!isset($_SESSION['locale']) || !in_array($_SESSION['locale'], ['pt', 'en'], true)) {
            $remembered = strtolower(trim((string) ($_COOKIE['room_check_language'] ?? '')));
            $_SESSION['locale'] = in_array($remembered, ['pt', 'en'], true) ? $remembered : 'pt';
        }

        if (self::locale() === 'en' && PHP_SAPI !== 'cli' && !self::$started) {
            self::$started = true;
            ob_start([self::class, 'translateOutput']);
        }
    }

    public static function locale(): string
    {
        return (string) ($_SESSION['locale'] ?? 'pt');
    }

    public static function setLocale(string $locale, bool $remember = true): void
    {
        $locale = strtolower(trim($locale));
        if (!in_array($locale, ['pt', 'en'], true)) {
            $locale = 'pt';
        }
        $_SESSION['locale'] = $locale;
        if ($remember && PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie('room_check_language', $locale, [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function localized(?string $portuguese, ?string $english): string
    {
        $portuguese = trim((string) $portuguese);
        $english = trim((string) $english);
        return self::locale() === 'en'
            ? ($english !== '' ? $english : $portuguese)
            : ($portuguese !== '' ? $portuguese : $english);
    }

    public static function registerDynamic(?string $portuguese, ?string $english): void
    {
        $portuguese = trim((string) $portuguese);
        $english = trim((string) $english);
        if ($portuguese === '' || $english === '' || $portuguese === $english) {
            return;
        }
        self::$dynamicDictionary[$portuguese] = $english;
    }

    public static function translateOutput(string $output): string
    {
        // API/JSON responses and script data must keep canonical database values unchanged.
        // Translation is applied only to visible DOM content in HTML pages.
        if (stripos($output, '<html') === false || stripos($output, '</body>') === false) {
            return $output;
        }

        $dictionary = self::dictionary();
        $json = json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $script = <<<'HTML'
<script>
(() => {
    const dictionary = __DICTIONARY__;
    const keys = Object.keys(dictionary).sort((a, b) => b.length - a.length);
    const escapePattern = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const templateKeys = keys.filter(key => key.includes('{value}'));
    const staticKeys = keys.filter(key => !key.includes('{value}'));
    const templatePatterns = templateKeys.map(key => ({
        key,
        regex: new RegExp('^' + key.split('{value}').map(escapePattern).join('(.*?)') + '$', 's')
    }));
    const partialKeys = staticKeys.filter(key => key.length >= 4 || /^\s.+\s$/.test(key));
    const partialPattern = partialKeys.length
        ? new RegExp(partialKeys.map(escapePattern).join('|'), 'g')
        : null;
    const fillTemplate = (template, values) => {
        let index = 0;
        return template.replace(/\{value\}/g, () => values[index++] ?? '');
    };
    const translate = value => {
        if (typeof value !== 'string' || value === '') return value;
        const trimmed = value.trim();
        if (Object.prototype.hasOwnProperty.call(dictionary, trimmed)) {
            return value.replace(trimmed, dictionary[trimmed]);
        }
        for (const template of templatePatterns) {
            const match = trimmed.match(template.regex);
            if (!match) continue;
            const translated = fillTemplate(dictionary[template.key], match.slice(1));
            return value.replace(trimmed, translated);
        }
        return partialPattern
            ? value.replace(partialPattern, match => dictionary[match] ?? match)
            : value;
    };
    const skippedTags = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'CODE', 'PRE', 'KBD', 'SAMP']);
    const isSkippedElement = element => Boolean(
        element && (skippedTags.has(element.tagName) || element.closest('[data-i18n-skip]'))
    );
    const translateNode = root => {
        if (!root) return;
        if (root.nodeType === Node.TEXT_NODE) {
            const parent = root.parentElement;
            if (parent && !isSkippedElement(parent)) {
                const current = root.nodeValue || '';
                const translated = translate(current);
                if (translated !== current) root.nodeValue = translated;
            }
            return;
        }
        if (root.nodeType !== Node.ELEMENT_NODE) return;
        if (isSkippedElement(root)) return;
        ['placeholder', 'title', 'aria-label'].forEach(attribute => {
            if (!root.hasAttribute(attribute)) return;
            const current = root.getAttribute(attribute);
            const translated = translate(current);
            if (translated !== current) root.setAttribute(attribute, translated);
        });
        root.childNodes.forEach(translateNode);
    };
    document.documentElement.lang = 'en';
    document.title = translate(document.title);
    translateNode(document.body);
    new MutationObserver(records => records.forEach(record => {
        if (record.type === 'characterData') translateNode(record.target);
        if (record.type === 'attributes') translateNode(record.target);
        record.addedNodes.forEach(translateNode);
    })).observe(document.body, {
        childList: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['placeholder', 'title', 'aria-label'],
        subtree: true
    });
    const nativeAlert = window.alert.bind(window);
    const nativeConfirm = window.confirm.bind(window);
    window.alert = message => nativeAlert(translate(String(message)));
    window.confirm = message => nativeConfirm(translate(String(message)));
    window.translateAppText = translate;
})();
</script>
HTML;
        $script = str_replace('__DICTIONARY__', $json ?: '{}', $script);
        return str_ireplace('</body>', $script . "\n</body>", $output);
    }

    private static function dictionary(): array
    {
        $dictionary = [
            'lang="pt"' => 'lang="en"',
            'Entre com a sua conta de trabalho para aceder aos módulos autorizados.' => 'Sign in with your work account to access the authorised modules.',
            'Verificação dos quartos do City Center Guest House e Welcome Guest House.' => 'Room inspections for City Center Guest House and Welcome Guest House.',
            'Consulte os itens que a Governanta lhe atribuiu.' => 'View the items assigned to you by the Housekeeping Manager.',
            'Estado dos telemóveis e configuração dos destinatários da Welcome Bell.' => 'Mobile status and Welcome Bell recipient settings.',
            'Utilizador ou password inválidos.' => 'Invalid username or password.',
            'Utilizador' => 'Username',
            'Password' => 'Password',
            'Idioma' => 'Language',
            'Entrar' => 'Sign in',
            'Sair' => 'Sign out',
            'Portal' => 'Portal',
            'Gerente' => 'Manager',
            'Governanta' => 'Housekeeping Manager',
            'Técnico de Manutenção' => 'Maintenance Technician',
            'Empregada de Andares' => 'Housekeeper',
            'Gestão dos Espaços' => 'Space Management',
            'GESTÃO DOS ESPAÇOS' => 'SPACE MANAGEMENT',
            'Quartos' => 'Rooms',
            'QUARTOS' => 'ROOMS',
            'Casas de banho comuns' => 'Shared bathrooms',
            'CASAS DE BANHO COMUNS' => 'SHARED BATHROOMS',
            'Corredores' => 'Corridors',
            'CORREDORES' => 'CORRIDORS',
            'Cozinhas' => 'Kitchens',
            'COZINHAS' => 'KITCHENS',
            'Terraços' => 'Terraces',
            'TERRAÇOS' => 'TERRACES',
            'Listas' => 'Lists',
            'LISTAS' => 'LISTS',
            'Criar intervalo de verificação' => 'Create verification period',
            'Editar intervalo de verificação' => 'Edit verification period',
            'Intervalo a editar' => 'Period to edit',
            'Escolher intervalo' => 'Choose period',
            'Criar intervalo' => 'Create period',
            'Guardar intervalo' => 'Save period',
            'Apagar intervalo' => 'Delete period',
            'Nome' => 'Name',
            'Data inicial' => 'Start date',
            'Data final' => 'End date',
            'As datas só podem ser reduzidas até continuarem a incluir todos os itens já atribuídos.' => 'Dates can only be shortened while still including every assigned item.',
            'Alojamento' => 'Property',
            'Intervalo' => 'Period',
            'Quarto' => 'Room',
            'Atribuir' => 'Assign',
            'Data da verificação' => 'Verification date',
            'Escolher empregada' => 'Choose housekeeper',
            'Escolher alojamento' => 'Choose property',
            'Escolher quarto' => 'Choose room',
            'Escolher lista' => 'Choose list',
            'Lista' => 'List',
            'Sem listas nesta área' => 'No lists in this area',
            'Intervalo de verificação' => 'Verification period',
            'Ex.: Verificação semanal' => 'E.g. Weekly inspection',
            'Problema a identificar' => 'Verification instructions',
            'PROBLEMA A IDENTIFICAR' => 'VERIFICATION INSTRUCTIONS',
            'Descreva a verificação...' => 'Describe the verification...',
            'Atribuído para' => 'Assigned for',
            'Atribuir todos os itens' => 'Assign all items',
            'Selecionar todos os itens' => 'Select all items',
            'Atribuir itens a verificar' => 'Assign inspection items',
            'Os meus itens a verificar' => 'My inspection items',
            'Tarefas dos Quartos' => 'Room Tasks',
            'Empregada responsável' => 'Assigned housekeeper',
            'Guardar atribuições' => 'Save assignments',
            'Sem empregadas ativas' => 'No active housekeepers',
            'Crie ou ative uma conta com o perfil Empregada de Andares antes de atribuir itens.' => 'Create or activate a Housekeeper account before assigning items.',
            'Atribuições inválidas.' => 'Invalid assignments.',
            'Atribuições guardadas.' => 'Assignments saved.',
            'Selecionado para' => 'Selected for',
            'Guardado' => 'Saved',
            'As alterações são guardadas automaticamente' => 'Changes are saved automatically',
            'Apenas consulta' => 'View only',
            'Dados carregados' => 'Data loaded',
            'Erro ao guardar.' => 'Could not save.',
            'Tentar novamente' => 'Try again',
            'Item selecionado' => 'Selected item',
            'Itens atribuídos' => 'Assigned items',
            'Marcar concluído' => 'Mark complete',
            'Abrir espaço' => 'Open space',
            'Instruções da verificação' => 'Verification instructions',
            'INSTRUÇÕES DA VERIFICAÇÃO' => 'VERIFICATION INSTRUCTIONS',
            'Dia com itens atribuídos' => 'Day with assigned items',
            'Itens de' => 'Items for',
            'item' => 'item',
            'itens' => 'items',
            'Estado' => 'Status',
            'Problema' => 'Issue',
            'Concluído' => 'Completed',
            'Sem itens atribuídos para esta data.' => 'No items assigned for this date.',
            'Hoje' => 'Today',
            'Anterior' => 'Previous',
            'Seguinte' => 'Next',
            'Janeiro' => 'January', 'Fevereiro' => 'February', 'Março' => 'March',
            'Abril' => 'April', 'Maio' => 'May', 'Junho' => 'June', 'Julho' => 'July',
            'Agosto' => 'August', 'Setembro' => 'September', 'Outubro' => 'October',
            'Novembro' => 'November', 'Dezembro' => 'December',
            'Criar nova lista' => 'Create new list',
            'Nova lista' => 'New list',
            'Nome da nova lista' => 'New list name',
            'Selecionar lista para editar' => 'Select list to edit',
            'Editar lista selecionada' => 'Edit selected list',
            'Nome da lista' => 'List name',
            'Área a verificar' => 'Area to verify',
            'Criar lista' => 'Create list',
            'Guardar lista' => 'Save list',
            'Apagar lista' => 'Delete list',
            'Lista base protegida — não pode ser apagada.' => 'Protected base list — it cannot be deleted.',
            'Lista base protegida' => 'Protected base list',
            'Lista criada.' => 'List created.',
            'Lista atualizada.' => 'List updated.',
            'Lista apagada.' => 'List deleted.',
            'Apagar esta lista?' => 'Delete this list?',
            'Apagar este item?' => 'Delete this item?',
            'Item' => 'Item',
            'Descreva a verificação' => 'Describe the verification',
            'Descreva a verificação…' => 'Describe the verification…',
            'Descreva o problema…' => 'Describe the issue…',
            'Descrição da verificação' => 'Verification description',
            'Novo item' => 'New item',
            'Nome do item' => 'Item name',
            'Adicionar item' => 'Add item',
            'Esta lista ainda não tem itens.' => 'This list does not have any items yet.',
            'Ações' => 'Actions',
            'Guardar' => 'Save',
            'Apagar' => 'Delete',
            'Check Casas Banho Comuns' => 'Shared Bathrooms Check',
            'CHECK CASAS BANHO COMUNS' => 'SHARED BATHROOMS CHECK',
            'Check Corredores' => 'Corridors Check',
            'CHECK CORREDORES' => 'CORRIDORS CHECK',
            'Check Cozinhas' => 'Kitchens Check',
            'CHECK COZINHAS' => 'KITCHENS CHECK',
            'Check Terraços' => 'Terraces Check',
            'CHECK TERRAÇOS' => 'TERRACES CHECK',
            'Item teste' => 'Test item',
            'ITEM TESTE' => 'TEST ITEM',
            'Espelho' => 'Mirror',
            'ESPELHO' => 'MIRROR',
            'Extintores' => 'Fire extinguishers',
            'EXTINTORES' => 'FIRE EXTINGUISHERS',
            'Administração' => 'Administration',
            'Utilizadores' => 'Users',
            'Permissões' => 'Permissions',
            'Auditoria' => 'Audit log',
            'Email' => 'Email',
            'Telemóvel' => 'Mobile phone',
            'Perfil' => 'Role',
            'Ativo' => 'Active',
            'Inativo' => 'Inactive',
            'Criar utilizador' => 'Create user',
            'Criar conta' => 'Create account',
            'Guardar dados' => 'Save details',
            'Editar utilizador' => 'Edit user',
            'Nova password' => 'New password',
            'Confirmar' => 'Confirm',
            'Cancelar' => 'Cancel',
            'Autenticação necessária.' => 'Authentication required.',
            'Não tem permissão para esta ação.' => 'You do not have permission to perform this action.',
            'Alojamento inválido.' => 'Invalid property.',
            'Intervalo de verificação não encontrado.' => 'Verification period not found.',
            'Escolha uma Empregada de Andares ativa.' => 'Choose an active Housekeeper.',
            'Estado inválido em' => 'Invalid status for',
            'Problema identificado:' => 'Issue identified:',
            'Intervalo criado' => 'Period created',
            'Intervalo atualizado' => 'Period updated',
            'Intervalo apagado' => 'Period deleted',
            'O QUE PRETENDE GERIR?' => 'WHAT WOULD YOU LIKE TO MANAGE?',
            'O que pretende gerir?' => 'What would you like to manage?',
            'OPERAÇÕES DE VERIFICAÇÃO' => 'INSPECTION OPERATIONS',
            'Operações de verificação' => 'Inspection operations',
            'Disponível' => 'Available',
            'Consulta read-only' => 'Read-only',
            'Abrir módulo' => 'Open module',
            'Campainha' => 'Doorbell',
            'ALERTA WHATSAPP' => 'WHATSAPP REMINDER',
            'Alerta WhatsApp' => 'WhatsApp reminder',
            'Enviar alerta WhatsApp' => 'Send WhatsApp reminder',
            'Hora do alerta WhatsApp' => 'WhatsApp reminder time',
            'CHECK GERAL QUARTOS' => 'GENERAL ROOM CHECK',
            'Check Geral Quartos' => 'General Room Check',
            'CHECK GERAL' => 'GENERAL CHECK',
            'Check Geral' => 'General Check',
            'Espelho' => 'Mirror',
            'Lampadas' => 'Lights',
            'Lâmpadas' => 'Lights',
            'Armarios' => 'Wardrobes',
            'Armários' => 'Wardrobes',
            'Cabeceiras' => 'Headboards',
            'Ventoinhas' => 'Fans',
            'Cortinas' => 'Curtains',
            'Fichas' => 'Power sockets',
            'Camas' => 'Beds',
            'Luzes' => 'Lights',
            'Portas' => 'Doors',
            'Fechaduras' => 'Locks',
            'Janelas' => 'Windows',
            'Chaves' => 'Keys',
            'Placa de Saida' => 'Exit sign',
            'Placa de Saída' => 'Exit sign',
            'Caixote de Lixo' => 'Waste bin',
            'Paredes' => 'Walls',
            'Verificar se está limpo e sem danos.' => 'Check that it is clean and undamaged.',
            'Confirmar que todas as lâmpadas acendem.' => 'Confirm that all lights turn on.',
            'Verificar a limpeza e o funcionamento das portas.' => 'Check cleanliness and that the doors work correctly.',
            'Confirmar que estão limpas e bem fixas.' => 'Confirm that they are clean and securely fitted.',
            'Testar o funcionamento e verificar a limpeza.' => 'Test operation and check cleanliness.',
            'Verificar a limpeza e o movimento das cortinas.' => 'Check cleanliness and movement of the curtains.',
            'Confirmar que estão fixas e sem danos visíveis.' => 'Confirm that they are secure and have no visible damage.',
            'Verificar a estabilidade e o estado das camas.' => 'Check the stability and condition of the beds.',
            'Testar todas as luzes do quarto.' => 'Test all room lights.',
            'Confirmar que abrem e fecham corretamente.' => 'Confirm that they open and close correctly.',
            'Testar a fechadura e o trinco da porta.' => 'Test the door lock and latch.',
            'Verificar abertura, fecho e estado dos vidros.' => 'Check opening, closing and the condition of the glass.',
            'Confirmar que as chaves estão disponíveis e funcionam.' => 'Confirm that the keys are available and work.',
            'Verificar se está visível e bem fixada.' => 'Confirm that it is visible and securely fitted.',
            'Confirmar que está limpo e em bom estado.' => 'Confirm that it is clean and in good condition.',
            'Verificar manchas, fissuras ou danos.' => 'Check for stains, cracks or damage.',
            'Confirmar a quantidade e o estado dos cabides.' => 'Confirm the number and condition of the hangers.',
            ' a ' => ' to ',
            'Carregando...' => 'Loading...',
            'Nenhum resultado.' => 'No results.',
            'Sim' => 'Yes',
            'Não' => 'No',
        ];
        return array_replace($dictionary, self::$dynamicDictionary);
    }
}
