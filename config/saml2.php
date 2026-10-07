<?php

declare(strict_types=1);
use JeffersonGoncalves\LaravelSaml2\Http\Controllers\Saml2Controller;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant model
    |--------------------------------------------------------------------------
    |
    | Eloquent model holding one row per Identity Provider. Custom models must
    | extend JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant.
    |
    */

    'tenant_model' => Saml2Tenant::class,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | GET       {prefix}/{tenant}/login     saml2.login
    | GET       {prefix}/{tenant}/logout    saml2.logout
    | GET       {prefix}/{tenant}/metadata  saml2.metadata
    | POST      {prefix}/{tenant}/acs       saml2.acs
    | GET|POST  {prefix}/{tenant}/sls       saml2.sls
    |
    | {tenant} accepts the tenant UUID or its friendly key ("okta", "acme").
    | The "web" middleware group is required for the login to persist in the
    | session. ACS and SLS are excluded from CSRF verification automatically.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'saml2',
        'middleware' => ['web'],
        'controller' => Saml2Controller::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant resolution
    |--------------------------------------------------------------------------
    |
    | Columns matched against the {tenant} route parameter, in order. For
    | subdomain or any other strategy, register a resolver:
    | Saml2::resolveTenantUsing(fn (Request $request) => ...).
    |
    */

    'resolve_tenant_by' => ['uuid', 'key'],

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | Fallback destinations when neither the request (returnTo / RelayState)
    | nor the tenant (relay_state_url) provides one.
    |
    */

    'redirects' => [
        'login' => env('SAML2_LOGIN_REDIRECT', '/'),
        'logout' => env('SAML2_LOGOUT_REDIRECT', '/'),
        'error' => env('SAML2_ERROR_REDIRECT', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed redirect hosts
    |--------------------------------------------------------------------------
    |
    | RelayState / returnTo are only followed when relative or pointing to the
    | current host or one of these hosts. Prevents open redirects.
    |
    */

    'allowed_redirect_hosts' => [],

    /*
    |--------------------------------------------------------------------------
    | Login query parameters
    |--------------------------------------------------------------------------
    |
    | Query parameters forwarded from the login route to the IdP SSO URL,
    | e.g. /saml2/acme/login?login_hint=jane@acme.com (Entra ID, Google).
    |
    */

    'login_query_parameters' => ['login_hint', 'domain_hint'],

    /*
    |--------------------------------------------------------------------------
    | Single Logout
    |--------------------------------------------------------------------------
    |
    | When the IdP notifies a logout (or confirms one), log the user out of
    | this guard and invalidate the session. Set guard to null to handle it
    | yourself through the SignedOut event.
    |
    */

    'logout' => [
        'guard' => 'web',
        'invalidate_session' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | Public URL of the application (e.g. https://app.example.com). Leave
    | empty to use the current request, which honours TrustProxies. Set it
    | when a proxy or non-standard port makes the received URL differ from
    | the public one ("response was received at http://... instead of https://").
    |
    */

    'base_url' => env('SAML2_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Signature validation from the raw query string
    |--------------------------------------------------------------------------
    |
    | Validate HTTP-Redirect signatures against the raw query string instead
    | of re-encoded parameters. Required by ADFS and other IdPs that encode
    | URLs with lower-case hex.
    |
    */

    'retrieve_parameters_from_server' => false,

    /*
    |--------------------------------------------------------------------------
    | Replay protection
    |--------------------------------------------------------------------------
    |
    | Reject an assertion whose ID was already consumed. Uses the default
    | cache store (or the one named here).
    |
    */

    'replay_protection' => [
        'enabled' => true,
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribute map
    |--------------------------------------------------------------------------
    |
    | Friendly name => candidate SAML attribute names (Name or FriendlyName).
    | Saml2User::mapped() returns the first value found for each key.
    |
    */

    'attribute_map' => [
        'email' => [
            'email',
            'mail',
            'emailAddress',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
            'urn:oid:0.9.2342.19200300.100.1.3',
        ],
        'name' => [
            'name',
            'displayName',
            'http://schemas.microsoft.com/identity/claims/displayname',
            'urn:oid:2.16.840.1.113730.3.1.241',
        ],
        'first_name' => [
            'firstName',
            'givenName',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
            'urn:oid:2.5.4.42',
        ],
        'last_name' => [
            'lastName',
            'surname',
            'sn',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
            'urn:oid:2.5.4.4',
        ],
        'groups' => [
            'groups',
            'memberOf',
            'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Disable to use your own migration (publish it with
    | php artisan vendor:publish --tag=saml2-migrations).
    |
    */

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Toolkit settings
    |--------------------------------------------------------------------------
    |
    | Shared by every tenant. A tenant can override any of these through its
    | "settings" JSON column, and Saml2::configureUsing() can change them at
    | runtime. The "idp" block and SP URLs are filled per tenant.
    |
    */

    'strict' => true,

    'debug' => env('SAML2_DEBUG', false),

    'sp' => [
        // Leave empty to use the tenant metadata URL.
        'entityId' => env('SAML2_SP_ENTITY_ID', ''),

        // PEM string or path to a .crt/.key file (see saml2:generate-cert).
        'x509cert' => env('SAML2_SP_X509_CERT', ''),
        'privateKey' => env('SAML2_SP_PRIVATE_KEY', ''),
    ],

    'security' => [
        'nameIdEncrypted' => false,
        'authnRequestsSigned' => false,
        'logoutRequestSigned' => false,
        'logoutResponseSigned' => false,
        'signMetadata' => false,
        'wantMessagesSigned' => false,
        'wantAssertionsSigned' => false,
        'wantNameIdEncrypted' => false,
        'requestedAuthnContext' => true,
        'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
    ],

    'contactPerson' => [
        'technical' => [
            'givenName' => env('SAML2_CONTACT_TECHNICAL_NAME', 'Technical'),
            'emailAddress' => env('SAML2_CONTACT_TECHNICAL_EMAIL', 'no-reply@example.com'),
        ],
        'support' => [
            'givenName' => env('SAML2_CONTACT_SUPPORT_NAME', 'Support'),
            'emailAddress' => env('SAML2_CONTACT_SUPPORT_EMAIL', 'no-reply@example.com'),
        ],
    ],

    'organization' => [
        'en-US' => [
            'name' => env('SAML2_ORGANIZATION_NAME', env('APP_NAME', 'Laravel')),
            'displayname' => env('SAML2_ORGANIZATION_NAME', env('APP_NAME', 'Laravel')),
            'url' => env('SAML2_ORGANIZATION_URL', env('APP_URL', 'http://localhost')),
        ],
    ],
];
