<?php
declare(strict_types=1);

final class TranslationServiceException extends InvalidArgumentException
{
    public function __construct(string $message, private bool $retryable = true)
    {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
