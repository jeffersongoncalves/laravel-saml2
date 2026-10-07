# Laravel SAML2

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/jeffersongoncalves)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-saml2.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-saml2)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-saml2.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-saml2)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-saml2/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-saml2/actions?query=workflow%3Atests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-saml2/pint.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/jeffersongoncalves/laravel-saml2/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![License](https://img.shields.io/packagist/l/jeffersongoncalves/laravel-saml2.svg?style=flat-square)](LICENSE.md)

Multi-tenant **SAML 2.0 Service Provider** for Laravel. Connect any number of Identity Providers —
Microsoft Entra ID (Azure AD), Okta, Google Workspace, Keycloak, ADFS, OneLogin, JumpCloud, Auth0 —
each stored as a row in the database, on top of the battle-tested
[OneLogin SAML toolkit](https://github.com/SAML-Toolkits/php-saml).

- 🏢 **One IdP per tenant**, resolved from the URL by UUID **or a friendly key** (`/saml2/acme/login`), or by your own resolver (subdomains, headers…).
- ⚙️ **Per-tenant toolkit settings** (security flags, SP entity ID, multiple IdP certificates) plus a runtime `Saml2::configureUsing()` hook.
- 📥 **IdP metadata import** (`--metadata-url`) and a schedulable `saml2:sync-metadata` to follow certificate rollovers.
- 🔐 **Secure by default**: open-redirect protection on `RelayState`/`returnTo`, assertion **replay protection**, strict mode on.
- 🚪 **Real Single Logout**: `SignedOut` carries the tenant, NameID and SessionIndex, and the local session is closed for you.
- 🌐 **Proxy friendly**: works behind load balancers / TLS terminators / non-standard ports (`SAML2_BASE_URL`).
- ⚡ **No `exit()`**: every step returns a Laravel response, so sessions are saved and Octane works.
- 🧪 Tested end-to-end against real signed SAML messages.

## Compatibility

| Package | Laravel          |
|---------|------------------|
| 1.x     | 11.x, 12.x, 13.x |

## Installation

```bash
composer require jeffersongoncalves/laravel-saml2
php artisan migrate
```

Optionally publish the config (and the migration if you want to customise it):

```bash
php artisan vendor:publish --tag=saml2-config
php artisan vendor:publish --tag=saml2-migrations
```

## Quick start

### 1. Register an Identity Provider

From the IdP metadata URL (recommended — entity ID, URLs and certificates are filled for you):

```bash
php artisan saml2:create-tenant --key=acme \
    --metadata-url="https://login.microsoftonline.com/<tenant-id>/federationmetadata/2007-06/federationmetadata.xml?appid=<app-id>"
```

Or by hand:

```bash
php artisan saml2:create-tenant --key=okta \
    --entity-id="http://www.okta.com/exk123" \
    --login-url="https://acme.okta.com/app/acme_app/exk123/sso/saml" \
    --logout-url="https://acme.okta.com/app/acme_app/exk123/slo/saml" \
    --x509cert="MIIDqDCCApCgAwIBAgIGAX..." \
    --name-id-format=emailAddress
```

The command prints what to configure at the IdP:

```
Identifier (Entity ID) ............ https://app.test/saml2/9c1d…/metadata
Reply URL (Assertion Consumer Service) https://app.test/saml2/9c1d…/acs
Logout URL (Single Logout Service) .. https://app.test/saml2/9c1d…/sls
SP metadata ....................... https://app.test/saml2/9c1d…/metadata
Sign on URL ....................... https://app.test/saml2/acme/login
```

Protocol endpoints always use the tenant UUID so they never change; the user-facing login/logout
links use the friendly key.

### 2. Log the user in

Listen to `SignedIn`, for example in `App\Providers\AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use JeffersonGoncalves\LaravelSaml2\Events\SignedIn;

Event::listen(function (SignedIn $event) {
    $saml = $event->user;

    $user = User::firstOrCreate(
        ['email' => $saml->email()],          // lower-cased, from the mapped attributes or the NameID
        ['name' => $saml->mapped()['name'] ?? $saml->email()],
    );

    Auth::login($user);

    // Also available: $event->request->ip(), $event->auth->tenant(), $saml->attributes(), $saml->sessionIndex()
});
```

The routes run in the `web` middleware group, so the login is persisted in the session and the user
is redirected to the `RelayState` / tenant `relay_state_url` / `SAML2_LOGIN_REDIRECT`.

### 3. Send users to the IdP

```php
use JeffersonGoncalves\LaravelSaml2\Facades\Saml2;

Saml2::loginUrl('acme', returnTo: '/dashboard');   // https://app.test/saml2/acme/login?returnTo=%2Fdashboard
```

```blade
<a href="{{ route('saml2.login', 'acme') }}">Sign in with Acme SSO</a>
```

To force SSO on protected routes, point Laravel's `auth` middleware to the login route
(`bootstrap/app.php`):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->redirectGuestsTo(fn () => route('saml2.login', 'acme'));
})
```

Extra query parameters listed in `login_query_parameters` are forwarded to the IdP, e.g.
`/saml2/acme/login?login_hint=jane@acme.com` pre-fills the user name at Entra ID and Google.
Add `?force=1` to require re-authentication (`ForceAuthn`).

## Routes

| Method   | URI                         | Name             |
|----------|-----------------------------|------------------|
| GET      | `saml2/{tenant}/login`      | `saml2.login`    |
| GET      | `saml2/{tenant}/logout`     | `saml2.logout`   |
| GET      | `saml2/{tenant}/metadata`   | `saml2.metadata` |
| POST     | `saml2/{tenant}/acs`        | `saml2.acs`      |
| GET/POST | `saml2/{tenant}/sls`        | `saml2.sls`      |

`{tenant}` is the UUID or the key (numeric IDs are never accepted from URLs). ACS and SLS are
excluded from CSRF verification automatically. Prefix, middleware and controller are configurable
under `routes`; set `routes.enabled` to `false` to register your own routes with the same names.

## Logout

**Started by your app** — send the user to `saml2.logout` (or `Saml2::logoutUrl(returnTo: '/bye')`).
The IdP receives a `LogoutRequest` with the NameID and SessionIndex stored at login, logs the user out
everywhere and comes back to `saml2.sls`, which closes the local session and redirects to `returnTo`
(or `SAML2_LOGOUT_REDIRECT`). When the tenant has no IdP logout URL, the user is logged out locally
right away.

```php
// e.g. on session timeout, or from your own logout action
return redirect(Saml2::logoutUrl(returnTo: route('saml2.login', 'acme')));
```

**Started by the IdP** — the IdP calls `saml2.sls` with a `LogoutRequest`. The package fires
`SignedOut`, logs the user out of the configured guard, invalidates the session and answers the IdP.

```php
use JeffersonGoncalves\LaravelSaml2\Events\SignedOut;

