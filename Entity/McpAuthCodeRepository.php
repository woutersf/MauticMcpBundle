<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<McpAuthCode>
 */
class McpAuthCodeRepository extends CommonRepository
{
    public function findByCode(string $code): ?McpAuthCode
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Removes codes that can no longer be exchanged. Called opportunistically on issue.
     */
    public function deleteExpired(): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(McpAuthCode::class, 'c')
            ->where('c.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }
}
