<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;
use Throwable;

class UpdateTenantCommand extends Command
{
    use InteractsWithTenants;

    protected $description = 'Update an Identity Provider (tenant); only the given options change';

    public function __construct()
    {
        $this->signature = 'saml2:update-tenant {tenant : ID, UUID or key}'.self::tenantOptions();

        parent::__construct();
    }

    public function handle(): int
    {
        if (! $tenant = $this->findTenantOrFail($this->tenantArgument())) {
            return self::FAILURE;
        }

        try {
            $this->applyOptions($tenant);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $tenant->save();

        $this->components->info("Tenant #{$tenant->id} ({$tenant->uuid}) updated.");
        $this->renderTenant($tenant);

        return self::SUCCESS;
    }
}
