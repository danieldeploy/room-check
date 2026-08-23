<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/I18n/Translator.php';

final class Auth
{
    public const PERMISSION_ROOM_CHECK_VIEW = 'room_check.view';
    public const PERMISSION_ROOM_CHECK_EDIT = 'room_check.edit';
    public const PERMISSION_ZKACCESS_VIEW = 'zkaccess.view';
    public const PERMISSION_ZKACCESS_CONFIGURE = 'zkaccess.configure';
    public const PERMISSION_USERS_MANAGE = 'users.manage';
    public const PERMISSION_PERMISSIONS_MANAGE = 'permissions.manage';
    public const PERMISSION_MY2N_VIEW = 'my2n.view';
    public const PERMISSION_MY2N_CREDENTIALS = 'my2n.credentials';
    public const PERMISSION_MY2N_CONTROL = 'my2n.control';
    public const PERMISSION_MY2N_SCHEDULE = 'my2n.schedule';
    public const PERMISSION_MY2N_ROLLBACK = 'my2n.rollback';
    public const PERMISSION_AUDIT_VIEW = 'audit.view';
    public const PERMISSION_TASK_ASSIGN = 'room_tasks.assign';
    public const PERMISSION_TASK_VIEW_OWN = 'room_tasks.view_own';

    public const PERMISSIONS = [
        self::PERMISSION_ROOM_CHECK_VIEW => ['group' => 'Gestão de Quartos', 'label' => 'Consultar quartos'],
        self::PERMISSION_ROOM_CHECK_EDIT => ['group' => 'Gestão de Quartos', 'label' => 'Alterar quartos'],
        self::PERMISSION_ZKACCESS_VIEW => ['group' => 'ZKAccess', 'label' => 'Consultar automação'],
        self::PERMISSION_ZKACCESS_CONFIGURE => ['group' => 'ZKAccess', 'label' => 'Configurar automação'],
        self::PERMISSION_MY2N_VIEW => ['group' => 'My2N', 'label' => 'Consultar campainha'],
        self::PERMISSION_MY2N_CREDENTIALS => ['group' => 'My2N', 'label' => 'Gerir login My2N'],
        self::PERMISSION_MY2N_CONTROL => ['group' => 'My2N', 'label' => 'Alterar destinatários'],
        self::PERMISSION_MY2N_SCHEDULE => ['group' => 'My2N', 'label' => 'Configurar horários'],
        self::PERMISSION_MY2N_ROLLBACK => ['group' => 'My2N', 'label' => 'Executar rollback'],
        self::PERMISSION_USERS_MANAGE => ['group' => 'Administração', 'label' => 'Gerir utilizadores'],
        self::PERMISSION_PERMISSIONS_MANAGE => ['group' => 'Administração', 'label' => 'Gerir permissões'],
        self::PERMISSION_AUDIT_VIEW => ['group' => 'Administração', 'label' => 'Consultar auditoria'],
        self::PERMISSION_TASK_ASSIGN => ['group' => 'Tarefas dos Quartos', 'label' => 'Atribuir itens a verificar'],
        self::PERMISSION_TASK_VIEW_OWN => ['group' => 'Tarefas dos Quartos', 'label' => 'Consultar tarefas próprias'],
    ];

    public const ROLES = [
        'gerente' => 'Gerente',
        'governanta' => 'Governanta',
        'tecnico_manutencao' => 'Técnico de Manutenção',
        'empregada_andares' => 'Empregada de Andares',
    ];

