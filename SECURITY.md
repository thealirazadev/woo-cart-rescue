# Security Policy

## Supported versions

Security fixes are provided for the latest released `1.x` line.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |
| < 1.0   | No        |

## Reporting a vulnerability

Please report suspected vulnerabilities privately rather than opening a public issue.

- Use GitHub's private vulnerability reporting: open the repository's **Security** tab and choose
  **Report a vulnerability**. This opens a private advisory visible only to the maintainers.

When reporting, include as much of the following as you can:

- The affected version and environment (WordPress and WooCommerce versions, PHP version).
- A description of the issue and its impact.
- Step-by-step reproduction, and a proof of concept if one exists.

You can expect an initial acknowledgement within a few days. Once a fix is prepared we will
coordinate a release and, with your consent, credit you in the advisory. Please do not disclose the
details publicly until a fix has shipped.

## Scope

This is a WooCommerce extension that runs inside WordPress. Reports are most useful when they
concern the plugin's own surface:

- The token-authenticated restore and unsubscribe endpoints and their HMAC verification.
- The guest-capture AJAX endpoint and its consent and nonce gates.
- SQL, output escaping, or capability checks in the plugin's own code.

Vulnerabilities in WordPress core, WooCommerce, or unrelated plugins should be reported to those
projects directly.
