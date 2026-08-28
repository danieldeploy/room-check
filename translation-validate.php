<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/I18n/ContentTranslator.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $pdo = database();
    Auth::requireLogin($pdo, $config);
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true, 512, JSON_THROW_ON_ERROR);
    Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);

    $fields = $payload['fields'] ?? null;
    if (!is_array($fields) || count($fields) > 100) {
        throw new InvalidArgumentException('Invalid translation validation request.');
    }

    $translator = new ContentTranslator($pdo, $config['translation'] ?? []);
    $sourceLanguage = Translator::locale();
    $results = [];

    foreach ($fields as $field) {
        if (!is_array($field)) continue;
        $fieldKey = trim((string) ($field['fieldKey'] ?? ''));
        $text = trim((string) ($field['text'] ?? ''));
        if (mb_strlen($text) > 5000) {
            throw new InvalidArgumentException('Text is too long.');
        }

        try {
            $versions = $translator->versions($text, $sourceLanguage);
            $results[] = [
                'fieldKey' => $fieldKey,
                'sourceConclusion' => (string) ($versions['sourceConclusion'] ?? 'ambiguous'),
                'translationConclusion' => (string) ($versions['translationConclusion'] ?? 'ambiguous'),
                'message' => (string) ($versions['validationMessage'] ?? ''),
            ];
        } catch (LanguageValidationException $exception) {
            throw $exception->withField($fieldKey);
        }
    }

    jsonResponse(['ok' => true, 'valid' => true, 'fields' => $results]);
} catch (LanguageValidationException $exception) {
    jsonResponse([
        'ok' => false,
        'validation' => true,
        'error' => $exception->getMessage(),
        'fieldKey' => $exception->fieldKey,
    ], 422);
} catch (JsonException | InvalidArgumentException $exception) {
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403, 429], true)) {
        jsonResponse(['ok' => false, 'error' => $exception->getMessage()], $exception->getCode());
    }
    error_log((string) $exception);
    jsonResponse(['ok' => false, 'error' => 'Translation validation failed.'], 500);
}