Event::listen(function (SignedOut $event) {
    // $event->tenant, $event->nameId, $event->sessionIndex, $event->request
    // Fired before the local logout, so Auth::user() is still available.
});
```

Set `logout.guard` to `null` / `logout.invalidate_session` to `false` to handle it yourself.

## Events

| Event          | When                                    | Payload                                               |
|----------------|-----------------------------------------|-------------------------------------------------------|
| `SignedIn`     | A SAMLResponse was validated            | `user` (`Saml2User`), `auth` (`Saml2Auth`), `request` |
| `SignedOut`    | Single Logout (either direction)        | `tenant`, `nameId`, `sessionIndex`, `request`         |
| `Saml2Failed`  | A response / logout message was rejected | `tenant`, `step` (`login`/`logout`), `errors`, `reason`, `request` |

On failure the user is redirected to `SAML2_ERROR_REDIRECT` with `saml2.error` (error codes) and
`saml2.error_reason` flashed to the session, and the reason is logged.

## The SAML user

```php
$saml->nameId();               // "jane@acme.com"
$saml->email();                // lower-cased e-mail
$saml->attributes();           // ['http://schemas…/emailaddress' => ['Jane@Acme.com'], …]
$saml->attribute('groups');    // all values, by Name or FriendlyName
$saml->first('department');    // first value
$saml->mapped();               // ['email' => …, 'name' => …, 'first_name' => …, 'last_name' => …, 'groups' => […]]
$saml->sessionIndex();
$saml->tenant();
```

`mapped()` resolves the `attribute_map` config, which already knows the common Entra ID, ADFS,
Okta, Google and LDAP OID attribute names. Add your own keys there.

## Per-tenant settings

Every tenant has a `settings` JSON column merged over the global config, so IdPs can differ in
security flags, SP entity ID, signature algorithms, etc.:

```bash
php artisan saml2:update-tenant acme --settings='{"security":{"wantAssertionsSigned":true},"sp":{"entityId":"urn:acme:app"}}'
```

Multiple IdP signing certificates (key rollover) go in `idp.x509certMulti` — the metadata import
does this automatically:

```json
{"idp": {"x509certMulti": {"signing": ["MIIC…old", "MIIC…new"]}}}
```

For anything dynamic, change the settings at runtime:

```php
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;

Saml2::configureUsing(function (array $settings, Saml2Tenant $tenant) {
    $settings['idp']['singleSignOnService']['url'] .= '?region='.$tenant->metadata['region'];

    return $settings;
});
```

## Custom tenant resolution

Resolve tenants by subdomain (or anything else) instead of the URL segment:

```php
use Illuminate\Http\Request;

Saml2::resolveTenantUsing(fn (Request $request) => Saml2::tenants()
    ->where('key', explode('.', $request->getHost())[0])
    ->first());
