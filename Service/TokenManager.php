<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMcpBundle\Entity\McpAccessToken;
use MauticPlugin\MauticMcpBundle\Entity\McpAuthCode;
use MauticPlugin\MauticMcpBundle\Entity\McpClient;
use MauticPlugin\MauticMcpBundle\Exception\OauthException;

/**
 * Issues and validates the OAuth 2.1 credentials of the MCP server.
 *
 * Tokens are random 256 bit strings. Only their SHA-256 hash is stored, so the database
 * never holds anything that can be replayed against the endpoint.
 */
class TokenManager
{
    private const AUTH_CODE_LIFETIME = 300;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private McpSettings $settings,
    ) {
    }

    /**
     * Dynamic Client Registration, RFC 7591. Claude and ChatGPT call this by themselves
     * when a user adds the connector, so there is nothing to copy and paste by hand.
     *
     * @param array<string, mixed> $metadata
     */
    public function registerClient(array $metadata): McpClient
    {
        $redirectUris = $metadata['redirect_uris'] ?? [];
        $redirectUris = array_values(array_filter(
            array_map('strval', is_array($redirectUris) ? $redirectUris : []),
            static fn (string $uri): bool => '' !== $uri
        ));

        if ([] === $redirectUris) {
            throw new \InvalidArgumentException('At least one redirect_uris entry is required.');
        }

        foreach ($redirectUris as $uri) {
            $this->assertSafeRedirectUri($uri);
        }

        $grantTypes = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
        $grantTypes = array_values(array_intersect(
            array_map('strval', is_array($grantTypes) ? $grantTypes : []),
            ['authorization_code', 'refresh_token']
        ));

        if ([] === $grantTypes) {
            $grantTypes = ['authorization_code'];
        }

        $isPublic = 'none' === ($metadata['token_endpoint_auth_method'] ?? 'none');

        $client = (new McpClient())
            ->setClientId(bin2hex(random_bytes(16)))
            ->setClientSecret($isPublic ? null : bin2hex(random_bytes(32)))
            ->setName((string) ($metadata['client_name'] ?? 'MCP client'))
            ->setRedirectUris($redirectUris)
            ->setGrantTypes($grantTypes)
            ->setScope(isset($metadata['scope']) ? (string) $metadata['scope'] : null);

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    public function findClient(string $clientId): ?McpClient
    {
        return $this->entityManager->getRepository(McpClient::class)->findOneBy(['clientId' => $clientId]);
    }

    /**
     * Stores the user's approval and returns the one time code to hand back to the client.
     */
    public function issueAuthCode(
        McpClient $client,
        User $user,
        string $redirectUri,
        ?string $scope,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
    ): string {
        $this->entityManager->getRepository(McpAuthCode::class)->deleteExpired();

        $code = $this->randomToken();

        $authCode = (new McpAuthCode())
            ->setCode($this->hash($code))
            ->setClientId($client->getClientId())
            ->setUserId((int) $user->getId())
            ->setRedirectUri($redirectUri)
            ->setScope($scope)
            ->setCodeChallenge($codeChallenge)
            ->setCodeChallengeMethod($codeChallengeMethod)
            ->setExpiresAt(new \DateTime('@' . (time() + self::AUTH_CODE_LIFETIME)));

        $this->entityManager->persist($authCode);
        $this->entityManager->flush();

        return $code;
    }

    /**
     * Exchanges an authorization code for tokens, checking PKCE on the way.
     *
     * @return array<string, mixed>
     *
     * @throws OauthException
     */
    public function exchangeAuthCode(
        string $code,
        string $clientId,
        ?string $clientSecret,
        ?string $redirectUri,
        ?string $codeVerifier,
    ): array {
        $client = $this->authenticateClient($clientId, $clientSecret);

        $repository = $this->entityManager->getRepository(McpAuthCode::class);
        $authCode   = $repository->findOneBy(['code' => $this->hash($code)]);

        if (null === $authCode) {
            throw new OauthException('invalid_grant', 'The authorization code is not valid.');
        }

        // The code is single use whatever happens next.
        $this->entityManager->remove($authCode);
        $this->entityManager->flush();

        if ($authCode->isExpired()) {
            throw new OauthException('invalid_grant', 'The authorization code has expired.');
        }

        if (!hash_equals($authCode->getClientId(), $client->getClientId())) {
            throw new OauthException('invalid_grant', 'The authorization code was issued to another client.');
        }

        if (null !== $redirectUri && !hash_equals($authCode->getRedirectUri(), $redirectUri)) {
            throw new OauthException('invalid_grant', 'The redirect_uri does not match the one used to obtain the code.');
        }

        $this->verifyPkce($authCode, $codeVerifier);

        $user = $this->findUser($authCode->getUserId());

        if (null === $user) {
            throw new OauthException('invalid_grant', 'The Mautic user behind this code no longer exists.');
        }

        return $this->issueTokens($client, $user, $authCode->getScope());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OauthException
     */
    public function refreshTokens(string $refreshToken, string $clientId, ?string $clientSecret): array
    {
        $client = $this->authenticateClient($clientId, $clientSecret);

        $repository = $this->entityManager->getRepository(McpAccessToken::class);
        $existing   = $repository->findOneBy(['refreshTokenHash' => $this->hash($refreshToken)]);

        if (null === $existing) {
            throw new OauthException('invalid_grant', 'The refresh token is not valid.');
        }

        if ($existing->isRefreshExpired()) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            throw new OauthException('invalid_grant', 'The refresh token has expired.');
        }

        if (!hash_equals($existing->getClientId(), $client->getClientId())) {
            throw new OauthException('invalid_grant', 'The refresh token was issued to another client.');
        }

        $user = $this->findUser($existing->getUserId());

        if (null === $user) {
            throw new OauthException('invalid_grant', 'The Mautic user behind this token no longer exists.');
        }

        // Refresh tokens rotate: the old pair is dropped as the new one is issued.
        $scope = $existing->getScope();
        $this->entityManager->remove($existing);
        $this->entityManager->flush();

        return $this->issueTokens($client, $user, $scope);
    }

    /**
     * Resolves a bearer token to the Mautic user it belongs to.
     */
    public function userForAccessToken(string $token): ?User
    {
        $repository = $this->entityManager->getRepository(McpAccessToken::class);
        $record     = $repository->findOneBy(['tokenHash' => $this->hash($token)]);

        if (null === $record) {
            return null;
        }

        if ($record->isExpired()) {
            return null;
        }

        $user = $this->findUser($record->getUserId());

        if (null === $user) {
            return null;
        }

        $record->setLastUsed(new \DateTime());
        $this->entityManager->flush();

        return $user;
    }

    public function revoke(string $token): void
    {
        $repository = $this->entityManager->getRepository(McpAccessToken::class);
        $hash       = $this->hash($token);

        $record = $repository->findOneBy(['tokenHash' => $hash])
            ?? $repository->findOneBy(['refreshTokenHash' => $hash]);

        if (null !== $record) {
            $this->entityManager->remove($record);
            $this->entityManager->flush();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issueTokens(McpClient $client, User $user, ?string $scope): array
    {
        $this->entityManager->getRepository(McpAccessToken::class)->deleteExpired();

        $accessToken  = $this->randomToken();
        $refreshToken = $this->randomToken();

        $lifetime        = $this->settings->accessTokenLifetime();
        $refreshLifetime = $this->settings->refreshTokenLifetime();

        $record = (new McpAccessToken())
            ->setTokenHash($this->hash($accessToken))
            ->setRefreshTokenHash($this->hash($refreshToken))
            ->setClientId($client->getClientId())
            ->setUserId((int) $user->getId())
            ->setScope($scope)
            ->setExpiresAt(new \DateTime('@' . (time() + $lifetime)))
            ->setRefreshExpiresAt(new \DateTime('@' . (time() + $refreshLifetime)));

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return [
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $lifetime,
            'refresh_token' => $refreshToken,
            'scope'         => $scope ?? 'mautic',
        ];
    }

    /**
     * @throws OauthException
     */
    private function authenticateClient(string $clientId, ?string $clientSecret): McpClient
    {
        $client = $this->findClient($clientId);

        if (null === $client) {
            throw new OauthException('invalid_client', 'Unknown client_id.');
        }

        if (!$client->isPublicClient()) {
            if (null === $clientSecret || !hash_equals((string) $client->getClientSecret(), $clientSecret)) {
                throw new OauthException('invalid_client', 'The client_secret is missing or wrong.');
            }
        }

        return $client;
    }

    /**
     * @throws OauthException
     */
    private function verifyPkce(McpAuthCode $authCode, ?string $codeVerifier): void
    {
        $challenge = $authCode->getCodeChallenge();

        if (null === $challenge || '' === $challenge) {
            // No challenge was sent at the authorize step, so there is nothing to verify.
            return;
        }

        if (null === $codeVerifier || '' === $codeVerifier) {
            throw new OauthException('invalid_grant', 'A code_verifier is required because the request used PKCE.');
        }

        $method   = strtoupper((string) ($authCode->getCodeChallengeMethod() ?? 'PLAIN'));
        $computed = match ($method) {
            'S256'  => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'PLAIN' => $codeVerifier,
            default => throw new OauthException('invalid_request', sprintf('Unsupported code_challenge_method "%s".', $method)),
        };

        if (!hash_equals($challenge, $computed)) {
            throw new OauthException('invalid_grant', 'The code_verifier does not match the code_challenge.');
        }
    }

    private function findUser(int $userId): ?User
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);

        if (!$user instanceof User) {
            return null;
        }

        return $user->isPublished() ? $user : null;
    }

    /**
     * Blocks the redirect URI shapes that turn an authorization server into an open
     * redirector or let a page in the browser read the code.
     */
    private function assertSafeRedirectUri(string $uri): void
    {
        $parts = parse_url($uri);

        if (false === $parts || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid redirect URI.', $uri));
        }

        $scheme = strtolower($parts['scheme']);

        if (in_array($scheme, ['javascript', 'data', 'vbscript', 'file'], true)) {
            throw new \InvalidArgumentException(sprintf('The redirect URI scheme "%s" is not allowed.', $scheme));
        }

        if (isset($parts['fragment'])) {
            throw new \InvalidArgumentException('A redirect URI must not contain a fragment.');
        }

        // http is only acceptable on loopback, which is how desktop clients receive the code.
        if ('http' === $scheme && !in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]', 'localhost'], true)) {
            throw new \InvalidArgumentException('Plain http redirect URIs are only allowed on loopback addresses.');
        }
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
