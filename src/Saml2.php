<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use OneLogin\Saml2\Auth as OneLoginAuth;

class Saml2
{
    public const SESSION_TENANT = 'saml2.tenant';

    public const SESSION_NAME_ID = 'saml2.name_id';

    public const SESSION_NAME_ID_FORMAT = 'saml2.name_id_format';

    public const SESSION_SESSION_INDEX = 'saml2.session_index';

    /** @var (Closure(Request): ?Saml2Tenant)|null */
    protected ?Closure $tenantResolver = null;

    /** @var list<Closure(array<string, mixed>, Saml2Tenant): array<string, mixed>> */
    protected array $settingsCallbacks = [];

    /**
     * Replace the {tenant} route parameter lookup, e.g. to resolve by subdomain.
     *
     * @param  Closure(Request): ?Saml2Tenant  $resolver
     */
    public function resolveTenantUsing(Closure $resolver): void
    {
        $this->tenantResolver = $resolver;
    }

    /**
     * Adjust the toolkit settings of any tenant at runtime.
     *
     * @param  Closure(array<string, mixed>, Saml2Tenant): array<string, mixed>  $callback
     */
    public function configureUsing(Closure $callback): void
    {
        $this->settingsCallbacks[] = $callback;
    }

    /**
     * @return class-string<Saml2Tenant>
     */
    public function tenantModel(): string
    {
        /** @var class-string<Saml2Tenant> */
        return config('saml2.tenant_model', Saml2Tenant::class);
    }

    /**
     * @return Builder<Saml2Tenant>
     */
    public function tenants(bool $withTrashed = false): Builder
    {
        $query = $this->tenantModel()::query();

        /** @phpstan-ignore method.notFound (SoftDeletes macro) */
        return $withTrashed ? $query->withTrashed() : $query;
    }

    /**
     * Find a tenant by id, UUID or key.
     */
    public function findTenant(int|string $identifier, bool $withTrashed = false): ?Saml2Tenant
    {
        $query = $this->tenants($withTrashed);

        if (is_int($identifier) || ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier)->first();
        }

