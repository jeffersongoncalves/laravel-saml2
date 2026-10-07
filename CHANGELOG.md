# Changelog

All notable changes to `laravel-saml2` will be documented in this file.

## v1.0.0 - 2026-10-07

First public release of **laravel-saml2**: a multi-tenant SAML 2.0 Service Provider for Laravel 11, 12 and 13, built on onelogin/php-saml.

### Highlights

- 🏢 One Identity Provider per tenant, resolved by UUID **or friendly key** (`/saml2/acme/login`), or by a custom resolver (subdomains, headers…).
- ⚙️ Per-tenant toolkit settings (`settings` JSON column) and a runtime `Saml2::configureUsing()` hook; multiple IdP signing certificates (`x509certMulti`).
- 📥 IdP metadata import (`saml2:create-tenant --metadata-url`) and schedulable `saml2:sync-metadata` for certificate rollover.
- 🔐 Open-redirect protection on RelayState/returnTo, assertion replay protection, strict mode on by default.
- 🚪 Single Logout in both directions: `SignedOut` carries tenant, NameID and SessionIndex; local guard logout and session invalidation built in.
- 🌐 Proxy friendly: received URL taken from the Laravel request (TrustProxies) or `SAML2_BASE_URL`.
- ⚡ No `exit()`: every step returns a Laravel response — sessions are saved and Octane works.
- 🧰 Commands: create/update/list/delete/restore tenants, tenant credentials, sync metadata, generate SP certificate.
- 🧪 End-to-end tests against real signed SAML messages.

### Installation

```bash
composer require jeffersongoncalves/laravel-saml2
php artisan migrate

```
See the [README](https://github.com/jeffersongoncalves/laravel-saml2#readme) for setup and IdP-specific notes (Entra ID, Okta, Google Workspace, Keycloak, ADFS).
