<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * An OAuth 2.1 client registered against the MCP server.
 *
 * Rows are created by the Dynamic Client Registration endpoint (RFC 7591), which is how
 * Claude and ChatGPT onboard themselves when a user adds this server as a connector.
 */
class McpClient
{
    public const TABLE_NAME = 'plugin_mcp_oauth_clients';

    private ?int $id = null;

    private string $clientId;

    private ?string $clientSecret = null;

    private string $name = 'MCP client';

    /**
     * @var array<int, string>
     */
    private array $redirectUris = [];

    /**
     * @var array<int, string>
     */
    private array $grantTypes = ['authorization_code', 'refresh_token'];

    private ?string $scope = null;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTime();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(McpClientRepository::class)
            ->addIndex(['client_id'], 'mcp_client_id_search');

        $builder->addId();

        $builder->createField('clientId', 'string')
            ->columnName('client_id')
            ->length(64)
            ->unique()
            ->build();

        $builder->createField('clientSecret', 'string')
            ->columnName('client_secret')
            ->length(128)
            ->nullable()
            ->build();

        $builder->createField('name', 'string')
            ->columnName('name')
            ->length(191)
            ->build();

        $builder->createField('redirectUris', 'json')
            ->columnName('redirect_uris')
            ->build();

        $builder->createField('grantTypes', 'json')
            ->columnName('grant_types')
            ->build();

        $builder->createField('scope', 'text')
            ->columnName('scope')
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

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(?string $clientSecret): self
    {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    /**
     * @param array<int, string> $redirectUris
     */
    public function setRedirectUris(array $redirectUris): self
    {
        $this->redirectUris = array_values($redirectUris);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getGrantTypes(): array
    {
        return $this->grantTypes;
    }

    /**
     * @param array<int, string> $grantTypes
     */
    public function setGrantTypes(array $grantTypes): self
    {
        $this->grantTypes = array_values($grantTypes);

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

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function isRedirectUriAllowed(string $redirectUri): bool
    {
        return in_array($redirectUri, $this->redirectUris, true);
    }

    public function isPublicClient(): bool
    {
        return null === $this->clientSecret || '' === $this->clientSecret;
    }
}