        return $query->where(function (Builder $query) use ($identifier): void {
            foreach ((array) config('saml2.resolve_tenant_by', ['uuid', 'key']) as $column) {
                $query->orWhere($column, $identifier);
            }
        })->first();
    }

    /**
     * Tenant of the current SAML request, or null when it cannot be resolved.
     */
    public function resolveTenant(Request $request): ?Saml2Tenant
    {
        if ($this->tenantResolver) {
            return ($this->tenantResolver)($request);
        }

        $identifier = $request->route('tenant');

        return is_string($identifier) && ! ctype_digit($identifier) ? $this->findTenant($identifier) : null;
    }

    /**
     * Toolkit instance for a tenant (model, id, UUID or key).
     */
    public function auth(Saml2Tenant|int|string $tenant): Saml2Auth
    {
        $tenant = $tenant instanceof Saml2Tenant ? $tenant : $this->findTenant($tenant);

        if (! $tenant) {
            throw new InvalidArgumentException('SAML2 tenant not found.');
        }

        return new Saml2Auth(new OneLoginAuth($this->settings($tenant)), $tenant);
    }

    /**
     * Tenant the current user signed in with (stored in the session at ACS time).
     */
    public function currentTenant(): ?Saml2Tenant
    {
        $uuid = session(self::SESSION_TENANT);

        return is_string($uuid) ? $this->findTenant($uuid) : null;
    }

    /**
     * Toolkit instance for the tenant the current user signed in with.
     */
    public function current(): ?Saml2Auth
    {
        $tenant = $this->currentTenant();

        return $tenant ? $this->auth($tenant) : null;
    }

    /**
     * URL of the login route, which sends the user to the IdP and back to $returnTo.
     */
    public function loginUrl(Saml2Tenant|int|string|null $tenant = null, ?string $returnTo = null): string
    {
        return $this->linkTo('saml2.login', $tenant, array_filter(['returnTo' => $returnTo]));
    }

    /**
     * URL of the logout route, which starts Single Logout at the IdP.
     */
    public function logoutUrl(Saml2Tenant|int|string|null $tenant = null, ?string $returnTo = null): string
    {
        return $this->linkTo('saml2.logout', $tenant, array_filter(['returnTo' => $returnTo]));
    }

    /**
     * Service Provider URLs to configure at the IdP. Always UUID based so they never change.
     *
     * @return array{entity_id: string, acs: string, sls: string, metadata: string, login: string, logout: string}
     */
    public function serviceProviderUrls(Saml2Tenant $tenant): array
    {
        $metadata = $this->route('saml2.metadata', $tenant->uuid);

        return [
            'entity_id' => (string) (config('saml2.sp.entityId') ?: $metadata),
            'acs' => $this->route('saml2.acs', $tenant->uuid),
            'sls' => $this->route('saml2.sls', $tenant->uuid),
            'metadata' => $metadata,
            'login' => $this->route('saml2.login', $tenant->routeIdentifier()),
            'logout' => $this->route('saml2.logout', $tenant->routeIdentifier()),
        ];
    }

    /**
     * Toolkit settings for a tenant: config, then the tenant "settings" column, then configureUsing() callbacks.
     *
     * @return array<string, mixed>
     */
    public function settings(Saml2Tenant $tenant): array
    {
        $config = (array) config('saml2');
        $urls = $this->serviceProviderUrls($tenant);

        $settings = [
            'strict' => (bool) ($config['strict'] ?? true),
            'debug' => (bool) ($config['debug'] ?? false),
            'sp' => [
                'entityId' => $urls['entity_id'],
                'assertionConsumerService' => ['url' => $urls['acs']],
                'singleLogoutService' => ['url' => $urls['sls']],
                'NameIDFormat' => $tenant->nameIdFormatUrn(),
                'x509cert' => $this->pem($config['sp']['x509cert'] ?? null),
                'privateKey' => $this->pem($config['sp']['privateKey'] ?? null),
            ],
            'idp' => array_filter([
                'entityId' => $tenant->idp_entity_id,
                'singleSignOnService' => ['url' => $tenant->idp_login_url],
                'singleLogoutService' => $tenant->idp_logout_url ? ['url' => $tenant->idp_logout_url] : null,
                'x509cert' => $tenant->idp_x509_cert,
            ]),
            'security' => $config['security'] ?? [],
            'contactPerson' => $config['contactPerson'] ?? [],
            'organization' => $config['organization'] ?? [],
        ];

        $settings = array_replace_recursive($settings, $tenant->settings ?? []);

        foreach ($this->settingsCallbacks as $callback) {
            $settings = $callback($settings, $tenant);
        }

        return $settings;
    }

    /**
     * Return $url when it is safe to redirect to (relative, current host or an allowed host).
     */
    public function safeRedirect(?string $url, ?Request $request = null): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\')) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($host) || ! in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return null;
        }

        $allowed = array_merge(
            (array) config('saml2.allowed_redirect_hosts', []),
            array_filter([
                ($request ?? request())->getHost(),
                parse_url((string) config('saml2.base_url'), PHP_URL_HOST),
                parse_url((string) config('app.url'), PHP_URL_HOST),
            ]),
        );

        return in_array(strtolower($host), array_map('strtolower', $allowed), true) ? $url : null;
    }

    /**
     * Absolute URL of a package route, honouring saml2.base_url.
     */
    public function route(string $name, string $tenant): string
    {
        $base = config('saml2.base_url');

        return $base
            ? rtrim((string) $base, '/').URL::route($name, ['tenant' => $tenant], false)
            : URL::route($name, ['tenant' => $tenant]);
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function linkTo(string $route, Saml2Tenant|int|string|null $tenant, array $query): string
    {
        $tenant = $tenant instanceof Saml2Tenant ? $tenant : ($tenant === null ? $this->currentTenant() : $this->findTenant($tenant));

        if (! $tenant) {
            throw new InvalidArgumentException('SAML2 tenant not found.');
        }

        $url = $this->route($route, $tenant->routeIdentifier());

        return $query ? $url.'?'.http_build_query($query) : $url;
    }

    /**
     * Accept a PEM string or a path (absolute or relative to the project root).
     */
    protected function pem(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || str_contains($value, '-----BEGIN')) {
            return $value;
        }

        foreach ([$value, base_path($value)] as $path) {
            if (strlen($path) < PHP_MAXPATHLEN && is_file($path)) {
                return trim((string) file_get_contents($path));
            }
        }

        return $value;
    }
}
