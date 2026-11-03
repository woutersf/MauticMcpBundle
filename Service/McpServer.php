<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMcpBundle\Exception\McpInvalidParamsException;
use MauticPlugin\MauticMcpBundle\Exception\McpMethodNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The JSON-RPC 2.0 side of the Model Context Protocol.
 *
 * The server is stateless: every request is handled on its own, with no session to keep
 * between calls. That is what the 2026-07-28 revision of the protocol asks for, and older
 * clients that still send "initialize" first keep working because the handshake is
 * answered but nothing is stored.
 */
class McpServer
{
    /**
     * Newest first. The server answers "initialize" with the client's requested version
     * when it is one of these, and with the newest one otherwise.
     */
    public const SUPPORTED_PROTOCOL_VERSIONS = [
        '2026-07-28',
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    public const LATEST_PROTOCOL_VERSION = '2026-07-28';

    private const PARSE_ERROR      = -32700;
    private const INVALID_REQUEST  = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS   = -32602;
    private const INTERNAL_ERROR   = -32603;

    public function __construct(
        private ToolRegistry $tools,
        private ToolExecutor $executor,
        private McpSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handles one JSON-RPC message, or a batch of them.
     *
     * @return array<string, mixed>|array<int, array<string, mixed>>|null null when every
     *                                                                   message was a notification and nothing has to be sent back
     */
    public function handle(mixed $payload, User $user): array|null
    {
        if (!is_array($payload)) {
            return $this->errorResponse(null, self::PARSE_ERROR, 'The request body is not valid JSON-RPC.');
        }

        // A JSON-RPC batch is a plain list of messages.
        if ([] !== $payload && array_is_list($payload)) {
            $responses = [];

            foreach ($payload as $message) {
                $response = $this->handleMessage(is_array($message) ? $message : [], $user);

                if (null !== $response) {
                    $responses[] = $response;
                }
            }

            return [] === $responses ? null : $responses;
        }

        return $this->handleMessage($payload, $user);
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>|null
     */
    private function handleMessage(array $message, User $user): ?array
    {
        $id     = $message['id'] ?? null;
        $method = is_string($message['method'] ?? null) ? $message['method'] : null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (null === $method) {
            return $this->errorResponse($id, self::INVALID_REQUEST, 'The "method" member is required.');
        }

        // Notifications carry no id and must never get a response.
        $isNotification = !array_key_exists('id', $message) || null === $id;

        try {
            $result = match ($method) {
                'initialize'                 => $this->initialize($params),
                'ping'                       => new \stdClass(),
                'tools/list'                 => $this->listTools(),
                'tools/call'                 => $this->callTool($params, $user),
                'prompts/list'               => $this->listPrompts(),
                'prompts/get'                => $this->getPrompt($params),
                'resources/list'             => ['resources' => []],
                'resources/templates/list'   => ['resourceTemplates' => []],
                'completion/complete'        => ['completion' => ['values' => [], 'hasMore' => false]],
                'logging/setLevel'           => new \stdClass(),
                default                      => $this->handleUnknown($method, $isNotification),
            };
        } catch (McpInvalidParamsException $e) {
            return $isNotification ? null : $this->errorResponse($id, self::INVALID_PARAMS, $e->getMessage());
        } catch (McpMethodNotFoundException $e) {
            return $isNotification ? null : $this->errorResponse($id, self::METHOD_NOT_FOUND, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('MCP: ' . $e->getMessage(), ['exception' => $e, 'method' => $method]);

            return $isNotification ? null : $this->errorResponse($id, self::INTERNAL_ERROR, $e->getMessage());
        }

        if ($isNotification) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];
    }

    private function handleUnknown(string $method, bool $isNotification): mixed
    {
        // Every notifications/* message is accepted and ignored. That covers
        // notifications/initialized, notifications/cancelled and anything a future client
        // decides to send.
        if ($isNotification || str_starts_with($method, 'notifications/')) {
            return new \stdClass();
        }

        throw new McpMethodNotFoundException(sprintf('Unknown method "%s".', $method));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        $version = in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requested
            : self::LATEST_PROTOCOL_VERSION;

        return [
            'protocolVersion' => $version,
            'capabilities'    => [
                'tools'   => ['listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => 'mautic',
                'title'   => 'Mautic',
                'version' => McpSettings::VERSION,
            ],
            'instructions' => $this->instructions(),
        ];
    }

    private function instructions(): string
    {
        $mode = $this->settings->isWriteEnabled()
            ? 'This connection may read and change Mautic data.'
            : 'This connection is read only. Tools that would change Mautic data are not offered.';

        return <<<TEXT
            This server exposes a Mautic marketing automation instance.

            {$mode} Every call runs as a specific Mautic user and obeys that user's
            permissions, so a denied call means the Mautic user lacks the permission, not
            that the tool is broken. Call mautic_whoami to see who you are acting as.

            Useful habits:
            - Contacts are called "leads" in some Mautic responses. They are the same thing.
            - Call mautic_list_contact_fields before writing contact fields, so you use the
              field aliases this instance actually has.
            - List endpoints page with "start" and "limit". Ask for a small limit first.
            - Before sending any email, show the user what will be sent and to whom, and
              wait for their go ahead.
            TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function listTools(): array
    {
        $tools = [];

        foreach ($this->availableTools() as $name => $tool) {
            $tools[] = [
                'name'        => $name,
                'title'       => $tool['title'],
                'description' => $tool['description'],
                'inputSchema' => $tool['schema'],
                'annotations' => [
                    'title'           => $tool['title'],
                    'readOnlyHint'    => ToolRegistry::SCOPE_READ === $tool['scope'],
                    'destructiveHint' => (bool) ($tool['destructive'] ?? false),
                    'idempotentHint'  => ToolRegistry::SCOPE_READ === $tool['scope'],
                    'openWorldHint'   => false,
                ],
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(array $params, User $user): array
    {
        $name = $params['name'] ?? null;

        if (!is_string($name) || '' === $name) {
            throw new McpInvalidParamsException('The "name" parameter is required.');
        }

        $tools = $this->availableTools();

        if (!isset($tools[$name])) {
            throw new McpInvalidParamsException(
                sprintf('Unknown tool "%s". Call tools/list to see what this server offers.', $name)
            );
        }

        $arguments = $params['arguments'] ?? [];

        if (!is_array($arguments)) {
            throw new McpInvalidParamsException('The "arguments" parameter must be an object.');
        }

        $missing = array_diff(
            $tools[$name]['schema']['required'] ?? [],
            array_keys(array_filter($arguments, static fn ($value): bool => null !== $value))
        );

        if ([] !== $missing) {
            throw new McpInvalidParamsException(
                sprintf('Missing required argument(s) for "%s": %s.', $name, implode(', ', $missing))
            );
        }

        return $this->executor->execute($user, $name, $tools[$name], $arguments);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function availableTools(): array
    {
        return $this->tools->available(
            $this->settings->isWriteEnabled(),
            $this->settings->isDeleteEnabled(),
            $this->settings->isPassthroughEnabled(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function listPrompts(): array
    {
        $prompts = [];

        foreach (McpPrompts::all() as $name => $prompt) {
            $prompts[] = [
                'name'        => $name,
                'title'       => $prompt['title'],
                'description' => $prompt['description'],
                'arguments'   => $prompt['arguments'],
            ];
        }

        return ['prompts' => $prompts];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function getPrompt(array $params): array
    {
        $name   = (string) ($params['name'] ?? '');
        $prompt = McpPrompts::all()[$name] ?? null;

        if (null === $prompt) {
            throw new McpInvalidParamsException(sprintf('Unknown prompt "%s".', $name));
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return [
            'description' => $prompt['description'],
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => McpPrompts::render($prompt['template'], $arguments),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
    }
}
