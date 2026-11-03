<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

/**
 * The catalogue of MCP tools this server advertises.
 *
 * Every tool is a thin, documented facade over one Mautic REST API route. The route is
 * executed as an internal sub-request (see MauticApiBridge), so Mautic's own permission
 * checks, validation and serialisation apply unchanged.
 *
 * Mapping keys used by ToolExecutor:
 *   method        HTTP verb of the Mautic API route
 *   path          route path, with {placeholders} filled from the arguments
 *   query         argument names that are passed as query string parameters
 *   body          argument names that are merged into the JSON request body
 *   body_from     a single object argument whose properties become the whole request body
 *   defaults      query string parameters that are always sent
 */
class ToolRegistry
{
    public const SCOPE_READ  = 'read';
    public const SCOPE_WRITE = 'write';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return array_merge(
            $this->contactTools(),
            $this->segmentTools(),
            $this->campaignTools(),
            $this->emailTools(),
            $this->contentTools(),
            $this->reportingTools(),
            $this->discoveryTools(),
        );
    }

    /**
     * The tools an MCP client may call, filtered by what the installation allows.
     *
     * @return array<string, array<string, mixed>>
     */
    public function available(bool $writeEnabled, bool $deleteEnabled, bool $passthroughEnabled): array
    {
        $tools = $this->all();

        if (!$writeEnabled) {
            $tools = array_filter($tools, fn (array $tool): bool => self::SCOPE_READ === $tool['scope']);
        }

        if (!$deleteEnabled) {
            $tools = array_filter($tools, fn (array $tool): bool => empty($tool['destructive']));
        }

        if ($passthroughEnabled) {
            $tools = array_merge($tools, $this->passthroughTool($writeEnabled));
        }

        return $tools;
    }

    public function get(string $name, bool $writeEnabled, bool $deleteEnabled, bool $passthroughEnabled): ?array
    {
        return $this->available($writeEnabled, $deleteEnabled, $passthroughEnabled)[$name] ?? null;
    }

    /**
     * Shared argument definitions for Mautic's list endpoints.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listProperties(string $what): array
    {
        return [
            'search' => [
                'type'        => 'string',
                'description' => sprintf(
                    'Mautic search string, for example "acme" or "is:published". Leave empty to list all %s.',
                    $what
                ),
            ],
            'start' => [
                'type'        => 'integer',
                'description' => 'Zero based offset of the first record to return. Use with limit to page through results.',
                'minimum'     => 0,
            ],
            'limit' => [
                'type'        => 'integer',
                'description' => 'How many records to return. Defaults to 30, maximum 200.',
                'minimum'     => 1,
                'maximum'     => 200,
            ],
            'orderBy' => [
                'type'        => 'string',
                'description' => 'Column to sort on, for example "dateAdded" or "name".',
            ],
            'orderByDir' => [
                'type'        => 'string',
                'description' => 'Sort direction.',
                'enum'        => ['ASC', 'DESC'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function listQueryArgs(): array
    {
        return ['search', 'start', 'limit', 'orderBy', 'orderByDir', 'minimal', 'where', 'order'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contactTools(): array
    {
        return [
            'mautic_list_contacts' => [
                'title'       => 'List contacts',
                'description' => 'Search and list Mautic contacts (leads). Returns the contact id, the core fields and the current points for each match. Use this to answer questions such as "how many contacts signed up last week" or "find the contact for jane@example.com".',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'properties' => array_merge($this->listProperties('contacts'), [
                        'minimal' => [
                            'type'        => 'boolean',
                            'description' => 'Return only the core contact fields instead of every custom field. Much smaller responses.',
                        ],
                    ]),
                ],
                'api' => [
                    'method' => 'GET',
                    'path'   => '/api/contacts',
                    'query'  => $this->listQueryArgs(),
                ],
            ],
            'mautic_get_contact' => [
                'title'       => 'Get a contact',
                'description' => 'Fetch one Mautic contact by its numeric id, including all custom fields, tags, owner, points and stage.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                    ],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/contacts/{id}'],
            ],
            'mautic_get_contact_activity' => [
                'title'       => 'Get contact activity',
                'description' => 'Fetch the timeline of a contact: page hits, email opens and clicks, form submissions, campaign steps and segment changes. This is the best tool for "what has this person done" questions.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'            => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                        'search'        => ['type' => 'string', 'description' => 'Filter the timeline by event name.'],
                        'includeEvents' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Only include these event types, for example ["email.opened", "page.hit"].',
                        ],
                        'page'  => ['type' => 'integer', 'description' => 'Page number of the timeline, starting at 1.'],
                        'limit' => ['type' => 'integer', 'description' => 'Events per page. Defaults to 25.'],
                    ],
                ],
                'api' => [
                    'method' => 'GET',
                    'path'   => '/api/contacts/{id}/activity',
                    'query'  => ['search', 'includeEvents', 'page', 'limit'],
                ],
            ],
            'mautic_get_contact_segments' => [
                'title'       => 'List the segments of a contact',
                'description' => 'List the segments a given contact currently belongs to.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric contact id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/contacts/{id}/segments'],
            ],
            'mautic_get_contact_campaigns' => [
                'title'       => 'List the campaigns of a contact',
                'description' => 'List the campaigns a given contact is a member of, with the date they joined.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric contact id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/contacts/{id}/campaigns'],
            ],
            'mautic_get_contact_notes' => [
                'title'       => 'List the notes of a contact',
                'description' => 'List the free text notes that users have written on a contact record.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'     => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                        'search' => ['type' => 'string', 'description' => 'Filter notes by text.'],
                        'start'  => ['type' => 'integer'],
                        'limit'  => ['type' => 'integer'],
                    ],
                ],
                'api' => [
                    'method' => 'GET',
                    'path'   => '/api/contacts/{id}/notes',
                    'query'  => ['search', 'start', 'limit'],
                ],
            ],
            'mautic_list_contact_fields' => [
                'title'       => 'List contact fields',
                'description' => 'List every contact field defined in this Mautic instance, with its alias, label and type. Call this first when you need to know which field aliases to use in mautic_create_contact or mautic_update_contact.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => new \stdClass()],
                'api'         => ['method' => 'GET', 'path' => '/api/contacts/list/fields'],
            ],
            'mautic_create_contact' => [
                'title'       => 'Create a contact',
                'description' => 'Create a new Mautic contact. Mautic deduplicates on its unique identifier fields (email by default), so an existing contact with the same email is updated rather than duplicated. Use mautic_list_contact_fields to discover valid field aliases.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['fields'],
                    'properties' => [
                        'fields' => [
                            'type'                 => 'object',
                            'description'          => 'Field alias to value, for example {"email": "jane@example.com", "firstname": "Jane", "tags": ["webinar"]}.',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/contacts/new', 'body_from' => 'fields'],
            ],
            'mautic_update_contact' => [
                'title'       => 'Update a contact',
                'description' => 'Update an existing Mautic contact. Only the fields you pass are changed, everything else is left as it is.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id', 'fields'],
                    'properties' => [
                        'id'     => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                        'fields' => [
                            'type'                 => 'object',
                            'description'          => 'Field alias to new value.',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'api' => ['method' => 'PATCH', 'path' => '/api/contacts/{id}/edit', 'body_from' => 'fields'],
            ],
            'mautic_delete_contact' => [
                'title'       => 'Delete a contact',
                'description' => 'Permanently delete a contact and its history from Mautic. This cannot be undone.',
                'scope'       => self::SCOPE_WRITE,
                'destructive' => true,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric contact id.']],
                ],
                'api' => ['method' => 'DELETE', 'path' => '/api/contacts/{id}/delete'],
            ],
            'mautic_adjust_contact_points' => [
                'title'       => 'Adjust the points of a contact',
                'description' => 'Add to or subtract from a contact point score.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id', 'operator', 'value'],
                    'properties' => [
                        'id'       => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                        'operator' => [
                            'type'        => 'string',
                            'description' => 'How to apply the value to the current score.',
                            'enum'        => ['plus', 'minus', 'times', 'divide'],
                        ],
                        'value'      => ['type' => 'integer', 'description' => 'The amount to apply.'],
                        'eventName'  => ['type' => 'string', 'description' => 'Label shown in the contact timeline.'],
                        'actionName' => ['type' => 'string', 'description' => 'Secondary label shown in the contact timeline.'],
                    ],
                ],
                'api' => [
                    'method' => 'POST',
                    'path'   => '/api/contacts/{id}/points/{operator}/{value}',
                    'body'   => ['eventName', 'actionName'],
                ],
            ],
            'mautic_set_do_not_contact' => [
                'title'       => 'Add a contact to Do Not Contact',
                'description' => 'Mark a contact as unsubscribed or bounced for a channel so Mautic stops sending to them.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id', 'channel'],
                    'properties' => [
                        'id'      => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                        'channel' => [
                            'type'        => 'string',
                            'description' => 'The channel to block.',
                            'enum'        => ['email', 'sms'],
                        ],
                        'reason' => [
                            'type'        => 'integer',
                            'description' => 'Why the contact is blocked: 1 unsubscribed, 2 bounced, 3 manual.',
                            'enum'        => [1, 2, 3],
                        ],
                        'comments' => ['type' => 'string', 'description' => 'Free text reason stored with the record.'],
                    ],
                ],
                'api' => [
                    'method' => 'POST',
                    'path'   => '/api/contacts/{id}/dnc/{channel}/add',
                    'body'   => ['reason', 'comments'],
                ],
            ],
            'mautic_remove_do_not_contact' => [
                'title'       => 'Remove a contact from Do Not Contact',
                'description' => 'Allow Mautic to send to this contact on a channel again.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id', 'channel'],
                    'properties' => [
                        'id'      => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                        'channel' => ['type' => 'string', 'enum' => ['email', 'sms']],
                    ],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/contacts/{id}/dnc/{channel}/remove'],
            ],
            'mautic_list_companies' => [
                'title'       => 'List companies',
                'description' => 'Search and list the companies (accounts) stored in Mautic.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('companies')],
                'api'         => ['method' => 'GET', 'path' => '/api/companies', 'query' => $this->listQueryArgs()],
            ],
            'mautic_get_company' => [
                'title'       => 'Get a company',
                'description' => 'Fetch one company by its numeric id, with all of its fields.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric company id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/companies/{id}'],
            ],
            'mautic_create_company' => [
                'title'       => 'Create a company',
                'description' => 'Create a new company record in Mautic.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['fields'],
                    'properties' => [
                        'fields' => [
                            'type'                 => 'object',
                            'description'          => 'Field alias to value, for example {"companyname": "Acme", "companycity": "Ghent"}.',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/companies/new', 'body_from' => 'fields'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function segmentTools(): array
    {
        return [
            'mautic_list_segments' => [
                'title'       => 'List segments',
                'description' => 'List the Mautic segments (contact lists) with their name, alias and filters.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('segments')],
                'api'         => ['method' => 'GET', 'path' => '/api/segments', 'query' => $this->listQueryArgs()],
            ],
            'mautic_get_segment' => [
                'title'       => 'Get a segment',
                'description' => 'Fetch one segment by its numeric id, including the filter definition that decides who is in it.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric segment id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/segments/{id}'],
            ],
            'mautic_create_segment' => [
                'title'       => 'Create a segment',
                'description' => 'Create a new Mautic segment. Filters follow the Mautic segment filter format: a list of objects with glue, field, object, type, operator and filter keys.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name'        => ['type' => 'string', 'description' => 'Display name of the segment.'],
                        'alias'       => ['type' => 'string', 'description' => 'URL safe alias. Generated from the name when omitted.'],
                        'description' => ['type' => 'string', 'description' => 'What this segment is for.'],
                        'isPublished' => ['type' => 'boolean', 'description' => 'Whether the segment is active. Defaults to true.'],
                        'isGlobal'    => ['type' => 'boolean', 'description' => 'Whether every user can see the segment.'],
                        'filters'     => [
                            'type'        => 'array',
                            'description' => 'Segment filters, for example [{"glue":"and","field":"email","object":"lead","type":"email","operator":"like","filter":"%@acme.com"}].',
                            'items'       => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
                'api' => [
                    'method' => 'POST',
                    'path'   => '/api/segments/new',
                    'body'   => ['name', 'alias', 'description', 'isPublished', 'isGlobal', 'filters'],
                ],
            ],
            'mautic_add_contact_to_segment' => [
                'title'       => 'Add a contact to a segment',
                'description' => 'Add one contact to a segment manually.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['segmentId', 'contactId'],
                    'properties' => [
                        'segmentId' => ['type' => 'integer', 'description' => 'The numeric segment id.'],
                        'contactId' => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                    ],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/segments/{segmentId}/contact/{contactId}/add'],
            ],
            'mautic_remove_contact_from_segment' => [
                'title'       => 'Remove a contact from a segment',
                'description' => 'Remove one contact from a segment.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['segmentId', 'contactId'],
                    'properties' => [
                        'segmentId' => ['type' => 'integer', 'description' => 'The numeric segment id.'],
                        'contactId' => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                    ],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/segments/{segmentId}/contact/{contactId}/remove'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function campaignTools(): array
    {
        return [
            'mautic_list_campaigns' => [
                'title'       => 'List campaigns',
                'description' => 'List the Mautic campaigns with their name, category and published state.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('campaigns')],
                'api'         => ['method' => 'GET', 'path' => '/api/campaigns', 'query' => $this->listQueryArgs()],
            ],
            'mautic_get_campaign' => [
                'title'       => 'Get a campaign',
                'description' => 'Fetch one campaign by its numeric id, including its full event tree (the campaign builder steps) and the segments that feed it.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric campaign id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/campaigns/{id}'],
            ],
            'mautic_get_campaign_contacts' => [
                'title'       => 'List the contacts in a campaign',
                'description' => 'List the contacts that are currently members of a campaign.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'    => ['type' => 'integer', 'description' => 'The numeric campaign id.'],
                        'start' => ['type' => 'integer'],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/campaigns/{id}/contacts', 'query' => ['start', 'limit']],
            ],
            'mautic_add_contact_to_campaign' => [
                'title'       => 'Add a contact to a campaign',
                'description' => 'Add one contact to a campaign so it starts flowing through the campaign steps.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['campaignId', 'contactId'],
                    'properties' => [
                        'campaignId' => ['type' => 'integer', 'description' => 'The numeric campaign id.'],
                        'contactId'  => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                    ],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/campaigns/{campaignId}/contact/{contactId}/add'],
            ],
            'mautic_remove_contact_from_campaign' => [
                'title'       => 'Remove a contact from a campaign',
                'description' => 'Remove one contact from a campaign.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['campaignId', 'contactId'],
                    'properties' => [
                        'campaignId' => ['type' => 'integer', 'description' => 'The numeric campaign id.'],
                        'contactId'  => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                    ],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/campaigns/{campaignId}/contact/{contactId}/remove'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emailTools(): array
    {
        return [
            'mautic_list_emails' => [
                'title'       => 'List emails',
                'description' => 'List the emails in Mautic with their subject, type (segment email or template email) and send statistics.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('emails')],
                'api'         => ['method' => 'GET', 'path' => '/api/emails', 'query' => $this->listQueryArgs()],
            ],
            'mautic_get_email' => [
                'title'       => 'Get an email',
                'description' => 'Fetch one email by its numeric id, including subject, HTML body, segments and the sent, read and failed counters.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric email id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/emails/{id}'],
            ],
            'mautic_create_email' => [
                'title'       => 'Create an email',
                'description' => 'Create a new email in Mautic. Create it unpublished first, review it in the Mautic UI, then publish it. Use emailType "template" for a one to one email and "list" for a segment email.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['name', 'subject'],
                    'properties' => [
                        'name'        => ['type' => 'string', 'description' => 'Internal name of the email.'],
                        'subject'     => ['type' => 'string', 'description' => 'Subject line shown to the recipient.'],
                        'customHtml'  => ['type' => 'string', 'description' => 'The HTML body. Mautic tokens such as {contactfield=firstname} are allowed.'],
                        'plainText'   => ['type' => 'string', 'description' => 'Plain text alternative body.'],
                        'emailType'   => [
                            'type'        => 'string',
                            'description' => 'Segment email ("list") or template email ("template").',
                            'enum'        => ['list', 'template'],
                        ],
                        'fromAddress' => ['type' => 'string', 'description' => 'Override the sender address.'],
                        'fromName'    => ['type' => 'string', 'description' => 'Override the sender name.'],
                        'replyToAddress' => ['type' => 'string', 'description' => 'Override the reply to address.'],
                        'isPublished' => ['type' => 'boolean', 'description' => 'Whether the email is live. Defaults to false, which is the safe choice.'],
                        'segments'    => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'description' => 'Segment ids this email is sent to. Only used when emailType is "list".',
                        ],
                    ],
                ],
                'api' => [
                    'method' => 'POST',
                    'path'   => '/api/emails/new',
                    'body'   => ['name', 'subject', 'customHtml', 'plainText', 'emailType', 'fromAddress', 'fromName', 'replyToAddress', 'isPublished', 'segments'],
                ],
            ],
            'mautic_update_email' => [
                'title'       => 'Update an email',
                'description' => 'Update an existing email. Only the fields you pass are changed.',
                'scope'       => self::SCOPE_WRITE,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'          => ['type' => 'integer', 'description' => 'The numeric email id.'],
                        'name'        => ['type' => 'string'],
                        'subject'     => ['type' => 'string'],
                        'customHtml'  => ['type' => 'string'],
                        'plainText'   => ['type' => 'string'],
                        'isPublished' => ['type' => 'boolean'],
                        'segments'    => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                ],
                'api' => [
                    'method' => 'PATCH',
                    'path'   => '/api/emails/{id}/edit',
                    'body'   => ['name', 'subject', 'customHtml', 'plainText', 'isPublished', 'segments'],
                ],
            ],
            'mautic_send_email_to_contact' => [
                'title'       => 'Send an email to one contact',
                'description' => 'Send an existing Mautic email to one contact immediately. The email must be published. This really sends a message, so confirm with the user first.',
                'scope'       => self::SCOPE_WRITE,
                'destructive' => true,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['emailId', 'contactId'],
                    'properties' => [
                        'emailId'   => ['type' => 'integer', 'description' => 'The numeric email id.'],
                        'contactId' => ['type' => 'integer', 'description' => 'The numeric contact id.'],
                        'tokens'    => [
                            'type'                 => 'object',
                            'description'          => 'Extra token values to substitute in the body.',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'api' => [
                    'method' => 'POST',
                    'path'   => '/api/emails/{emailId}/contact/{contactId}/send',
                    'body'   => ['tokens'],
                ],
            ],
            'mautic_send_email_to_segments' => [
                'title'       => 'Send a segment email',
                'description' => 'Send a published segment email to every contact in its segments. This starts a real send to potentially many people, so always confirm with the user before calling it.',
                'scope'       => self::SCOPE_WRITE,
                'destructive' => true,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric email id.']],
                ],
                'api' => ['method' => 'POST', 'path' => '/api/emails/{id}/send'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contentTools(): array
    {
        return [
            'mautic_list_forms' => [
                'title'       => 'List forms',
                'description' => 'List the Mautic forms with their name, alias and submission count.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('forms')],
                'api'         => ['method' => 'GET', 'path' => '/api/forms', 'query' => $this->listQueryArgs()],
            ],
            'mautic_get_form' => [
                'title'       => 'Get a form',
                'description' => 'Fetch one form by its numeric id, including every field and every submit action.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric form id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/forms/{id}'],
            ],
            'mautic_get_form_submissions' => [
                'title'       => 'List form submissions',
                'description' => 'List the submissions of a form, with the submitted values and the contact that submitted them.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'    => ['type' => 'integer', 'description' => 'The numeric form id.'],
                        'start' => ['type' => 'integer'],
                        'limit' => ['type' => 'integer'],
                        'order' => ['type' => 'string', 'description' => 'Column to sort on.'],
                    ],
                ],
                'api' => [
                    'method' => 'GET',
                    'path'   => '/api/forms/{id}/submissions',
                    'query'  => ['start', 'limit', 'order'],
                ],
            ],
            'mautic_list_pages' => [
                'title'       => 'List landing pages',
                'description' => 'List the Mautic landing pages with their title, alias, URL and hit count.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('pages')],
                'api'         => ['method' => 'GET', 'path' => '/api/pages', 'query' => $this->listQueryArgs()],
            ],
            'mautic_get_page' => [
                'title'       => 'Get a landing page',
                'description' => 'Fetch one landing page by its numeric id, including its HTML.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'The numeric page id.']],
                ],
                'api' => ['method' => 'GET', 'path' => '/api/pages/{id}'],
            ],
            'mautic_list_assets' => [
                'title'       => 'List assets',
                'description' => 'List the downloadable assets in Mautic with their title, download URL and download count.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('assets')],
                'api'         => ['method' => 'GET', 'path' => '/api/assets', 'query' => $this->listQueryArgs()],
            ],
            'mautic_list_tags' => [
                'title'       => 'List tags',
                'description' => 'List every contact tag defined in this Mautic instance.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('tags')],
                'api'         => ['method' => 'GET', 'path' => '/api/tags', 'query' => $this->listQueryArgs()],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function reportingTools(): array
    {
        return [
            'mautic_list_reports' => [
                'title'       => 'List reports',
                'description' => 'List the saved reports in Mautic.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => $this->listProperties('reports')],
                'api'         => ['method' => 'GET', 'path' => '/api/reports', 'query' => $this->listQueryArgs()],
            ],
            'mautic_run_report' => [
                'title'       => 'Run a report',
                'description' => 'Fetch a saved report by its numeric id and run it, returning the resulting rows and totals.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'         => ['type' => 'integer', 'description' => 'The numeric report id.'],
                        'dateFrom'   => ['type' => 'string', 'description' => 'Start of the reporting window, as YYYY-MM-DD.'],
                        'dateTo'     => ['type' => 'string', 'description' => 'End of the reporting window, as YYYY-MM-DD.'],
                        'page'       => ['type' => 'integer', 'description' => 'Page of results, starting at 1.'],
                        'limit'      => ['type' => 'integer', 'description' => 'Rows per page.'],
                    ],
                ],
                'api' => [
                    'method' => 'GET',
                    'path'   => '/api/reports/{id}',
                    'query'  => ['dateFrom', 'dateTo', 'page', 'limit'],
                ],
            ],
            'mautic_get_stats' => [
                'title'       => 'Query a statistics table',
                'description' => 'Read rows straight from one of Mautic statistics tables, for example "email_stats", "page_hits", "lead_event_log" or "campaign_lead_event_log". Call it without a table name to get the list of tables you may query.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'table' => [
                            'type'        => 'string',
                            'description' => 'Statistics table name. Omit to list the available tables.',
                        ],
                        'start' => ['type' => 'integer', 'description' => 'Zero based offset.'],
                        'limit' => ['type' => 'integer', 'description' => 'Row count. Defaults to 100.'],
                        'order' => [
                            'type'        => 'array',
                            'description' => 'Sorting, for example [{"col": "date_sent", "dir": "DESC"}].',
                            'items'       => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'where' => [
                            'type'        => 'array',
                            'description' => 'Filters, for example [{"col": "email_id", "expr": "eq", "val": 7}].',
                            'items'       => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
                'api' => [
                    'method'   => 'GET',
                    'path'     => '/api/stats/{table}',
                    'optional' => ['table'],
                    'query'    => ['start', 'limit', 'order', 'where'],
                ],
            ],
            'mautic_get_dashboard_data' => [
                'title'       => 'Get dashboard widget data',
                'description' => 'Fetch the numbers behind a Mautic dashboard widget, for example "emails.in.time", "submissions.in.time" or "created.leads.in.time". Call it without a type to get the list of widget types.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'type'     => ['type' => 'string', 'description' => 'Widget type. Omit to list the available types.'],
                        'dateFrom' => ['type' => 'string', 'description' => 'Start of the window, as YYYY-MM-DD.'],
                        'dateTo'   => ['type' => 'string', 'description' => 'End of the window, as YYYY-MM-DD.'],
                        'timeUnit' => [
                            'type'        => 'string',
                            'description' => 'Granularity of the series.',
                            'enum'        => ['H', 'd', 'W', 'm', 'Y'],
                        ],
                    ],
                ],
                'api' => [
                    'method'   => 'GET',
                    'path'     => '/api/data/{type}',
                    'optional' => ['type'],
                    'query'    => ['dateFrom', 'dateTo', 'timeUnit'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function discoveryTools(): array
    {
        return [
            'search' => [
                'title'       => 'Search Mautic',
                'description' => 'Search across Mautic contacts, companies, segments, campaigns, emails, forms and pages in one call. Returns a flat list of results with an id, a title and a URL. Pass that id to the fetch tool to read the full record.',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['query'],
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What to look for.'],
                        'types' => [
                            'type'        => 'array',
                            'description' => 'Limit the search to these record types. Defaults to all of them.',
                            'items'       => [
                                'type' => 'string',
                                'enum' => ['contact', 'company', 'segment', 'campaign', 'email', 'form', 'page'],
                            ],
                        ],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum results per type. Defaults to 10.'],
                    ],
                ],
                'handler' => 'search',
            ],
            'fetch' => [
                'title'       => 'Fetch a Mautic record',
                'description' => 'Fetch the full record behind an id returned by the search tool. The id has the form "type:number", for example "contact:42".',
                'scope'       => self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'A search result id, for example "contact:42".'],
                    ],
                ],
                'handler' => 'fetch',
            ],
            'mautic_whoami' => [
                'title'       => 'Show the connected Mautic user',
                'description' => 'Return the Mautic user this MCP connection acts as, with their role and permissions. Useful to explain why a call was denied.',
                'scope'       => self::SCOPE_READ,
                'schema'      => ['type' => 'object', 'properties' => new \stdClass()],
                'api'         => ['method' => 'GET', 'path' => '/api/users/self'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function passthroughTool(bool $writeEnabled): array
    {
        $methods = $writeEnabled ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] : ['GET'];

        return [
            'mautic_api_request' => [
                'title'       => 'Call any Mautic REST API endpoint',
                'description' => 'Escape hatch for anything the dedicated tools do not cover. Calls a Mautic REST API route directly. The path must start with /api/. See https://developer.mautic.org/ for the available routes.',
                'scope'       => $writeEnabled ? self::SCOPE_WRITE : self::SCOPE_READ,
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['path'],
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => 'API path, for example "/api/contacts" or "/api/emails/3".'],
                        'method' => [
                            'type'        => 'string',
                            'description' => 'HTTP method. Defaults to GET.',
                            'enum'        => $methods,
                        ],
                        'query' => [
                            'type'                 => 'object',
                            'description'          => 'Query string parameters.',
                            'additionalProperties' => true,
                        ],
                        'body' => [
                            'type'                 => 'object',
                            'description'          => 'JSON request body, for write methods.',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'handler' => 'passthrough',
            ],
        ];
    }
}
