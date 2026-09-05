<?php
declare(strict_types=1);

final class TranslationQuotaManager
{
    private const DEFAULT_DAILY_LIMIT = 15500;
    private const DEFAULT_QUOTA_TIMEZONE = 'America/Los_Angeles';
    private const DEFAULT_DISPLAY_TIMEZONE = 'Europe/Lisbon';

    public function __construct(private PDO $pdo, private array $config) {}

    public function reserve(int $characters, string $sourceLanguage): void
    {
        if ($characters < 1 || $this->dailyLimit() < 1) {
            return;
        }

        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Translation quota must be reserved before opening a data transaction.');
        }

        $period = $this->currentPeriod();
        $limit = $this->dailyLimit();

        try {
            $this->pdo->beginTransaction();
            $this->ensurePeriodRow($period['quota_date'], $limit);
            $row = $this->lockPeriodRow($period['quota_date']);
            $used = (int) ($row['characters_used'] ?? 0);
            $next = $used + $characters;

            if ($next > $limit) {
                $this->markLimitReachedRow($period['quota_date'], $limit);
                $this->pdo->commit();
                throw $this->limitException($sourceLanguage, $period['reset_display']);
            }

            $statement = $this->pdo->prepare(
                "UPDATE translation_daily_usage
                 SET characters_used = :characters_used,
                     character_limit = :character_limit,
                     limit_reached_at = CASE
                         WHEN :limit_reached_at = 1 THEN COALESCE(limit_reached_at, UTC_TIMESTAMP())
                         ELSE limit_reached_at
                     END,
                     alert_next_attempt_at = CASE
                         WHEN :limit_reached_retry = 1 AND alert_sent_at IS NULL
                              AND alert_status = 'none' THEN NULL
                         ELSE alert_next_attempt_at
                     END,
                     alert_status = CASE
                         WHEN :limit_reached_status = 1 AND alert_sent_at IS NULL
                              AND alert_status = 'none' THEN 'pending'
                         ELSE alert_status
                     END
                 WHERE engine_key = :engine_key AND quota_date = :quota_date"
            );
            $statement->execute([
                'characters_used' => $next,
                'character_limit' => $limit,
                'limit_reached_at' => $next >= $limit ? 1 : 0,
                'limit_reached_status' => $next >= $limit ? 1 : 0,
                'limit_reached_retry' => $next >= $limit ? 1 : 0,
                'engine_key' => $this->engineKey(),
                'quota_date' => $period['quota_date'],
            ]);
            $this->pdo->commit();
        } catch (InvalidArgumentException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new InvalidArgumentException(
                $sourceLanguage === 'en'
                    ? 'Not saved: daily translation usage control is unavailable.'
                    : 'Não guardado: o controlo diário da tradução está indisponível.'
            );
        }
    }

    public function markProviderLimitReached(): void
    {
        $period = $this->currentPeriod();
        $limit = $this->dailyLimit();
        if ($limit < 1 || $this->pdo->inTransaction()) {
            return;
        }

        try {
            $this->pdo->beginTransaction();
            $this->ensurePeriodRow($period['quota_date'], $limit);
            $this->lockPeriodRow($period['quota_date']);
            $this->markLimitReachedRow($period['quota_date'], $limit);
            $this->pdo->commit();
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    /** @return array{quota_date:string,characters_used:int,character_limit:int,reset_display:string}|null */
    public function pendingAlert(): ?array
    {
        $period = $this->currentPeriod();
        $expire = $this->pdo->prepare(
            "UPDATE translation_daily_usage
             SET alert_status = 'expired'
             WHERE engine_key = :engine_key
               AND quota_date <> :quota_date
               AND alert_status IN ('pending', 'failed')
               AND alert_sent_at IS NULL"
        );
        $expire->execute([
            'engine_key' => $this->engineKey(),
            'quota_date' => $period['quota_date'],
        ]);

        $statement = $this->pdo->prepare(
            "SELECT quota_date, characters_used, character_limit
             FROM translation_daily_usage
             WHERE engine_key = :engine_key
               AND quota_date = :quota_date
               AND alert_status IN ('pending', 'failed')
               AND alert_sent_at IS NULL
               AND (alert_next_attempt_at IS NULL OR alert_next_attempt_at <= UTC_TIMESTAMP())
             LIMIT 1"
        );
        $statement->execute([
            'engine_key' => $this->engineKey(),
            'quota_date' => $period['quota_date'],
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'quota_date' => (string) $row['quota_date'],
            'characters_used' => (int) $row['characters_used'],
            'character_limit' => (int) $row['character_limit'],
            'reset_display' => $period['reset_display'],
        ];
    }

    public function markAlertSent(string $quotaDate, string $messageId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE translation_daily_usage
             SET alert_status = 'sent', alert_sent_at = UTC_TIMESTAMP(),
                 alert_message_id = :message_id, alert_last_error = NULL,
                 alert_next_attempt_at = NULL
             WHERE engine_key = :engine_key AND quota_date = :quota_date
               AND alert_sent_at IS NULL"
        );
        $statement->execute([
            'message_id' => mb_substr($messageId, 0, 255),
            'engine_key' => $this->engineKey(),
            'quota_date' => $quotaDate,
        ]);
    }

    public function markAlertFailed(string $quotaDate, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE translation_daily_usage
             SET alert_status = 'failed',
                 alert_attempt_count = alert_attempt_count + 1,
                 alert_last_error = :last_error,
                 alert_next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
             WHERE engine_key = :engine_key AND quota_date = :quota_date
               AND alert_sent_at IS NULL"
        );
        $statement->execute([
            'last_error' => mb_substr($error, 0, 1000),
            'engine_key' => $this->engineKey(),
            'quota_date' => $quotaDate,
        ]);
    }

    public function limitException(string $sourceLanguage, ?string $resetDisplay = null): InvalidArgumentException
    {
        $reset = $resetDisplay ?? $this->currentPeriod()['reset_display'];
        return new InvalidArgumentException(
            $sourceLanguage === 'en'
                ? "Not saved: daily translation limit reached. Try again after {$reset} (Portugal time)."
                : "Não guardado: limite diário de tradução atingido. Tente novamente depois de {$reset} (hora de Portugal)."
        );
    }

    /** @return array{quota_date:string,reset_display:string,reset_utc:string} */
    public static function periodAt(
        DateTimeImmutable $now,
        string $quotaTimezone = self::DEFAULT_QUOTA_TIMEZONE,
        string $displayTimezone = self::DEFAULT_DISPLAY_TIMEZONE
    ): array {
        $quotaZone = new DateTimeZone($quotaTimezone);
        $displayZone = new DateTimeZone($displayTimezone);
        $quotaNow = $now->setTimezone($quotaZone);
        $quotaReset = $quotaNow->setTime(0, 0, 0)->modify('+1 day');
        $displayReset = $quotaReset->setTimezone($displayZone);

        return [
            'quota_date' => $quotaNow->format('Y-m-d'),
            'reset_display' => $displayReset->format('d/m/Y') . ' às ' . $displayReset->format('H:i'),
            'reset_utc' => $quotaReset->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array{quota_date:string,reset_display:string,reset_utc:string} */
    private function currentPeriod(): array
    {
        return self::periodAt(
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->quotaTimezone(),
            $this->displayTimezone()
        );
    }

    private function ensurePeriodRow(string $quotaDate, int $limit): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO translation_daily_usage
                (engine_key, quota_date, character_limit, characters_used, alert_status)
             VALUES (:engine_key, :quota_date, :character_limit, 0, 'none')
             ON DUPLICATE KEY UPDATE character_limit = VALUES(character_limit)"
        );
        $statement->execute([
            'engine_key' => $this->engineKey(),
            'quota_date' => $quotaDate,
            'character_limit' => $limit,
        ]);
    }

    /** @return array<string,mixed> */
    private function lockPeriodRow(string $quotaDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT characters_used, character_limit, alert_status, alert_sent_at
             FROM translation_daily_usage
             WHERE engine_key = :engine_key AND quota_date = :quota_date
             FOR UPDATE'
        );
        $statement->execute([
            'engine_key' => $this->engineKey(),
            'quota_date' => $quotaDate,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Translation quota row is unavailable.');
        }
        return $row;
    }

    private function markLimitReachedRow(string $quotaDate, int $limit): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE translation_daily_usage
             SET character_limit = :character_limit,
                 limit_reached_at = COALESCE(limit_reached_at, UTC_TIMESTAMP()),
                 alert_next_attempt_at = CASE
                     WHEN alert_sent_at IS NULL AND alert_status = 'none' THEN NULL
                     ELSE alert_next_attempt_at
                 END,
                 alert_status = CASE
                     WHEN alert_sent_at IS NULL AND alert_status = 'none' THEN 'pending'
                     ELSE alert_status
                 END
             WHERE engine_key = :engine_key AND quota_date = :quota_date"
        );
        $statement->execute([
            'character_limit' => $limit,
            'engine_key' => $this->engineKey(),
            'quota_date' => $quotaDate,
        ]);
    }

    private function dailyLimit(): int
    {
        $configured = (int) ($this->config['daily_character_limit'] ?? self::DEFAULT_DAILY_LIMIT);
        return $configured > 0
            ? min($configured, self::DEFAULT_DAILY_LIMIT)
            : self::DEFAULT_DAILY_LIMIT;
    }

    private function engineKey(): string
    {
        $value = trim((string) ($this->config['engine_key'] ?? 'google-basic-nmt-v2'));
        return $value !== '' ? $value : 'google-basic-nmt-v2';
    }

    private function quotaTimezone(): string
    {
        $value = trim((string) ($this->config['quota_timezone'] ?? self::DEFAULT_QUOTA_TIMEZONE));
        return $this->validTimezoneOrDefault($value, self::DEFAULT_QUOTA_TIMEZONE);
    }

    private function displayTimezone(): string
    {
        $value = trim((string) ($this->config['display_timezone'] ?? self::DEFAULT_DISPLAY_TIMEZONE));
        return $this->validTimezoneOrDefault($value, self::DEFAULT_DISPLAY_TIMEZONE);
    }

    private function validTimezoneOrDefault(string $value, string $default): string
    {
        if ($value === '') {
            return $default;
        }
        try {
            new DateTimeZone($value);
            return $value;
        } catch (Throwable) {
            return $default;
        }
    }
}
