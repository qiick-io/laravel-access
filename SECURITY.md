# Security Policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| Untagged `develop` / pre-`0.1.0` | Best effort while the package is in development. |
| Tagged `0.x` / `1.x` (once published) | Documented here when the first tag lands. |

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
("Report a vulnerability" on the Security tab of
`qiick-io/laravel-access`). We aim to acknowledge reports within 72 hours.

This package is the authorization boundary shared by every qiick product.
Reports about super-admin elevation through API keys or OAuth grants, forged
actor context, soft-deleted group grants, organisation/team bleed, Policy
bypass, stale permission caches after demotion, or 2FA middleware skips are
treated as critical.

Coordinate disclosure with `qiick/laravel-api-keys` and `qiick/laravel-oidc`
when a finding spans packages.

## Trusted release path

Tagged releases are cut from `main` with GitHub attestations / build provenance
where available. Packagist publish happens only after Luca’s explicit go. Never
trust an untagged `develop` commit as a production dependency.
