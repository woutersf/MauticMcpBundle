<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Exception;

/**
 * An OAuth 2.1 error, carrying the error code that belongs in the JSON response body.
 */
class OauthException extends \RuntimeException
{
    public function __construct(
        private string $error,
        string $description,
        private int $status = 400,
    ) {
        parent::__construct($description);
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
