<?php

declare(strict_types=1);

return [
    'name'        => 'Mautic MCP Server',
    'description' => 'Exposes Mautic as a remote Model Context Protocol (MCP) server at <code>/mcp</code>, so Claude, ChatGPT and other MCP clients can read and act on this instance.',
    'version'     => '1.1.1',
    'author'      => 'Dropsolid',

    'routes' => [
        'public' => [
            // The MCP endpoint itself. Authentication happens inside the controller,
            // because MCP clients authenticate with a bearer token rather than a session.
            'mautic_mcp_endpoint' => [
                'path'       => '/mcp',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\McpController::indexAction',
                'method'     => ['GET', 'POST', 'DELETE', 'OPTIONS'],
            ],

            // RFC 9728. Both spellings exist in the wild, so both are served.
            'mautic_mcp_resource_metadata' => [
                'path'       => '/.well-known/oauth-protected-resource',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::resourceMetadataAction',
                'method'     => ['GET'],
            ],
            'mautic_mcp_resource_metadata_scoped' => [
                'path'       => '/.well-known/oauth-protected-resource/mcp',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::resourceMetadataAction',
                'method'     => ['GET'],
            ],

            // RFC 8414.
            'mautic_mcp_authorization_server_metadata' => [
                'path'       => '/.well-known/oauth-authorization-server',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::authorizationServerMetadataAction',
                'method'     => ['GET'],
            ],
            // RFC 8414 section 3.1: clients probe the path aware variant first.
            'mautic_mcp_authorization_server_metadata_scoped' => [
                'path'       => '/.well-known/oauth-authorization-server/mcp',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::authorizationServerMetadataAction',
                'method'     => ['GET'],
            ],
            'mautic_mcp_openid_metadata' => [
                'path'       => '/.well-known/openid-configuration',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::authorizationServerMetadataAction',
                'method'     => ['GET'],
            ],

            // RFC 7591 dynamic client registration, plus the token and revocation endpoints.
            // The paths deliberately avoid the segments "/oauth" and "/api": Mautic's own
            // API subscriber rewrites the body of any response whose URL contains those,
            // which would mangle the RFC 6749 error shape these endpoints must return.
            'mautic_mcp_oauth_register' => [
                'path'       => '/mcp/register',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::registerAction',
                'method'     => ['POST'],
            ],
            'mautic_mcp_oauth_token' => [
                'path'       => '/mcp/token',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::tokenAction',
                'method'     => ['POST'],
            ],
            'mautic_mcp_oauth_revoke' => [
                'path'       => '/mcp/revoke',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::revokeAction',
                'method'     => ['POST'],
            ],
        ],

        'main' => [
            // Behind /s/, so Mautic's own firewall makes the user log in before approving.
            'mautic_mcp_oauth_authorize' => [
                'path'       => '/mcp/authorize',
                'controller' => 'MauticPlugin\MauticMcpBundle\Controller\OauthController::authorizeAction',
                'method'     => ['GET', 'POST'],
            ],
        ],
    ],

    'parameters' => [
        // Master switch, on top of publishing the plugin under Settings, Plugins.
        'mcp_enabled' => true,
        // Writing is off by default. An installation has to opt in.
        'mcp_allow_write'       => false,
        'mcp_allow_delete'      => false,
        'mcp_allow_passthrough' => false,
        // OAuth 2.1 with dynamic client registration, which is how Claude and ChatGPT connect.
        'mcp_allow_oauth' => true,
        // HTTP basic auth with Mautic credentials. Off by default.
        'mcp_allow_basic_auth' => false,
        // A fixed bearer token for clients that cannot run an OAuth flow.
        'mcp_static_token'         => '',
        'mcp_static_token_user_id' => 0,
        // Seconds.
        'mcp_access_token_lifetime'  => 3600,
        'mcp_refresh_token_lifetime' => 2592000,
    ],

    'services' => [
        'events' => [
            'mautic.mcp.config.subscriber' => [
                'class' => \MauticPlugin\MauticMcpBundle\EventListener\ConfigSubscriber::class,
            ],
            'mautic.mcp.exception.subscriber' => [
                'class' => \MauticPlugin\MauticMcpBundle\EventListener\McpExceptionSubscriber::class,
            ],
        ],
        'forms' => [
            'mautic.mcp.form.type.config' => [
                'class'     => \MauticPlugin\MauticMcpBundle\Form\Type\ConfigType::class,
                'arguments' => ['doctrine.orm.entity_manager'],
                'tags'      => ['form.type'],
            ],
        ],
        'integrations' => [
            'mautic.integration.mcp' => [
                'class'     => \MauticPlugin\MauticMcpBundle\Integration\McpIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
    ],
];
