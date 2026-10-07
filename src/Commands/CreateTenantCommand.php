<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;
use Throwable;

class CreateTenantCommand extends Command
{
    use InteractsWithTenants;

    protected $description = 'Register an Identity Provider (tenant)';

    public function __construct()
    {
        $this->signature = 'saml2:create-tenant'.self::tenantOptions();

        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = new ($this->saml2()->tenantModel());

        try {
            $this->applyOptions($tenant);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $missing = match (true) {
            ! $tenant->idp_entity_id => 'entity-id',
            ! $tenant->idp_login_url => 'login-url',
            ! $tenant->idp_x509_cert && ! isset($tenant->settings['idp']['x509certMulti']) => 'x509cert',
            default => null,
        };

        if ($missing) {
            $this->components->error("Pass --{$missing} or --metadata-url.");

            return self::FAILURE;
        }

        $tenant->save();

        $this->components->info("Tenant #{$tenant->id} ({$tenant->uuid}) created.");
        $this->renderTenant($tenant);
        $this->renderCredentials($tenant);

        return self::SUCCESS;
    }
}
