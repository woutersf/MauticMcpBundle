<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Controller;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMcpBundle\Service\McpSettings;
use MauticPlugin\MauticMcpBundle\Exception\OauthException;
use MauticPlugin\MauticMcpBundle\Service\TokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The OAuth 2.1 authorization server that guards the MCP endpoint.
 *
 * It is deliberately small: authorization code with PKCE, refresh tokens, and dynamic
 * client registration. That is the exact set an MCP client needs, and nothing more.
 * The user approves the connection while logged into Mautic, so the tokens this server
 * hands out can never do more than that Mautic user is allowed to do.
 */
class OauthController extends AbstractController
{
    private const CSRF_INTENTION = 'mcp_oauth_authorize';

    public function __construct(
        private TokenManager $tokenManager,
        private McpSettings $settings,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * RFC 9728 protected resource metadata. The 401 from the MCP endpoint points here.
     */
    public function resourceMetadataAction(Request $request): Response
    {
        $base = $this->baseUrl($request);

        return $this->json([
            'resource'                 => $base . '/mcp',
            'authorization_servers'    => [$base],
            'bearer_methods_supported' => ['header'],
            'scopes_supported'         => ['mautic'],
            'resource_name'            => 'Mautic MCP server',
            'resource_documentation'   => 'https://developer.mautic.org/',
        ]);
    }

    /**
     * RFC 8414 authorization server metadata.
     */
    public function authorizationServerMetadataAction(Request $request): Response
    {
        $base = $this->baseUrl($request);

        return $this->json([
            'issuer'                                => $base,
            'authorization_endpoint'                => $base . '/s/mcp/authorize',
            'token_endpoint'                        => $base . '/mcp/token',
            'registration_endpoint'                 => $base . '/mcp/register',
            'revocation_endpoint'                   => $base . '/mcp/revoke',
            'scopes_supported'                      => ['mautic'],
            'response_types_supported'              => ['code'],
            'response_modes_supported'              => ['query'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported'      => ['S256'],
            'service_documentation'                 => 'https://developer.mautic.org/',
        ]);
    }

    /**
     * RFC 7591 dynamic client registration.
     */
    public function registerAction(Request $request): Response
    {
        if (!$this->settings->isEnabled() || !$this->settings->isOauthEnabled()) {
            return $this->oauthError('temporarily_unavailable', 'The MCP OAuth server is switched off.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $metadata = json_decode((string) $request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->oauthError('invalid_client_metadata', 'The request body is not valid JSON.');
        }

        if (!is_array($metadata)) {
            return $this->oauthError('invalid_client_metadata', 'The request body must be a JSON object.');
        }

        try {
            $client = $this->tokenManager->registerClient($metadata);
        } catch (\InvalidArgumentException $e) {
            return $this->oauthError('invalid_redirect_uri', $e->getMessage());
        }

        $response = [
            'client_id'                  => $client->getClientId(),
            'client_id_issued_at'        => $client->getDateAdded()->getTimestamp(),
            'client_name'                => $client->getName(),
            'redirect_uris'              => $client->getRedirectUris(),
            'grant_types'                => $client->getGrantTypes(),
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $client->isPublicClient() ? 'none' : 'client_secret_post',
        ];

        if (!$client->isPublicClient()) {
            $response['client_secret'] = $client->getClientSecret();
        }

        return $this->json($response, Response::HTTP_CREATED);
    }

    /**
     * The consent screen. This route sits behind /s/, so Mautic's own firewall makes the
     * user log in first and sends them back here afterwards.
     */
    public function authorizeAction(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new RedirectResponse($this->generateUrl('login'));
        }

        if (!$this->settings->isEnabled() || !$this->settings->isOauthEnabled()) {
            return $this->errorPage('The MCP OAuth server is switched off in this Mautic instance.');
        }

        $clientId     = (string) $request->get('client_id', '');
        $redirectUri  = (string) $request->get('redirect_uri', '');
        $responseType = (string) $request->get('response_type', 'code');
        $state        = (string) $request->get('state', '');
        $scope        = (string) $request->get('scope', 'mautic');
        $challenge    = (string) $request->get('code_challenge', '');
        $method       = (string) $request->get('code_challenge_method', '');

        $client = '' === $clientId ? null : $this->tokenManager->findClient($clientId);

        if (null === $client) {
            return $this->errorPage('Unknown client_id. The application asking for access is not registered here.');
        }

        // Anything below this point may safely redirect back to the client, because the
        // redirect URI has been checked against what the client registered.
        if ('' === $redirectUri) {
            $uris        = $client->getRedirectUris();
            $redirectUri = $uris[0] ?? '';
        }

        if ('' === $redirectUri || !$client->isRedirectUriAllowed($redirectUri)) {
            return $this->errorPage('The redirect_uri does not match what this client registered. Nothing was sent back to it.');
        }

        if ('code' !== $responseType) {
            return $this->redirectWithError($redirectUri, 'unsupported_response_type', 'Only the authorization code flow is supported.', $state);
        }

        if ('' !== $challenge && !in_array(strtoupper($method), ['S256', 'PLAIN'], true)) {
            return $this->redirectWithError($redirectUri, 'invalid_request', 'Unsupported code_challenge_method.', $state);
        }

        if ('POST' === $request->getMethod()) {
            $submittedToken = (string) $request->request->get('_csrf_token', '');

            if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_INTENTION, $submittedToken))) {
                return $this->errorPage('This approval form expired. Start the connection again from your MCP client.');
            }

            if ('approve' !== $request->request->get('action')) {
                return $this->redirectWithError($redirectUri, 'access_denied', 'The user declined the request.', $state);
            }

            $code = $this->tokenManager->issueAuthCode(
                $client,
                $user,
                $redirectUri,
                '' === $scope ? null : $scope,
                '' === $challenge ? null : $challenge,
                '' === $method ? null : strtoupper($method),
            );

            return new RedirectResponse($this->appendQuery($redirectUri, array_filter([
                'code'  => $code,
                'state' => '' === $state ? null : $state,
            ])));
        }

