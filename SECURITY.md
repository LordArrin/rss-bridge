# Security Policy

## Supported Versions

Only the latest stable release and the current `master` branch of this fork are supported with security updates.

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |
| < Latest| :x:                |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

If you have discovered a security vulnerability in this project, please report it privately. This gives us time to work with you to fix the issue before public exposure, reducing the chance that the exploit will be used before a patch is released.

### How to Report

Please use GitHub's private vulnerability reporting feature:

1. Navigate to the [Report a vulnerability](https://github.com/LordArrin/rss-bridge/security/advisories/new) page.
2. Fill in the form with as much detail as possible.
3. We will acknowledge receipt of your report within **3 business days**.

### What to Include in Your Report

To help us understand and triage the issue quickly, please include the following:
- Type of issue (e.g., SSRF, RCE, XSS, authentication bypass, etc.)
- Step-by-step instructions to reproduce the issue.
- Proof-of-concept or exploit code (if applicable).
- Potential impact on the application and its users.

## Scope

The following areas are considered in-scope for security reporting:
- The core library (`lib/`, `middlewares/`, `proxies/`).
- Official bridges shipped with the repository (`bridges-v2/`).
- The Docker build process, entrypoint scripts, and configuration handling.
- Authentication bypass and Remote Code Execution (RCE) vectors.

### Out of Scope

- Vulnerabilities in third-party dependencies (unless you have a patch or a mitigation strategy for this project).
- Issues related to specific external websites being scraped by the bridges.
- Social engineering or phishing attacks against users of this software.
- Custom bridges added by the end-user in their own environment (`/config/bridges-v2/`), unless the vulnerability stems from a flaw in the `SafeBridgeLoader` or core execution environment.

## Security Best Practices for Deployment

Since RSS-Bridge is a web scraper that handles user-defined parameters, please follow these guidelines when deploying the application:

1. **Volume Permissions**: Ensure the mounted `/config` directory and especially `bridges-v2/` are strictly read-only or only writable by trusted administrators. A compromised write-access to `bridges-v2/` will lead to Remote Code Execution (RCE) inside the container.
2. **Network Isolation**: Run the container behind a reverse proxy and restrict access via IP whitelisting or OAuth (e.g., Authentik, Authelia) if you do not use the built-in authentication.
3. **Environment Variables**: Do not commit your `config.ini.php` or environment variables containing sensitive tokens/proxy credentials to version control.

## Response Timeline

- We will acknowledge receipt of your vulnerability report within **3 business days**.
- We will provide a detailed response and triage status within **14 days**.
- We will work on a patch and coordinate the release with you.
- We believe in **Responsible Disclosure** and will publicly credit researchers who report valid vulnerabilities (unless you prefer to remain anonymous).
