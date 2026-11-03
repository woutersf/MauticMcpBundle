<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<McpClient>
 */
class McpClientRepository extends CommonRepository
{
    public function findByClientId(string $clientId): ?McpClient
    {
        return $this->findOneBy(['clientId' => $clientId]);
    }
}
