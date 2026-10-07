<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \JeffersonGoncalves\LaravelSaml2\Saml2Auth auth(\JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant|int|string $tenant)
 * @method static \JeffersonGoncalves\LaravelSaml2\Saml2Auth|null current()
 * @method static \JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant|null currentTenant()
 * @method static \JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant|null findTenant(int|string $identifier, bool $withTrashed = false)
 * @method static \JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant|null resolveTenant(\Illuminate\Http\Request $request)
 * @method static \Illuminate\Database\Eloquent\Builder<\JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant> tenants(bool $withTrashed = false)
 * @method static class-string<\JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant> tenantModel()
 * @method static void resolveTenantUsing(\Closure $resolver)
 * @method static void configureUsing(\Closure $callback)
 * @method static string loginUrl(\JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant|int|string|null $tenant = null, string|null $returnTo = null)
 * @method static string logoutUrl(\JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant|int|string|null $tenant = null, string|null $returnTo = null)
 * @method static array{entity_id: string, acs: string, sls: string, metadata: string, login: string, logout: string} serviceProviderUrls(\JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant $tenant)
 * @method static array<string, mixed> settings(\JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant $tenant)
 * @method static string|null safeRedirect(string|null $url, \Illuminate\Http\Request|null $request = null)
 *
 * @see \JeffersonGoncalves\LaravelSaml2\Saml2
 */
class Saml2 extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JeffersonGoncalves\LaravelSaml2\Saml2::class;
    }
}