```

`tenant_model` lets you use your own model (it must extend `Saml2Tenant`), e.g. to add relations to
your own `teams` / `organizations` table.

## Certificates

### Service Provider certificate

Needed to sign requests or receive encrypted assertions:

```bash
php artisan saml2:generate-cert            # storage/saml2/sp.crt + sp.key
```

```dotenv
SAML2_SP_X509_CERT=storage/saml2/sp.crt     # a path or the PEM itself
SAML2_SP_PRIVATE_KEY=storage/saml2/sp.key
```

On Windows PHP builds without an `openssl.cnf`, pass `--openssl-config=C:\path\to\openssl.cnf`.

### IdP certificate rollover

Tenants created with `--metadata-url` can be refreshed on a schedule (`routes/console.php`):

```php
Schedule::command('saml2:sync-metadata')->daily();
```

## Behind a proxy, HTTPS or a non-standard port

The toolkit compares the URL it received with the `Destination` the IdP signed. The package feeds it
the scheme/host/port of the Laravel request, so configuring
[TrustProxies](https://laravel.com/docs/requests#configuring-trusted-proxies) is usually enough.
If the public URL still differs (TLS terminated upstream, port 8080 internally, a path prefix at the
load balancer), set it explicitly:

```dotenv
SAML2_BASE_URL=https://app.example.com
```

It is used both for the SP URLs sent to the IdP and for validating incoming messages, which fixes
*"The response was received at http://… instead of https://…"*.

## Sessions and SameSite cookies

The IdP posts the response cross-site, so browsers do not send `SameSite=Lax` cookies with it: the
login works (a fresh session is started), but anything stored in the session **before** going to the
IdP is not visible in the `SignedIn` listener. Carry state in `returnTo` instead, or set
`SESSION_SAME_SITE=none` (requires `SESSION_SECURE_COOKIE=true`).

## Identity Provider notes

| IdP | Notes |
|-----|-------|
| **Microsoft Entra ID** | Use the *App Federation Metadata Url* with `--metadata-url`. Identifier = SP Entity ID, Reply URL = ACS. `AADSTS750054` means Entra received no `SAMLRequest`: start the flow from `saml2.login` (not from the Entra URL directly), check the tenant login URL is `https://login.microsoftonline.com/<tenant-id>/saml2`, and make sure nothing in between strips the query string. *Invalid audience* means the Identifier at Entra differs from the SP entity ID — set `SAML2_SP_ENTITY_ID` or the tenant `sp.entityId` to what Entra expects. |
| **Okta** | Single sign-on URL = ACS, Audience URI = SP Entity ID. Use the *Metadata URL* from the Sign On tab. |
| **Google Workspace** | ACS URL and Entity ID from `saml2:tenant-credentials`; Name ID format `EMAIL` → `--name-id-format=emailAddress`. |
| **Keycloak** | Client ID = SP Entity ID. Either disable *Client signature required* or generate an SP certificate and enable `security.authnRequestsSigned`. Metadata: `https://<host>/realms/<realm>/protocol/saml/descriptor`. |
| **ADFS** | Set `retrieve_parameters_from_server` to `true` (ADFS URL-encodes signatures in lower case). |

## Configuration reference

See [`config/saml2.php`](config/saml2.php). Environment variables:

| Variable | Default | |
|----------|---------|---|
| `SAML2_LOGIN_REDIRECT` | `/` | After login when no RelayState |
| `SAML2_LOGOUT_REDIRECT` | `/` | After logout when no returnTo |
| `SAML2_ERROR_REDIRECT` | `/` | After a rejected message |
| `SAML2_BASE_URL` | – | Public URL of the app |
| `SAML2_SP_ENTITY_ID` | metadata URL | Shared SP entity ID |
| `SAML2_SP_X509_CERT` / `SAML2_SP_PRIVATE_KEY` | – | PEM or path |
| `SAML2_DEBUG` | `false` | Toolkit debug mode |

## Commands

| Command | |
|---------|---|
| `saml2:create-tenant` | Register an IdP (`--metadata-url` or explicit options) |
| `saml2:update-tenant {tenant}` | Change only the given options |
| `saml2:list-tenants [--trashed]` | List IdPs |
| `saml2:tenant-credentials {tenant}` | Show the SP URLs to configure at the IdP |
| `saml2:delete-tenant {tenant} [--force]` | Soft (or permanent) delete |
| `saml2:restore-tenant {tenant}` | Undo a soft delete |
| `saml2:sync-metadata [tenant]` | Refresh IdP data from the metadata URL |
| `saml2:generate-cert` | Create the SP certificate and key |

`{tenant}` accepts the ID, UUID or key.

## Existing `saml2_tenants` tables

If your database already has a `saml2_tenants` table with the usual columns (`uuid`, `key`,
`idp_entity_id`, `idp_login_url`, `idp_logout_url`, `idp_x509_cert`, `relay_state_url`,
`name_id_format`, `metadata`), the migration upgrades it in place: it adds `idp_metadata_url` and
`settings` and makes the optional columns nullable. Rows and UUIDs are kept, so the
`/saml2/{uuid}/acs|sls|metadata` URLs registered at your IdPs keep working. Route names are
`saml2.*`, the events live in `JeffersonGoncalves\LaravelSaml2\Events`, and the facade is
`JeffersonGoncalves\LaravelSaml2\Facades\Saml2`.

Rolling the migration back drops the table.

## Testing

```bash
composer test
composer analyse
composer format
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please report security issues by e-mail to gerson.simao.92@gmail.com instead of opening a public issue.

## Credits

- [Jefferson Gonçalves](https://github.com/jeffersongoncalves)
- [SAML-Toolkits/php-saml](https://github.com/SAML-Toolkits/php-saml)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
