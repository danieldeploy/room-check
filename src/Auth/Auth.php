<?php
declare(strict_types=1);

final class Auth
{
    public const ROLES = [
        'gerente' => 'Gerente',
        'governanta' => 'Governanta',
        'tecnico_manutencao' => 'Técnico Manutenção',
        'empregada_andares' => 'Empregada de Andares',
    ];

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
    }

    public static function attempt(PDO $pdo, string $username, string $password, array $config): bool
    {
        self::startSession($config);
        $username = trim($username);
        $usernameKey = hash('sha256', mb_strtolower($username));
        $ipKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'));

        self::assertNotRateLimited($pdo, $usernameKey, $ipKey);

        $statement = $pdo->prepare(
            'SELECT id, username, display_name, password_hash, role, is_active
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
        $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $user['id']]);
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
            'SELECT id, username, display_name, role, is_active, last_login_at
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

    public static function requireRole(PDO $pdo, array $config, array $roles): array
    {
        $user = self::requireLogin($pdo, $config);
        if (!in_array($user['role'], $roles, true)) {
            throw new RuntimeException('Não tem permissão para esta área.', 403);
        }
        return $user;
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
