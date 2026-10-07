<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class Saml2ServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('saml2')
            ->hasConfigFile('saml2')
            ->hasMigration('create_saml2_tenants_table')
            ->hasCommands(
                Commands\CreateTenantCommand::class,
                Commands\UpdateTenantCommand::class,
                Commands\DeleteTenantCommand::class,
                Commands\RestoreTenantCommand::class,
                Commands\ListTenantsCommand::class,
                Commands\TenantCredentialsCommand::class,
                Commands\SyncMetadataCommand::class,
                Commands\GenerateCertificateCommand::class,
            );
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Saml2::class);
    }

    public function packageBooted(): void
    {
        if (config('saml2.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('saml2.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/saml2.php');

            // The IdP posts cross-site to ACS/SLS without a CSRF token.
            $prefix = trim((string) config('saml2.routes.prefix', 'saml2'), '/');
            ValidateCsrfToken::except([ltrim("{$prefix}/*/acs", '/'), ltrim("{$prefix}/*/sls", '/')]);
        }
    }
}
