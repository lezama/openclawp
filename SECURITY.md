# Security Policy

openclaWP is developer-preview software. Do not run it on production sites or
with personal messaging accounts unless you have reviewed the risks in
`README.md`.

## Supported versions

| Version | Security fixes |
| --- | --- |
| `0.1.x` | Best effort while the plugin is in preview |

## Reporting a vulnerability

Please report security issues privately through
[GitHub Security Advisories](https://github.com/lezama/openclawp/security/advisories/new).

If GitHub advisories are unavailable, contact the maintainer through the
repository before opening a public issue. Include reproduction steps, affected
versions, and whether the issue requires a configured AI provider or WhatsApp
Cloud API.

## Security-sensitive areas

The highest-risk surfaces are:

* REST routes and ability permission callbacks.
* Agent tool execution and workflow input binding.
* WhatsApp webhook verification and outbound message handling.
* AI provider routing, because prompt content and tool results may be sent to
  configured external services.

Use local or staging environments for testing, and keep transport credentials
scoped to sandbox accounts.