    public const DEFAULT_ROLE_PERMISSIONS = [
        'gerente' => [
            self::PERMISSION_ROOM_CHECK_VIEW,
            self::PERMISSION_ROOM_CHECK_EDIT,
            self::PERMISSION_ZKACCESS_VIEW,
            self::PERMISSION_ZKACCESS_CONFIGURE,
            self::PERMISSION_USERS_MANAGE,
            self::PERMISSION_PERMISSIONS_MANAGE,
            self::PERMISSION_MY2N_VIEW,
            self::PERMISSION_MY2N_CREDENTIALS,
            self::PERMISSION_MY2N_CONTROL,
            self::PERMISSION_MY2N_SCHEDULE,
            self::PERMISSION_MY2N_ROLLBACK,
            self::PERMISSION_AUDIT_VIEW,
            self::PERMISSION_TASK_ASSIGN,
        ],
        'governanta' => [
            self::PERMISSION_ROOM_CHECK_VIEW,
            self::PERMISSION_ROOM_CHECK_EDIT,
            self::PERMISSION_MY2N_VIEW,
            self::PERMISSION_TASK_ASSIGN,
        ],
        'tecnico_manutencao' => [
            self::PERMISSION_ROOM_CHECK_VIEW,
            self::PERMISSION_ROOM_CHECK_EDIT,
            self::PERMISSION_ZKACCESS_VIEW,
            self::PERMISSION_MY2N_VIEW,
        ],
        'empregada_andares' => [
            self::PERMISSION_ROOM_CHECK_VIEW,
            self::PERMISSION_ROOM_CHECK_EDIT,
            self::PERMISSION_TASK_VIEW_OWN,
        ],
    ];

    public const LOCKED_ROLE_PERMISSIONS = [
        'gerente' => [
            self::PERMISSION_USERS_MANAGE,
            self::PERMISSION_PERMISSIONS_MANAGE,
            self::PERMISSION_MY2N_CREDENTIALS,
        ],
    ];

    public const PERMISSION_DEPENDENCIES = [
        self::PERMISSION_ROOM_CHECK_EDIT => [self::PERMISSION_ROOM_CHECK_VIEW],
        self::PERMISSION_ZKACCESS_CONFIGURE => [self::PERMISSION_ZKACCESS_VIEW],
        self::PERMISSION_MY2N_CREDENTIALS => [self::PERMISSION_MY2N_VIEW],
        self::PERMISSION_MY2N_CONTROL => [self::PERMISSION_MY2N_VIEW],
        self::PERMISSION_MY2N_SCHEDULE => [self::PERMISSION_MY2N_VIEW],
        self::PERMISSION_MY2N_ROLLBACK => [self::PERMISSION_MY2N_VIEW],
    ];

    private static array $permissionCache = [];
    private static ?bool $permissionStorageAvailable = null;

