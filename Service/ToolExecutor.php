<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

use Mautic\UserBundle\Entity\User;

/**
 * Turns one MCP tools/call into one or more Mautic REST API calls and shapes the result
 * into MCP content blocks.
 */
class ToolExecutor
{
    private const SEARCHABLE = [
        'contact'  => ['path' => '/api/contacts',  'key' => 'contacts',  'label' => ['firstname', 'lastname', 'email'], 'ui' => 's/contacts/view/%d'],
        'company'  => ['path' => '/api/companies', 'key' => 'companies', 'label' => ['companyname'],                    'ui' => 's/companies/view/%d'],
        'segment'  => ['path' => '/api/segments',  'key' => 'lists',     'label' => ['name'],                           'ui' => 's/segments/edit/%d'],
        'campaign' => ['path' => '/api/campaigns', 'key' => 'campaigns', 'label' => ['name'],                           'ui' => 's/campaigns/view/%d'],
        'email'    => ['path' => '/api/emails',    'key' => 'emails',    'label' => ['name', 'subject'],                'ui' => 's/emails/view/%d'],
        'form'     => ['path' => '/api/forms',     'key' => 'forms',     'label' => ['name'],                           'ui' => 's/forms/view/%d'],
        'page'     => ['path' => '/api/pages',     'key' => 'pages',     'label' => ['title'],                          'ui' => 's/pages/view/%d'],
    ];

    private const FETCHABLE = [
        'contact'  => '/api/contacts/%d',
        'company'  => '/api/companies/%d',
        'segment'  => '/api/segments/%d',
        'campaign' => '/api/campaigns/%d',
        'email'    => '/api/emails/%d',
        'form'     => '/api/forms/%d',
        'page'     => '/api/pages/%d',
    ];

    public function __construct(
        private MauticApiBridge $bridge,
        private McpSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $arguments
     *
     * @return array{content: array<int, array<string, mixed>>, isError: bool, structuredContent?: mixed}
     */
    public function execute(User $user, string $name, array $tool, array $arguments): array
    {
        try {
            return match ($tool['handler'] ?? 'api') {
                'search'      => $this->runSearch($user, $arguments),
                'fetch'       => $this->runFetch($user, $arguments),
                'passthrough' => $this->runPassthrough($user, $arguments),
                default       => $this->runApiTool($user, $name, $tool, $arguments),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $arguments
     *
     * @return array{content: array<int, array<string, mixed>>, isError: bool, structuredContent?: mixed}
     */
    private function runApiTool(User $user, string $name, array $tool, array $arguments): array
    {
        $api      = $tool['api'];
        $optional = $api['optional'] ?? [];
        $path     = $api['path'];

        preg_match_all('/\{(\w+)\}/', $path, $matches);

        foreach ($matches[1] as $placeholder) {
            $value = $arguments[$placeholder] ?? null;

            if (null === $value || '' === $value) {
                if (in_array($placeholder, $optional, true)) {
                    // /api/stats/{table} and /api/data/{type} list what is available when
                    // the segment is left off entirely.
                    $path = str_replace('/{' . $placeholder . '}', '', $path);
                    continue;
                }

                throw new \InvalidArgumentException(
                    sprintf('The tool "%s" requires the argument "%s".', $name, $placeholder)
                );
            }

            $path = str_replace('{' . $placeholder . '}', rawurlencode((string) $value), $path);
        }

        $query = [];
        foreach ($api['query'] ?? [] as $argument) {
            if (array_key_exists($argument, $arguments) && null !== $arguments[$argument]) {
                $query[$argument] = $this->normaliseScalar($arguments[$argument]);
            }
        }

        $body = null;
        if (isset($api['body_from'])) {
            $candidate = $arguments[$api['body_from']] ?? null;
            if (is_array($candidate)) {
                $body = $candidate;
            }
        }

        foreach ($api['body'] ?? [] as $argument) {
            if (array_key_exists($argument, $arguments) && null !== $arguments[$argument]) {
                $body ??= [];
                $body[$argument] = $arguments[$argument];
            }
        }

        if (null === $body && !in_array($api['method'], ['GET', 'DELETE'], true)) {
            $body = [];
        }

        $body = $this->repairBuilderHtml($body);

        $result = $this->bridge->call($user, $api['method'], $path, $query, $body);

        return $this->respond($result);
    }

    /**
     * Emails and landing pages both keep their body in customHtml, and both are edited by
     * the same GrapesJS builder, which refuses a document whose head parses empty. Repair
     * it on the way in, so nothing written through this server lands unopenable.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null
     */
    private function repairBuilderHtml(?array $body): ?array
    {
        if (null === $body || !isset($body['customHtml']) || !is_string($body['customHtml'])) {
            return $body;
        }

        $body['customHtml'] = BuilderHtmlNormalizer::normalize(
            $body['customHtml'],
            (string) ($body['subject'] ?? $body['title'] ?? $body['name'] ?? '')
        );

        return $body;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: array<int, array<string, mixed>>, isError: bool, structuredContent?: mixed}
     */
    private function runSearch(User $user, array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ('' === $query) {
            throw new \InvalidArgumentException('The "query" argument is required.');
        }

        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 10)));
        $types = $arguments['types'] ?? array_keys(self::SEARCHABLE);
        $types = array_values(array_intersect((array) $types, array_keys(self::SEARCHABLE)));

        $results = [];

        foreach ($types as $type) {
            $spec   = self::SEARCHABLE[$type];
            $result = $this->bridge->call($user, 'GET', $spec['path'], [
                'search'  => $query,
                'limit'   => $limit,
                'minimal' => 1,
            ]);

            if ($result['status'] >= 400 || !is_array($result['data'])) {
                // A user without permission on one entity type should still get the rest.
                continue;
            }

            foreach ((array) ($result['data'][$spec['key']] ?? []) as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $results[] = [
                    'id'    => $type . ':' . ($record['id'] ?? 0),
                    'type'  => $type,
                    'title' => $this->labelFor($record, $spec['label']),
                    'url'   => rtrim($this->settings->siteUrl(), '/') . '/' . sprintf($spec['ui'], (int) ($record['id'] ?? 0)),
                ];
            }
        }

        return $this->respond(['status' => 200, 'data' => ['results' => $results]]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: array<int, array<string, mixed>>, isError: bool, structuredContent?: mixed}
     */
    private function runFetch(User $user, array $arguments): array
    {
        $id = (string) ($arguments['id'] ?? '');

        if (!preg_match('/^([a-z]+):(\d+)$/', $id, $matches)) {
            throw new \InvalidArgumentException('The "id" argument must look like "contact:42".');
        }

        [, $type, $recordId] = $matches;

        if (!isset(self::FETCHABLE[$type])) {
            throw new \InvalidArgumentException(
                sprintf('Unknown record type "%s". Expected one of: %s.', $type, implode(', ', array_keys(self::FETCHABLE)))
            );
        }

        $result = $this->bridge->call($user, 'GET', sprintf(self::FETCHABLE[$type], (int) $recordId));

        return $this->respond($result);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: array<int, array<string, mixed>>, isError: bool, structuredContent?: mixed}
     */
    private function runPassthrough(User $user, array $arguments): array
    {
        $path = (string) ($arguments['path'] ?? '');

        if (!str_starts_with($path, '/api/')) {
            throw new \InvalidArgumentException('The "path" argument must start with "/api/".');
        }

        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException('The "path" argument must not contain "..".');
        }

        $method = strtoupper((string) ($arguments['method'] ?? 'GET'));

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported HTTP method "%s".', $method));
        }

        $query = is_array($arguments['query'] ?? null) ? $arguments['query'] : [];
        $body  = is_array($arguments['body'] ?? null) ? $arguments['body'] : null;

        if (null === $body && !in_array($method, ['GET', 'DELETE'], true)) {
            $body = [];
        }

        // The raw API tool reaches /api/emails and /api/pages too, so it needs the same
        // repair. Passing HTML straight through here is how an email ends up stored in a
        // shape the builder cannot open.
        $body = $this->repairBuilderHtml($body);

        $result = $this->bridge->call($user, $method, $path, $query, $body);

        return $this->respond($result);
    }

