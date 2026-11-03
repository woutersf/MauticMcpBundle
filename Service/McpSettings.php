<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;

/**
 * Reads the plugin configuration: whether the MCP server is on, and how much it may do.
 *
 * The server is off unless the plugin is published under Settings, Plugins, and the
 * Mautic REST API is enabled under Configuration, API Settings. Writing is off by default:
 * an installation that only wants a read only assistant does not have to change anything.
 */
class McpSettings
{
    public const VERSION = '1.1.1';

    public const INTEGRATION_NAME = 'Mcp';

    public function __construct(
        private CoreParametersHelper $coreParameters,
        private IntegrationHelper $integrationHelper,
    ) {
    }

    public function isPluginPublished(): bool
    {
        $integration = $this->integrationHelper->getIntegrationObject(self::INTEGRATION_NAME);

        if (!$integration) {
            return false;
        }

        $settings = $integration->getIntegrationSettings();

        return null !== $settings && $settings->isPublished();
    }

    public function isApiEnabled(): bool
    {
        return (bool) $this->coreParameters->get('api_enabled', false);
    }

    public function isEnabled(): bool
    {
        return $this->isPluginPublished()
            && $this->isApiEnabled()
            && (bool) $this->coreParameters->get('mcp_enabled', true);
    }

    public function isWriteEnabled(): bool
    {
        return (bool) $this->coreParameters->get('mcp_allow_write', false);
    }

    public function isDeleteEnabled(): bool
    {
        return $this->isWriteEnabled() && (bool) $this->coreParameters->get('mcp_allow_delete', false);
    }

    public function isPassthroughEnabled(): bool
    {
        return (bool) $this->coreParameters->get('mcp_allow_passthrough', false);
    }

    public function isBasicAuthEnabled(): bool
    {
        return (bool) $this->coreParameters->get('mcp_allow_basic_auth', false)
            && (bool) $this->coreParameters->get('api_enable_basic_auth', false);
    }

    public function isOauthEnabled(): bool
    {
        return (bool) $this->coreParameters->get('mcp_allow_oauth', true);
    }

    /**
     * A fixed bearer token, for clients that cannot run the OAuth flow. Empty disables it.
     */
    public function staticToken(): string
    {
        return trim((string) $this->coreParameters->get('mcp_static_token', ''));
    }

    /**
     * The Mautic user id that a static token call acts as.
     */
    public function staticTokenUserId(): int
    {
        return (int) $this->coreParameters->get('mcp_static_token_user_id', 0);
    }

    public function accessTokenLifetime(): int
    {
        return max(300, (int) $this->coreParameters->get('mcp_access_token_lifetime', 3600));
    }

    public function refreshTokenLifetime(): int
    {
        return max(3600, (int) $this->coreParameters->get('mcp_refresh_token_lifetime', 2592000));
    }

    public function siteUrl(): string
    {
        return rtrim((string) $this->coreParameters->get('site_url', ''), '/');
    }
}
