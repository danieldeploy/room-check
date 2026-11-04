<?php
declare(strict_types=1);

final class My2NCredentialStore
{
    public function __construct(private readonly string $file)
    {
    }

    public function isConfigured(): bool
    {
        try {
            $credentials = $this->read();
            return $credentials['identifier'] !== '' && $credentials['password'] !== '';
        } catch (Throwable) {
            return false;
        }
    }

    public function maskedIdentifier(): ?string
    {
        try {
            $identifier = $this->read()['identifier'];
        } catch (Throwable) {
            return null;
        }

        if (str_contains($identifier, '@')) {
            [$name, $domain] = explode('@', $identifier, 2);
            $visible = mb_substr($name, 0, min(2, mb_strlen($name)));
            return $visible . str_repeat('•', max(3, mb_strlen($name) - mb_strlen($visible))) . '@' . $domain;
        }

        $visible = mb_substr($identifier, 0, min(3, mb_strlen($identifier)));
        return $visible . str_repeat('•', max(3, mb_strlen($identifier) - mb_strlen($visible)));
    }

    public function save(string $identifier, string $password): void
    {
        $identifier = trim($identifier);
        if ($identifier === '' || mb_strlen($identifier) > 190) {
            throw new InvalidArgumentException('Indique um login My2N válido.');
        }
        if ($password === '' || strlen($password) > 2048) {
            throw new InvalidArgumentException('Indique uma password My2N válida.');
        }

        $path = $this->validatedPath(true);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório privado My2N.', 503);
        }
        @chmod($directory, 0700);

        $temporary = tempnam($directory, '.my2n-');
        if ($temporary === false) {
            throw new RuntimeException('Não foi possível preparar o ficheiro privado My2N.', 503);
        }

        try {
            $payload = json_encode([
                'identifier' => $identifier,
                'password' => $password,
                'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $payload . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível guardar as credenciais My2N.', 503);
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Não foi possível ativar as credenciais My2N.', 503);
            }
            @chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function read(): array
    {
        $path = $this->validatedPath(false);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Credenciais My2N não configuradas no servidor.', 503);
        }

        $decoded = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $identifier = trim((string) ($decoded['identifier'] ?? ''));
        $password = (string) ($decoded['password'] ?? '');
        if ($identifier === '' || $password === '') {
            throw new RuntimeException('Credenciais My2N incompletas no servidor.', 503);
        }

        return ['identifier' => $identifier, 'password' => $password];
    }

    private function validatedPath(bool $allowMissing): string
    {
        $file = trim($this->file);
        if ($file === '' || !str_starts_with($file, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('O caminho privado das credenciais My2N não está configurado.', 503);
        }

        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $resolvedFile = realpath($file);
        $resolvedParent = realpath(dirname($file));
        $candidate = $resolvedFile !== false ? $resolvedFile : ($resolvedParent !== false
            ? $resolvedParent . DIRECTORY_SEPARATOR . basename($file)
            : $file);

        if ($documentRoot !== false && ($candidate === $documentRoot
            || str_starts_with($candidate, $documentRoot . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('As credenciais My2N não podem ficar em public_html.', 503);
        }
        if (!$allowMissing && $resolvedFile === false) {
            throw new RuntimeException('Credenciais My2N não configuradas no servidor.', 503);
        }

        return $candidate;
    }
}
