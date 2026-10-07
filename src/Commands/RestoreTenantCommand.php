<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;

class RestoreTenantCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'saml2:restore-tenant {tenant : ID, UUID or key}';

    protected $description = 'Restore a deleted Identity Provider (tenant)';

    public function handle(): int
    {
        if (! $tenant = $this->findTenantOrFail($this->tenantArgument())) {
            return self::FAILURE;
        }

        $tenant->restore();
        $this->components->info("Tenant #{$tenant->id} restored.");

        return self::SUCCESS;
    }
}
