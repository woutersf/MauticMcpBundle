<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Controller;

use MauticPlugin\MauticMcpBundle\Service\McpAuthenticator;
use MauticPlugin\MauticMcpBundle\Service\McpServer;
use MauticPlugin\MauticMcpBundle\Service\McpSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Streamable HTTP transport of the MCP server.
 *
 * POST /mcp carries one JSON-RPC message, or a batch of them. The reply is a single JSON
 * object, or an SSE frame when the client asks for text/event-stream and nothing else.
 * GET /mcp is answered with 405: this server never pushes messages on its own, so there
 * is no stream for a client to open.
 */
class McpController extends AbstractController
{
    public function __construct(
        private McpServer $server,
        private McpAuthenticator $authenticator,
        private McpSettings $settings,
    ) {
    }

    public function indexAction(Request $request): Response
    {
        if ('OPTIONS' === $request->getMethod()) {
            return $this->corsPreflight();
        }

        if (!$this->settings->isEnabled()) {
            return $this->cors(new JsonResponse(
                [
                    'error'             => 'service_unavailable',
                    'error_description' => $this->disabledReason(),
                ],
                Response::HTTP_SERVICE_UNAVAILABLE
            ));
        }

        if ('GET' === $request->getMethod()) {
            // No server initiated stream. Say so instead of leaving the client hanging.
            return $this->cors(new JsonResponse(
                [
                    'error'             => 'method_not_allowed',
                    'error_description' => 'This MCP server is stateless and does not open a server to client stream. Send JSON-RPC messages with POST.',
                ],
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => 'POST, DELETE, OPTIONS']
            ));
        }

        if ('DELETE' === $request->getMethod()) {
            // Clients close their session here. There is no session, so this always succeeds.
            return $this->cors(new Response('', Response::HTTP_NO_CONTENT));
        }

        $user = $this->authenticator->authenticate($request);

        if (null === $user) {
            return $this->unauthorized($request);
        }

        $raw = $request->getContent();

        try {
            $payload = '' === $raw ? null : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->cors(new JsonResponse(
                $this->server->errorResponse(null, -32700, 'Parse error: ' . $e->getMessage()),
                Response::HTTP_BAD_REQUEST
            ));
        }

        $result = $this->server->handle($payload, $user);

        if (null === $result) {
            // Every message was a notification.
            return $this->cors(new Response('', Response::HTTP_ACCEPTED));
        }

        return $this->cors($this->encodeResult($request, $result));
    }

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $result
     */
    private function encodeResult(Request $request, array $result): Response
    {
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($this->wantsEventStream($request)) {
            return new Response(
                "event: message\ndata: " . $json . "\n\n",
                Response::HTTP_OK,
                [
                    'Content-Type'      => 'text/event-stream; charset=utf-8',
                    'Cache-Control'     => 'no-cache, no-store',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]
            );
        }

        return new Response(
            (string) $json,
            Response::HTTP_OK,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * Only switch to SSE framing for a client that asked for it and will not take JSON.
     */
    private function wantsEventStream(Request $request): bool
    {
        $accept = strtolower((string) $request->headers->get('Accept', ''));

        return str_contains($accept, 'text/event-stream') && !str_contains($accept, 'application/json');
    }

    /**
     * The 401 points the client at the protected resource metadata (RFC 9728), which is
     * how Claude and ChatGPT discover where to send the user to log in.
     */
    private function unauthorized(Request $request): Response
    {
        $metadataUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . '/.well-known/oauth-protected-resource';

        $challenge = sprintf('Bearer realm="Mautic MCP", resource_metadata="%s"', $metadataUrl);

        return $this->cors(new JsonResponse(
            [
                'error'             => 'unauthorized',
                'error_description' => 'A valid access token is required. Authorise this client first, or send the configured MCP bearer token.',
            ],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => $challenge]
        ));
    }

    private function disabledReason(): string
    {
        if (!$this->settings->isPluginPublished()) {
            return 'The MCP plugin is not enabled. Enable it under Settings, Plugins, Mautic MCP Server.';
        }

        if (!$this->settings->isApiEnabled()) {
            return 'The Mautic REST API is disabled. Enable it under Configuration, API Settings.';
        }

        return 'The MCP endpoint is switched off in the Mautic configuration.';
    }

    private function corsPreflight(): Response
    {
        return $this->cors(new Response('', Response::HTTP_NO_CONTENT));
    }

    private function cors(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Mcp-Method, Mcp-Name, Last-Event-ID'
        );
        $response->headers->set('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
