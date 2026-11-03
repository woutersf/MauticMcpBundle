<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<McpAccessToken>
 */
class McpAccessTokenRepository extends CommonRepository
{
    public function findByTokenHash(string $tokenHash): ?McpAccessToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findByRefreshTokenHash(string $refreshTokenHash): ?McpAccessToken
    {
        return $this->findOneBy(['refreshTokenHash' => $refreshTokenHash]);
    }

    /**
     * Removes tokens whose refresh window has also closed, so the table does not grow forever.
     */
    public function deleteExpired(): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(McpAccessToken::class, 't')
            ->where('t.expiresAt < :now')
            ->andWhere('t.refreshExpiresAt IS NULL OR t.refreshExpiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }
}
