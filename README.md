# Mautic MCP Server

Exposes a Mautic instance as a remote [Model Context Protocol](https://modelcontextprotocol.io)
server, so Claude, ChatGPT and other MCP clients can read and act on it.

The server lives at `https://your-mautic/mcp`. It speaks the Streamable HTTP transport and
is stateless: there is no session to keep between calls, which is what the 2026-07-28
revision of the protocol asks for. Older clients that still send `initialize` first keep
working, from protocol revision 2024-11-05 onwards.

Requires Mautic 7.x and PHP 8.2 or later. Tested on Mautic 7.1.0 with PHP 8.3.

## How it works

The plugin does not re-implement the Mautic API. Each tool runs the matching Mautic REST
API route as an internal sub-request, with the connected user placed in the security token
first. Routing, the API controllers, the permission checks and the JMS serialiser all run
exactly as they do for an outside API call.

That has one consequence worth stating plainly: **this plugin cannot widen what a user is
allowed to see or do.** A connected assistant acting as a Mautic user with read only
permissions on contacts gets 403 on everything else, from Mautic itself.

```
MCP client  ──POST /mcp──►  McpController
                                │  authenticate (OAuth token, fixed token, or basic auth)
                                ▼
                            McpServer   (JSON-RPC 2.0: initialize, tools/list, tools/call…)
                                │
                                ▼
                            ToolExecutor ──► MauticApiBridge ──► Mautic REST API route
                                                                 (permissions enforced here)
```

## Install

Copy or symlink the plugin into `plugins/MauticMcpBundle`, then:

```bash
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Three tables are created: `plugin_mcp_oauth_clients`, `plugin_mcp_oauth_codes` and
`plugin_mcp_oauth_tokens`.

Then:

1. **Settings → Plugins → Mautic MCP Server**, set it to published.
2. **Configuration → API Settings**, enable the Mautic REST API. The MCP endpoint refuses
   to answer while the API is off.
3. **Configuration → MCP Server**, choose what the server may do.

## Configuration

| Setting | Default | What it does |
| --- | --- | --- |
| Enable the MCP endpoint | yes | Master switch for `/mcp`. |
| Allow OAuth connections | yes | Lets clients register themselves and ask a logged in Mautic user for approval. This is how Claude and ChatGPT connect. |
| Allow writing | **no** | Offers the tools that create and change records. With it off, the server advertises read only tools and nothing else. |
| Allow deleting and sending | **no** | Offers the tools that permanently delete records and send email to real recipients. Needs writing to be allowed. |
| Allow the raw API tool | **no** | Offers `mautic_api_request`, which can call any Mautic REST API route. Powerful, and harder to predict than the named tools. |
| Allow HTTP basic auth | **no** | Accepts a Mautic username and password on `/mcp`. Also needs basic auth enabled in API Settings. |
| Fixed bearer token | empty | A token for clients that cannot run an OAuth flow. Every call made with it acts as the user selected next to it. |
| Access token lifetime | 3600 s | How long an issued access token stays valid. Clients refresh automatically. |
| Refresh token lifetime | 2592000 s | How long a client can refresh before the user has to approve again. |

The defaults are the cautious ones. A fresh install is read only until somebody decides
otherwise.

### A note on permissions for non-admin users

A Mautic user who is not an administrator needs the **API → Access granted** permission on
their role, on top of the permissions for the records they should reach. Without it every
API route answers 403, and so does every MCP tool.

## Connecting a client

### Claude (claude.ai, Claude Desktop)

Add a custom connector pointing at `https://your-mautic/mcp`. Claude registers itself
through dynamic client registration, sends you to the Mautic login, shows a consent screen
naming the Mautic user the connection will act as, and takes the tokens from there. Nothing
has to be copied by hand.

### Claude Code

```bash
claude mcp add --transport http mautic https://your-mautic/mcp
```

Claude Code runs the same OAuth flow. To use the fixed token instead:

```bash
claude mcp add --transport http mautic https://your-mautic/mcp \
  --header "Authorization: Bearer YOUR_FIXED_TOKEN"
```

### ChatGPT

Add a connector with the server URL `https://your-mautic/mcp`. Pick OAuth and ChatGPT will
register itself, or pick API key authentication and paste the fixed token.

The server also provides the `search` and `fetch` tools that ChatGPT's deep research mode
expects: `search` returns `{id, title, url}` results across contacts, companies, segments,
campaigns, emails, forms and pages, and `fetch` takes one of those ids (`contact:42`) and
returns the full record.

### Anything else

Any MCP client that speaks Streamable HTTP works. Send JSON-RPC over `POST /mcp` with an
`Authorization: Bearer` header.

## OAuth endpoints

| Endpoint | Path |
| --- | --- |
| Protected resource metadata (RFC 9728) | `/.well-known/oauth-protected-resource` |
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server` |
| OpenID Connect discovery (same payload) | `/.well-known/openid-configuration` |
| Dynamic client registration (RFC 7591) | `/mcp/register` |
| Authorization (consent screen) | `/s/mcp/authorize` |
| Token | `/mcp/token` |
| Revocation (RFC 7009) | `/mcp/revoke` |

Both metadata documents are served at the path aware URI as well, with `/mcp` appended:
RFC 8414 section 3.1 tells a client to try that form before it falls back to the root one,
and MCP clients do.

Authorization code with PKCE (S256), refresh tokens with rotation. Tokens are stored as
SHA-256 hashes, so a leaked database row cannot be replayed against the endpoint. The
authorize route sits behind Mautic's own `/s/` firewall, so the user logs into Mautic
before approving, and the approval is bound to that user.

The paths deliberately avoid the segments `/oauth` and `/api`. Mautic rewrites the body of
any response whose URL contains those, which would mangle the error shape OAuth clients
expect.

## Tools

Read tools, always available:

| Tool | What it returns |
| --- | --- |
| `mautic_list_contacts` | Search and page through contacts |
| `mautic_get_contact` | One contact with every field |
| `mautic_get_contact_activity` | A contact's timeline: opens, clicks, page hits, form submissions |
| `mautic_get_contact_segments` | The segments a contact is in |
| `mautic_get_contact_campaigns` | The campaigns a contact is in |
| `mautic_get_contact_notes` | Notes written on a contact |
| `mautic_list_contact_fields` | Every contact field alias, label and type |
| `mautic_list_companies`, `mautic_get_company` | Companies |
| `mautic_list_segments`, `mautic_get_segment` | Segments and their filters |
| `mautic_list_campaigns`, `mautic_get_campaign` | Campaigns and their full event tree |
| `mautic_get_campaign_contacts` | Who is in a campaign |
| `mautic_list_emails`, `mautic_get_email` | Emails with sent, read and failed counters |
| `mautic_list_forms`, `mautic_get_form` | Forms with fields and actions |
| `mautic_get_form_submissions` | Submitted values per form |
| `mautic_list_pages`, `mautic_get_page` | Landing pages |
| `mautic_list_assets`, `mautic_list_tags` | Assets and contact tags |
| `mautic_list_reports`, `mautic_run_report` | Saved reports, run over a date window |
| `mautic_get_stats` | Rows from a Mautic statistics table |
| `mautic_get_dashboard_data` | The numbers behind a dashboard widget |
| `search`, `fetch` | Cross-entity search, and fetch by result id |
| `mautic_whoami` | The Mautic user this connection acts as |

Write tools, only when writing is allowed:

`mautic_create_contact`, `mautic_update_contact`, `mautic_adjust_contact_points`,
`mautic_set_do_not_contact`, `mautic_remove_do_not_contact`, `mautic_create_company`,
`mautic_create_segment`, `mautic_add_contact_to_segment`,
`mautic_remove_contact_from_segment`, `mautic_add_contact_to_campaign`,
`mautic_remove_contact_from_campaign`, `mautic_create_email`, `mautic_update_email`.

Destructive tools, only when deleting and sending is also allowed:

`mautic_delete_contact`, `mautic_send_email_to_contact`, `mautic_send_email_to_segments`.

Escape hatch, only when the raw API tool is allowed:

`mautic_api_request`, which calls any `/api/...` route directly.

## Prompts

The server also offers four prompts, which clients surface as slash commands:
`contact_briefing`, `campaign_health`, `segment_audit` and `weekly_marketing_report`.

## Checking it by hand

```bash
# Discovery
curl https://your-mautic/.well-known/oauth-protected-resource

# Unauthenticated: 401 with a pointer to the metadata
curl -i -X POST https://your-mautic/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'

# With the fixed token
curl -X POST https://your-mautic/mcp \
  -H "Authorization: Bearer YOUR_FIXED_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Troubleshooting

**503 with "The MCP plugin is not enabled"**: publish the plugin under Settings, Plugins.

**503 with "The Mautic REST API is disabled"**: enable the API under Configuration, API
Settings.

**401 on every call**: the token is wrong, expired, or the fixed token has no Mautic user
selected next to it. Check the Mautic log for a warning naming the reason.

**Every tool returns 403**: the connected Mautic user lacks permissions. Call
`mautic_whoami` to see who the connection acts as, then check that role. Non-admin users
also need the API access permission.

**A tool is missing from `tools/list`**: writing, deleting or the raw API tool is switched
off in the configuration. That is the intended behaviour: a tool that is not advertised
cannot be called.

## Related work

Two other projects cover nearby ground:

- [`shinde-rahul/plugin-mautic-mcp`](https://packagist.org/packages/shinde-rahul/plugin-mautic-mcp),
  a read only Mautic plugin built on `symfony/mcp-bundle`, with stdio as its primary
  transport and a handful of contact and campaign tools.
- `Cbrown35/mantic-MCP`, a standalone Node server that talks to the Mautic REST API from
  outside using OAuth2 credentials.

This plugin differs in running inside Mautic with no extra dependency, serving remote
clients over HTTP with its own OAuth 2.1 provider, and covering writes behind explicit
switches.

## License

MIT.
