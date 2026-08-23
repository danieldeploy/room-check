<?php
declare(strict_types=1);

final class Translator
{
    private static bool $started = false;

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

    public static function translateOutput(string $output): string
    {
        $dictionary = self::dictionary();
        $serverDictionary = array_filter(
            $dictionary,
            static fn (string $key): bool => mb_strlen($key) >= 4,
            ARRAY_FILTER_USE_KEY
        );
        $output = strtr($output, $serverDictionary);

        if (stripos($output, '<html') === false || stripos($output, '</body>') === false) {
            return $output;
        }

        $json = json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $script = <<<'HTML'
<script>
(() => {
    const dictionary = __DICTIONARY__;
    const keys = Object.keys(dictionary).sort((a, b) => b.length - a.length);
    const translate = value => {
        if (typeof value !== 'string' || value === '') return value;
        const trimmed = value.trim();
        if (Object.prototype.hasOwnProperty.call(dictionary, trimmed)) {
            return value.replace(trimmed, dictionary[trimmed]);
        }
        let result = value;
        keys.forEach(key => {
            if (key.length >= 4 && result.includes(key)) result = result.split(key).join(dictionary[key]);
        });
        return result;
    };
    const translateNode = root => {
        if (!root) return;
        if (root.nodeType === Node.TEXT_NODE) {
            const parent = root.parentElement;
            if (parent && !['SCRIPT', 'STYLE', 'TEXTAREA'].includes(parent.tagName)) root.nodeValue = translate(root.nodeValue);
            return;
        }
        if (root.nodeType !== Node.ELEMENT_NODE) return;
        if (['SCRIPT', 'STYLE'].includes(root.tagName)) return;
        ['placeholder', 'title', 'aria-label'].forEach(attribute => {
            if (root.hasAttribute(attribute)) root.setAttribute(attribute, translate(root.getAttribute(attribute)));
        });
        root.childNodes.forEach(translateNode);
    };
    document.documentElement.lang = 'en';
    document.title = translate(document.title);
    translateNode(document.body);
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(translateNode)))
        .observe(document.body, { childList: true, subtree: true });
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
        return [
            'lang="pt"' => 'lang="en"',
            'Portal de Gestão' => 'Management Portal',
            'Entre com a sua conta de trabalho para aceder aos módulos autorizados.' => 'Sign in with your work account to access the authorised modules.',
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
            'Listas de itens' => 'Item lists',
            'LISTAS DE ITENS' => 'ITEM LISTS',
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
            'Problema a identificar' => 'Verification instructions',
            'PROBLEMA A IDENTIFICAR' => 'VERIFICATION INSTRUCTIONS',
            'Descreva a verificação...' => 'Describe the verification...',
            'Atribuído para' => 'Assigned for',
            'Selecionado para' => 'Selected for',
            'Guardado' => 'Saved',
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
            'Selecionar lista para editar' => 'Select list to edit',
            'Editar lista selecionada' => 'Edit selected list',
            'Nome da lista' => 'List name',
            'Área a verificar' => 'Area to verify',
            'Criar lista' => 'Create list',
            'Guardar lista' => 'Save list',
            'Apagar lista' => 'Delete list',
            'Lista base protegida — não pode ser apagada.' => 'Protected base list — it cannot be deleted.',
            'Item' => 'Item',
            'Descreva a verificação' => 'Describe the verification',
            'Ações' => 'Actions',
            'Guardar' => 'Save',
            'Apagar' => 'Delete',
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
            'Editar utilizador' => 'Edit user',
            'Nova password' => 'New password',
            'Confirmar' => 'Confirm',
            'Cancelar' => 'Cancel',
            'Autenticação necessária.' => 'Authentication required.',
            'Não tem permissão para esta ação.' => 'You do not have permission to perform this action.',
            'Dados carregados' => 'Data loaded',
            'Carregando...' => 'Loading...',
            'Nenhum resultado.' => 'No results.',
            'Sim' => 'Yes',
            'Não' => 'No',
        ];
    }
}
