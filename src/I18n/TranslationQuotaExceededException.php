<?php
declare(strict_types=1);

final class TranslationQuotaExceededException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        private string $quotaDate,
        private string $resetDisplay,
        private string $resetUtc,
        private string $sourceLanguage
    ) {
        parent::__construct($message);
    }

    public function quotaDate(): string
    {
        return $this->quotaDate;
    }

    public function resetDisplay(): string
    {
        return $this->resetDisplay;
    }

    public function resetUtc(): string
    {
        return $this->resetUtc;
    }

    public function sourceLanguage(): string
    {
        return $this->sourceLanguage;
    }
}
