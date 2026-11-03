<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Security\Authentication\Token\Permissions\TokenPermissions;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Runs a Mautic REST API route in process, as the given Mautic user.
 *
 * The MCP endpoint does not re-implement Mautic's API. It builds a sub-request for the
 * real API route and hands it to the HTTP kernel. Routing, the API controllers, the
 * permission checks and the JMS serialiser all run exactly as they do for an outside
 * API call, so this plugin cannot accidentally widen what a user is allowed to see.
 *
 * Sub-requests do not pass through the security firewall, which is why the user is put
 * into the token storage here before dispatching, and the previous token is restored
 * afterwards.
 */
class MauticApiBridge
{
    public function __construct(
        private HttpKernelInterface $httpKernel,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
        private CoreParametersHelper $coreParameters,
        private TokenPermissions $tokenPermissions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, data: mixed}
     */
    public function call(User $user, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        $path   = '/' . ltrim($path, '/');

        $previousToken = $this->tokenStorage->getToken();

        try {
            $token = new UsernamePasswordToken($user, 'mautic_mcp', $user->getRoles());
            $this->tokenStorage->setToken($token);

            // Mautic normally resolves a user's permission bitmasks in its authentication
            // listeners, which a token built here never passes through. Without this call
            // a non-admin user arrives with no permissions at all and every API route
            // answers 403, no matter what the role actually allows.
            $this->tokenPermissions->setActivePermissionsOnAuthToken($token);

            $subRequest = $this->buildSubRequest($method, $path, $query, $body);

            $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);

            $content = (string) $response->getContent();
            $decoded = '' === $content ? null : json_decode($content, true);

            if (JSON_ERROR_NONE !== json_last_error() && '' !== $content) {
                $decoded = ['raw' => $content];
            }

            return [
                'status' => $response->getStatusCode(),
                'data'   => $decoded,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('MCP: Mautic API call %s %s failed: %s', $method, $path, $e->getMessage()),
                ['exception' => $e]
            );

            return [
                'status' => 500,
                'data'   => [
                    'errors' => [
                        ['message' => $e->getMessage(), 'code' => 500, 'type' => 'internal_error'],
                    ],
                ],
            ];
        } finally {
            $this->tokenStorage->setToken($previousToken);
        }
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     */
    private function buildSubRequest(string $method, string $path, array $query, ?array $body): Request
    {
        $uri = $this->baseUrl() . $path;

        if ([] !== $query) {
            $uri .= '?' . http_build_query($query);
        }

        $content = null;
        $server  = [
            'HTTP_ACCEPT'  => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        if (null !== $body) {
            $content = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $subRequest = Request::create($uri, $method, [], [], [], $server, $content);

        // Carry over the headers that Mautic's own listeners look at, so that locale and
        // client address resolution behave the same as for a top level request.
        $mainRequest = $this->requestStack->getMainRequest();
        if (null !== $mainRequest) {
            foreach (['accept-language', 'user-agent', 'x-forwarded-for'] as $header) {
                if ($mainRequest->headers->has($header)) {
                    $subRequest->headers->set($header, $mainRequest->headers->get($header));
                }
            }

            $subRequest->server->set('REMOTE_ADDR', $mainRequest->server->get('REMOTE_ADDR', '127.0.0.1'));
        }

        // Mautic reads the request body as JSON for API writes; mark the format explicitly
        // so FOSRestBundle's body listener does not have to guess.
        $subRequest->setRequestFormat('json');
        $subRequest->attributes->set('_format', 'json');

        // Symfony only attaches a session to main requests, and parts of Mautic read one
        // while serving an API route. An in-memory session keeps those paths working
        // without writing anything into the caller's real session or setting a cookie.
        $subRequest->setSession(new Session(new MockArraySessionStorage()));

        return $subRequest;
    }

    /**
     * API routes inherit the scheme of site_url (Mautic forces https on every route when
     * site_url is https), so the sub-request has to be built with that same scheme or the
     * router answers with a redirect instead of the controller.
     */
    private function baseUrl(): string
    {
        $siteUrl = (string) $this->coreParameters->get('site_url');

        if ('' !== $siteUrl) {
            $parts = parse_url($siteUrl);

            if (!empty($parts['host'])) {
                $scheme = $parts['scheme'] ?? 'https';
                $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

                return $scheme . '://' . $parts['host'] . $port;
            }
        }

        $mainRequest = $this->requestStack->getMainRequest();

        return null !== $mainRequest ? $mainRequest->getSchemeAndHttpHost() : 'http://localhost';
    }
}
