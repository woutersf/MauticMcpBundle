<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Works out which Mautic user is behind an MCP request.
 *
 * Three credentials are accepted, in this order:
 *   1. An OAuth 2.1 bearer token issued by this plugin. This is what Claude and ChatGPT use.
 *   2. The fixed bearer token from the plugin configuration, for clients that cannot run
 *      an OAuth flow. It acts as one configured Mautic user.
 *   3. HTTP basic auth with Mautic credentials, off by default.
 *
 * The MCP endpoint is a public route, so nothing is authenticated until this class says so.
 */
class McpAuthenticator
{
    public function __construct(
        private TokenManager $tokenManager,
        private McpSettings $settings,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
    ) {
    }

    public function authenticate(Request $request): ?User
    {
        $header = (string) $request->headers->get('Authorization', '');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $this->authenticateBearer(trim($matches[1]));
        }

        if (preg_match('/^Basic\s+(.+)$/i', $header, $matches)) {
            return $this->authenticateBasic(trim($matches[1]));
        }

        return null;
    }

    private function authenticateBearer(string $token): ?User
    {
        if ('' === $token) {
            return null;
        }

        if ($this->settings->isOauthEnabled()) {
            $user = $this->tokenManager->userForAccessToken($token);

            if (null !== $user) {
                return $user;
            }
        }

        return $this->authenticateStaticToken($token);
    }

    private function authenticateStaticToken(string $token): ?User
    {
        $configured = $this->settings->staticToken();

        if ('' === $configured) {
            return null;
        }

        // Compared with hash_equals so the check does not leak the token through timing.
        if (!hash_equals($configured, $token)) {
            return null;
        }

        $userId = $this->settings->staticTokenUserId();

        if ($userId <= 0) {
            $this->logger->warning('MCP: a static token is configured but no Mautic user is selected for it.');

            return null;
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);

        if (!$user instanceof User || !$user->isPublished()) {
            $this->logger->warning(sprintf('MCP: the static token user %d does not exist or is disabled.', $userId));

            return null;
        }

        return $user;
    }

    private function authenticateBasic(string $encoded): ?User
    {
        if (!$this->settings->isBasicAuthEnabled()) {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        if (false === $decoded || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);

        if (!$user instanceof User) {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $username]);
        }

        if (!$user instanceof User || !$user->isPublished()) {
            return null;
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->logger->notice(sprintf('MCP: failed basic auth attempt for "%s".', $username));

            return null;
        }

        return $user;
    }
}