    public static function startSession(array $config = []): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }

        $idleLimit = (int) ($config['auth']['session_idle_seconds'] ?? 28800);
        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        if ($lastActivity > 0 && time() - $lastActivity > $idleLimit) {
            self::logout();
            session_start();
        }
        $_SESSION['last_activity'] = time();
        Translator::boot();
    }

    public static function attempt(PDO $pdo, string $username, string $password, array $config, ?string $language = null): bool
    {
        self::startSession($config);
        $username = trim($username);
        $usernameKey = hash('sha256', mb_strtolower($username));
        $ipKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'));

        self::assertNotRateLimited($pdo, $usernameKey, $ipKey);

        $statement = $pdo->prepare(
            'SELECT id, username, display_name, password_hash, role, is_active, preferred_language
             FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = is_array($user)
            ? password_verify($password, (string) $user['password_hash'])
            : password_verify($password, $dummyHash) && false;

        if (!$valid || (int) ($user['is_active'] ?? 0) !== 1) {
            self::recordFailedAttempt($pdo, $usernameKey, $ipKey);
            self::audit($pdo, null, 'login_failed', ['username_key' => $usernameKey]);
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }

        $pdo->prepare('DELETE FROM login_attempts WHERE username_key = :username AND ip_key = :ip')
            ->execute(['username' => $usernameKey, 'ip' => $ipKey]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        $language = in_array($language, ['pt', 'en'], true) ? $language : (string) ($user['preferred_language'] ?? 'pt');
        Translator::setLocale($language);
        $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(), preferred_language = :language WHERE id = :id')
            ->execute(['language' => $language, 'id' => $user['id']]);
        self::audit($pdo, (int) $user['id'], 'login_success');
        return true;
    }

    public static function currentUser(PDO $pdo, array $config): ?array
    {
        self::startSession($config);
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT id, username, display_name, role, is_active, last_login_at, preferred_language
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user) || (int) $user['is_active'] !== 1 || !isset(self::ROLES[$user['role']])) {
            self::logout();
            return null;
        }

        return $user;
    }

    public static function requireLogin(PDO $pdo, array $config): array
    {
        $user = self::currentUser($pdo, $config);
        if ($user === null) {
            throw new RuntimeException('Autenticação necessária.', 401);
        }
        return $user;
    }

    public static function requirePermission(PDO $pdo, array $config, string $permission): array
    {
        $user = self::requireLogin($pdo, $config);
        if (!self::hasPermission($pdo, $user, $permission)) {
            throw new RuntimeException('Não tem permissão para esta ação.', 403);
        }
        return $user;
    }

    public static function hasPermission(PDO $pdo, array $user, string $permission): bool
    {
        return self::roleHasPermission($pdo, (string) ($user['role'] ?? ''), $permission);
    }

    public static function roleHasPermission(PDO $pdo, string $role, string $permission): bool
    {
        if (!isset(self::PERMISSIONS[$permission])) {
            return false;
        }
        return in_array($permission, self::permissionsForRole($pdo, $role), true);
    }

    public static function permissionsForRole(PDO $pdo, string $role): array
    {
        if (!isset(self::ROLES[$role])) {
            return [];
        }
        if (isset(self::$permissionCache[$role])) {
            return self::$permissionCache[$role];
        }

        $permissions = self::DEFAULT_ROLE_PERMISSIONS[$role] ?? [];
        if (self::permissionStorageAvailable($pdo)) {
            $statement = $pdo->prepare(
                'SELECT permission FROM role_permissions WHERE role = :role ORDER BY permission'
            );
            $statement->execute(['role' => $role]);
            $permissions = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        }

        $permissions = self::normalizePermissions(array_merge(
            $permissions,
            self::LOCKED_ROLE_PERMISSIONS[$role] ?? []
        ));
        self::$permissionCache[$role] = $permissions;
        return $permissions;
    }

    public static function normalizePermissions(array $permissions): array
    {
        $normalized = [];
        foreach ($permissions as $permission) {
            $permission = (string) $permission;
            if (!isset(self::PERMISSIONS[$permission])) {
                continue;
            }
            $normalized[] = $permission;
            foreach (self::PERMISSION_DEPENDENCIES[$permission] ?? [] as $dependency) {
                $normalized[] = $dependency;
            }
        }
        return array_values(array_unique($normalized));
    }

    public static function defaultRoleHasPermission(string $role, string $permission): bool
    {
        return isset(self::PERMISSIONS[$permission])
            && in_array($permission, self::DEFAULT_ROLE_PERMISSIONS[$role] ?? [], true);
    }

    public static function permissionStorageAvailable(PDO $pdo): bool
    {
        if (self::$permissionStorageAvailable !== null) {
            return self::$permissionStorageAvailable;
        }
        try {
            $pdo->query('SELECT 1 FROM role_permissions LIMIT 1');
            self::$permissionStorageAvailable = true;
        } catch (PDOException) {
            self::$permissionStorageAvailable = false;
        }
        return self::$permissionStorageAvailable;
    }

    public static function resetPermissionCache(): void
    {
        self::$permissionCache = [];
        self::$permissionStorageAvailable = null;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function validateRole(string $role): string
    {
        if (!isset(self::ROLES[$role])) {
            throw new InvalidArgumentException('Perfil inválido.');
        }
        return $role;
    }

    public static function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new InvalidArgumentException('A password deve ter pelo menos 12 caracteres.');
        }
    }

    public static function audit(PDO $pdo, ?int $actorId, string $action, ?array $details = null): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO auth_audit_log (actor_user_id, action, details_json, ip_key)
             VALUES (:actor, :action, :details, :ip)'
        );
        $statement->execute([
            'actor' => $actorId,
            'action' => $action,
            'details' => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'ip' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')),
        ]);
    }

    private static function assertNotRateLimited(PDO $pdo, string $usernameKey, string $ipKey): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username_key = :username AND ip_key = :ip
               AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)'
        );
        $statement->execute(['username' => $usernameKey, 'ip' => $ipKey]);
        if ((int) $statement->fetchColumn() >= 5) {
            throw new RuntimeException('Demasiadas tentativas. Aguarde 15 minutos.', 429);
        }
    }

    private static function recordFailedAttempt(PDO $pdo, string $usernameKey, string $ipKey): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO login_attempts (username_key, ip_key) VALUES (:username, :ip)'
        );
        $statement->execute(['username' => $usernameKey, 'ip' => $ipKey]);
    }
}
