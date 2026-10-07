<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use Throwable;

class SyncMetadataCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'saml2:sync-metadata {tenant? : ID, UUID or key; all tenants with a metadata URL when omitted}';

    protected $description = 'Refresh IdP URLs and certificates from the IdP metadata URL (schedule it to follow certificate rollovers)';

    public function handle(): int
    {
        if ($identifier = $this->tenantArgument()) {
            if (! $tenant = $this->findTenantOrFail($identifier, false)) {
                return self::FAILURE;
            }
            $tenants = collect([$tenant]);
        } else {
            $tenants = $this->saml2()->tenants()->whereNotNull('idp_metadata_url')->get();
        }

        $failed = false;

        $tenants->each(function (Saml2Tenant $tenant) use (&$failed): void {
            try {
                $tenant->refreshFromMetadataUrl();
                $changed = $tenant->isDirty();
                $tenant->save();
                $this->components->info("Tenant #{$tenant->id}: ".($changed ? 'updated' : 'unchanged').'.');
            } catch (Throwable $e) {
                $failed = true;
                $this->components->error("Tenant #{$tenant->id}: {$e->getMessage()}");
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
