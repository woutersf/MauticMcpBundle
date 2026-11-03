<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Makes the plugin appear under Settings, Plugins, so it can be switched on and off
 * from the Mautic UI like any other integration.
 */
class McpIntegration extends AbstractIntegration
{
    public function getName(): string
    {
        return 'Mcp';
    }

    public function getDisplayName(): string
    {
        return 'Mautic MCP Server';
    }

    public function getDescription(): string
    {
        $endpoint = $this->router->generate('mautic_mcp_endpoint', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return '<div>Exposes Mautic as a remote Model Context Protocol server, so Claude, ChatGPT and other MCP clients can read and act on this instance.</div>'
            . '<div>Endpoint: <code>' . $endpoint . '</code></div>'
            . '<div>Settings live under Configuration, MCP Server. The Mautic REST API must be enabled as well.</div>';
    }

    public function getIcon(): string
    {
        return 'plugins/MauticMcpBundle/Assets/img/mcp.png';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getRequiredKeyFields(): array
    {
        return [];
    }
}