    /**
     * @param array{status: int, data: mixed} $result
     *
     * @return array{content: array<int, array<string, mixed>>, isError: bool, structuredContent?: mixed}
     */
    private function respond(array $result): array
    {
        $isError = $result['status'] >= 400;
        $payload = $result['data'];

        if ($isError) {
            $message = $this->errorMessage($payload, $result['status']);

            return $this->error($message, $payload);
        }

        return [
            'content'           => [['type' => 'text', 'text' => $this->encode($payload)]],
            'structuredContent' => is_array($payload) ? $payload : ['result' => $payload],
            'isError'           => false,
        ];
    }

    /**
     * @return array{content: array<int, array<string, mixed>>, isError: bool}
     */
    private function error(string $message, mixed $details = null): array
    {
        $text = $message;

        if (null !== $details) {
            $text .= "\n\n" . $this->encode($details);
        }

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => true,
        ];
    }

    private function errorMessage(mixed $payload, int $status): string
    {
        if (is_array($payload) && isset($payload['errors'][0]['message'])) {
            return sprintf('Mautic refused the request (HTTP %d): %s', $status, $payload['errors'][0]['message']);
        }

        if (403 === $status) {
            return 'Mautic refused the request (HTTP 403). The connected Mautic user does not have permission for this action.';
        }

        if (404 === $status) {
            return 'Mautic could not find that record (HTTP 404).';
        }

        return sprintf('Mautic refused the request (HTTP %d).', $status);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, string>   $fields
     */
    private function labelFor(array $record, array $fields): string
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $record[$field] ?? ($record['fields']['core'][$field]['value'] ?? null);

            if (is_string($value) && '' !== trim($value)) {
                $values[] = trim($value);
            }
        }

        return [] === $values ? ('#' . ($record['id'] ?? '?')) : implode(' ', array_unique($values));
    }

    private function normaliseScalar(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function encode(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ) ?: '{}';
    }
}
