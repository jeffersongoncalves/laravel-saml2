<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;

class TenantCredentialsCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'saml2:tenant-credentials {tenant : ID, UUID or key}';

    protected $description = 'Show a tenant and the Service Provider URLs to configure at the IdP';

    public function handle(): int
    {
        if (! $tenant = $this->findTenantOrFail($this->tenantArgument())) {
            return self::FAILURE;
        }

        $this->renderTenant($tenant);
        $this->renderCredentials($tenant);

        return self::SUCCESS;
    }
}
