<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * An access token (and its refresh token) issued to an MCP client for one Mautic user.
 *
 * Tokens are stored as SHA-256 hashes. A leaked database row therefore cannot be replayed
 * against the MCP endpoint.
 */
class McpAccessToken
{
    public const TABLE_NAME = 'plugin_mcp_oauth_tokens';

    private ?int $id = null;

    private string $tokenHash;

    private ?string $refreshTokenHash = null;

    private string $clientId;

    private int $userId;

    private ?string $scope = null;

    private \DateTimeInterface $expiresAt;

    private ?\DateTimeInterface $refreshExpiresAt = null;

    private ?\DateTimeInterface $lastUsed = null;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTime();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(McpAccessTokenRepository::class)
            ->addIndex(['token_hash'], 'mcp_token_search')
            ->addIndex(['refresh_token_hash'], 'mcp_refresh_search')
            ->addIndex(['expires_at'], 'mcp_token_expiry');

        $builder->addId();

        $builder->createField('tokenHash', 'string')
            ->columnName('token_hash')
            ->length(64)
            ->unique()
            ->build();

        $builder->createField('refreshTokenHash', 'string')
            ->columnName('refresh_token_hash')
            ->length(64)
            ->nullable()
            ->build();

        $builder->createField('clientId', 'string')
            ->columnName('client_id')
            ->length(64)
            ->build();

        $builder->createField('userId', 'integer')
            ->columnName('user_id')
            ->build();

        $builder->createField('scope', 'text')
            ->columnName('scope')
            ->nullable()
            ->build();

        $builder->createField('expiresAt', 'datetime')
            ->columnName('expires_at')
            ->build();

        $builder->createField('refreshExpiresAt', 'datetime')
            ->columnName('refresh_expires_at')
            ->nullable()
            ->build();

        $builder->createField('lastUsed', 'datetime')
            ->columnName('last_used')
            ->nullable()
            ->build();

        $builder->createField('dateAdded', 'datetime')
            ->columnName('date_added')
            ->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getRefreshTokenHash(): ?string
    {
        return $this->refreshTokenHash;
    }

    public function setRefreshTokenHash(?string $refreshTokenHash): self
    {
        $this->refreshTokenHash = $refreshTokenHash;

        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRefreshExpiresAt(): ?\DateTimeInterface
    {
        return $this->refreshExpiresAt;
    }

    public function setRefreshExpiresAt(?\DateTimeInterface $refreshExpiresAt): self
    {
        $this->refreshExpiresAt = $refreshExpiresAt;

        return $this;
    }

    public function getLastUsed(): ?\DateTimeInterface
    {
        return $this->lastUsed;
    }

    public function setLastUsed(?\DateTimeInterface $lastUsed): self
    {
        $this->lastUsed = $lastUsed;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt->getTimestamp() < time();
    }

    public function isRefreshExpired(): bool
    {
        return null === $this->refreshExpiresAt || $this->refreshExpiresAt->getTimestamp() < time();
    }
}
