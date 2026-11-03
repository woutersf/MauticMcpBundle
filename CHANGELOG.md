# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-03

### Fixed

- Email and landing page HTML written through the MCP tools can now be opened in the
  GrapesJS builder. The builder parses the stored `customHtml` and refuses any document
  whose `<head>` or `<body>` parses empty, which an assistant writing email HTML hits
  every time, because it emits a `<body>` and no `<head>`. Nothing on the server side
  cares, so the email looked correct in the list, in the preview pane and in the sent
  message, and failed only in the builder, with a white screen and an uncaught
  `No valid HTML template found`. `BuilderHtmlNormalizer` now gives such a document a
  minimal `<head>` on the way in. A document that already has a non-empty `<head>` is
  stored byte for byte unchanged.

### Added

- `/.well-known/oauth-authorization-server/mcp`, the path aware form of the authorization
  server metadata. RFC 8414 section 3.1 has clients try that before falling back to the
  root path, so this removes one failed request from every connection handshake.
- A `customHtml` note in the `mautic_create_email` and `mautic_update_email` tool
  descriptions, so a client writes a complete document rather than relying on the repair.
- Packaging for use as a standalone repository: tests, PHPStan, CI across PHP 8.2 to 8.4,
  a contributing guide and this changelog.

## [1.0.0] - 2026-11-03

### Added

- First release. A stateless Streamable HTTP MCP server at `/mcp`, 47 tools over the
  Mautic REST API, MCP prompts, and three ways to authenticate: OAuth 2.1 with dynamic
  client registration, a fixed bearer token, and HTTP basic auth.

[Unreleased]: https://github.com/woutersf/MauticMcpBundle/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/woutersf/MauticMcpBundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/woutersf/MauticMcpBundle/releases/tag/v1.0.0