        return new Response(
            $this->renderConsentPage($request, $client->getName(), $redirectUri, $user),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    /**
     * The token endpoint: authorization_code and refresh_token grants.
     */
    public function tokenAction(Request $request): Response
    {
        if (!$this->settings->isEnabled() || !$this->settings->isOauthEnabled()) {
            return $this->oauthError('temporarily_unavailable', 'The MCP OAuth server is switched off.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        [$clientId, $clientSecret] = $this->clientCredentials($request);

        $grantType = (string) $request->request->get('grant_type', '');

        try {
            $tokens = match ($grantType) {
                'authorization_code' => $this->tokenManager->exchangeAuthCode(
                    (string) $request->request->get('code', ''),
                    $clientId,
                    $clientSecret,
                    $request->request->has('redirect_uri') ? (string) $request->request->get('redirect_uri') : null,
                    $request->request->has('code_verifier') ? (string) $request->request->get('code_verifier') : null,
                ),
                'refresh_token' => $this->tokenManager->refreshTokens(
                    (string) $request->request->get('refresh_token', ''),
                    $clientId,
                    $clientSecret,
                ),
                default => throw new OauthException('unsupported_grant_type', sprintf('The grant type "%s" is not supported.', $grantType)),
            };
        } catch (OauthException $e) {
            return $this->oauthError($e->getError(), $e->getMessage(), $e->getStatus());
        }

        $response = $this->json($tokens);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * RFC 7009 token revocation.
     */
    public function revokeAction(Request $request): Response
    {
        $token = (string) $request->request->get('token', '');

        if ('' !== $token) {
            $this->tokenManager->revoke($token);
        }

        // RFC 7009 asks for 200 whether or not the token existed.
        return new Response('', Response::HTTP_OK);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function clientCredentials(Request $request): array
    {
        $header = (string) $request->headers->get('Authorization', '');

        if (preg_match('/^Basic\s+(.+)$/i', $header, $matches)) {
            $decoded = base64_decode(trim($matches[1]), true);

            if (false !== $decoded && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [urldecode($id), urldecode($secret)];
            }
        }

        $clientId     = (string) $request->request->get('client_id', '');
        $clientSecret = $request->request->has('client_secret')
            ? (string) $request->request->get('client_secret')
            : null;

        return [$clientId, $clientSecret];
    }

    private function baseUrl(Request $request): string
    {
        $configured = $this->settings->siteUrl();

        if ('' !== $configured) {
            return $configured;
        }

        return $request->getSchemeAndHttpHost() . $request->getBaseUrl();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function appendQuery(string $uri, array $parameters): string
    {
        if ([] === $parameters) {
            return $uri;
        }

        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri . $separator . http_build_query($parameters);
    }

    private function redirectWithError(string $redirectUri, string $error, string $description, string $state): RedirectResponse
    {
        return new RedirectResponse($this->appendQuery($redirectUri, array_filter([
            'error'             => $error,
            'error_description' => $description,
            'state'             => '' === $state ? null : $state,
        ])));
    }

    private function oauthError(string $error, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return $this->json(
            ['error' => $error, 'error_description' => $description],
            $status
        );
    }

    private function errorPage(string $message): Response
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new Response(
            $this->page('Cannot authorise', '<p class="lead">' . $safe . '</p>'),
            Response::HTTP_BAD_REQUEST,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    private function renderConsentPage(Request $request, string $clientName, string $redirectUri, User $user): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $csrf   = $escape($this->csrfTokenManager->getToken(self::CSRF_INTENTION)->getValue());
        $action = $escape($request->getRequestUri());
        $client = $escape($clientName);
        $target = $escape(parse_url($redirectUri, PHP_URL_HOST) ?: $redirectUri);
        $who    = $escape($user->getName() . ' (' . $user->getUserIdentifier() . ')');

        $writeNotice = $this->settings->isWriteEnabled()
            ? '<li class="warn">Create and change contacts, segments, campaigns and emails</li>'
            : '<li>Read only: this connection cannot change anything in Mautic</li>';

        $deleteNotice = $this->settings->isDeleteEnabled()
            ? '<li class="warn">Delete records and send emails to real people</li>'
            : '';

        $body = <<<HTML
            <p class="lead"><strong>{$client}</strong> is asking to connect to this Mautic instance.</p>
            <p class="muted">It will act as <strong>{$who}</strong> and can do everything that user is allowed to do, no more.</p>
            <ul class="scopes">
              <li>Read contacts, companies, segments, campaigns, emails, forms, pages and reports</li>
              {$writeNotice}
              {$deleteNotice}
            </ul>
            <p class="muted small">After approving, your browser returns to <strong>{$target}</strong>.</p>
            <form method="post" action="{$action}">
              <input type="hidden" name="_csrf_token" value="{$csrf}">
              <div class="actions">
                <button type="submit" name="action" value="deny" class="btn btn-secondary">Cancel</button>
                <button type="submit" name="action" value="approve" class="btn btn-primary">Allow access</button>
              </div>
            </form>
            HTML;

        return $this->page('Connect to Mautic', $body);
    }

    private function page(string $title, string $body): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1.0">
              <meta name="robots" content="noindex, nofollow">
              <title>{$safeTitle}</title>
              <style>
                :root { color-scheme: light dark; }
                * { box-sizing: border-box; }
                body {
                  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                  margin: 0; min-height: 100vh; display: flex; align-items: center;
                  justify-content: center; background: #f0f2f5; color: #1c1e21; padding: 24px;
                }
                .card {
                  background: #fff; border-radius: 12px; padding: 2.5rem; max-width: 520px;
                  width: 100%; box-shadow: 0 6px 28px rgba(0,0,0,.12);
                }
                h1 { font-size: 1.4rem; margin: 0 0 1.25rem; }
                .lead { font-size: 1.02rem; line-height: 1.55; margin: 0 0 .75rem; }
                .muted { color: #606770; line-height: 1.55; margin: 0 0 1rem; }
                .small { font-size: .86rem; }
                ul.scopes { margin: 0 0 1.5rem; padding-left: 1.2rem; line-height: 1.7; }
                ul.scopes li.warn { color: #b35309; font-weight: 600; }
                .actions { display: flex; gap: .75rem; justify-content: flex-end; }
                .btn {
                  font: inherit; padding: .62rem 1.15rem; border-radius: 7px;
                  border: 1px solid transparent; cursor: pointer;
                }
                .btn-primary { background: #4e5e9e; color: #fff; }
                .btn-primary:hover { background: #3f4d84; }
                .btn-secondary { background: #e4e6eb; color: #1c1e21; }
                .btn-secondary:hover { background: #d8dadf; }
                @media (prefers-color-scheme: dark) {
                  body { background: #18191a; color: #e4e6eb; }
                  .card { background: #242526; box-shadow: 0 6px 28px rgba(0,0,0,.5); }
                  .muted { color: #b0b3b8; }
                  .btn-secondary { background: #3a3b3c; color: #e4e6eb; }
                  ul.scopes li.warn { color: #f0a868; }
                }
              </style>
            </head>
            <body>
              <div class="card">
                <h1>{$safeTitle}</h1>
                {$body}
              </div>
            </body>
            </html>
            HTML;
    }
}
