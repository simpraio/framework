# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |

## Reporting a Vulnerability

Please do not report security vulnerabilities through public GitHub issues.

Email **security@simpra.io** with:

- A description of the vulnerability
- Steps to reproduce
- Affected version(s)
- Potential impact

You will receive a response within 5 business days. If the issue is confirmed,
we will release a patch and credit you in the changelog unless you prefer
to remain anonymous.

## Scope

The following are in scope:

- Authentication and session handling (`extensions/auth`)
- CSRF protection (`extensions/csrf`)
- Rate limiting (`extensions/ratelimit`)
- Security headers (`extensions/security`)
- Input validation (`extensions/validation`)
- Core request/response handling and route parsing

## Out of Scope

- Vulnerabilities in your application code that uses this framework
- Issues requiring physical access to the server
- Social engineering
