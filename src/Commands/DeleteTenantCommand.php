<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;

class DeleteTenantCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'saml2:delete-tenant
        {tenant : ID, UUID or key}
        {--force : Delete permanently instead of soft deleting}';

    protected $description = 'Delete an Identity Provider (tenant)';

    public function handle(): int
    {
        if (! $tenant = $this->findTenantOrFail($this->tenantArgument())) {
            return self::FAILURE;
        }

        if ($this->option('force')) {
            $tenant->forceDelete();
            $this->components->info("Tenant #{$tenant->id} permanently deleted.");
        } else {
            $tenant->delete();
            $this->components->info("Tenant #{$tenant->id} deleted. Restore it with saml2:restore-tenant {$tenant->id}.");
        }

        return self::SUCCESS;
    }
}
