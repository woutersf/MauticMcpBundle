<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * A short lived OAuth 2.1 authorization code, bound to the Mautic user who approved it
 * and to the PKCE challenge the client sent.
 */
class McpAuthCode
{
    public const TABLE_NAME = 'plugin_mcp_oauth_codes';

    private ?int $id = null;

    private string $code;

    private string $clientId;

    private int $userId;

    private string $redirectUri;

    private ?string $scope = null;

    private ?string $codeChallenge = null;

    private ?string $codeChallengeMethod = null;

    private \DateTimeInterface $expiresAt;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTime();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(McpAuthCodeRepository::class)
            ->addIndex(['code'], 'mcp_code_search')
            ->addIndex(['expires_at'], 'mcp_code_expiry');

        $builder->addId();

        $builder->createField('code', 'string')
            ->columnName('code')
            ->length(128)
            ->unique()
            ->build();

        $builder->createField('clientId', 'string')
            ->columnName('client_id')
            ->length(64)
            ->build();

        $builder->createField('userId', 'integer')
            ->columnName('user_id')
            ->build();

        $builder->createField('redirectUri', 'text')
            ->columnName('redirect_uri')
            ->build();

        $builder->createField('scope', 'text')
            ->columnName('scope')
            ->nullable()
            ->build();

        $builder->createField('codeChallenge', 'string')
            ->columnName('code_challenge')
            ->length(191)
            ->nullable()
            ->build();

        $builder->createField('codeChallengeMethod', 'string')
            ->columnName('code_challenge_method')
            ->length(16)
            ->nullable()
            ->build();

        $builder->createField('expiresAt', 'datetime')
            ->columnName('expires_at')
            ->build();

        $builder->createField('dateAdded', 'datetime')
            ->columnName('date_added')
            ->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

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

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function setRedirectUri(string $redirectUri): self
    {
        $this->redirectUri = $redirectUri;

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

    public function getCodeChallenge(): ?string
    {
        return $this->codeChallenge;
    }

    public function setCodeChallenge(?string $codeChallenge): self
    {
        $this->codeChallenge = $codeChallenge;

        return $this;
    }

    public function getCodeChallengeMethod(): ?string
    {
        return $this->codeChallengeMethod;
    }

    public function setCodeChallengeMethod(?string $codeChallengeMethod): self
    {
        $this->codeChallengeMethod = $codeChallengeMethod;

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

    public function isExpired(): bool
    {
        return $this->expiresAt->getTimestamp() < time();
    }
}
