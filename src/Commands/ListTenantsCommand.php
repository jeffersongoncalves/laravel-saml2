<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;

class ListTenantsCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'saml2:list-tenants {--trashed : Include deleted tenants}';

    protected $description = 'List the Identity Providers (tenants)';

    public function handle(): int
    {
        $tenants = $this->saml2()->tenants((bool) $this->option('trashed'))->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->components->info('No tenants yet. Create one with saml2:create-tenant.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'UUID', 'Key', 'IdP entity ID', 'NameID', 'Deleted'],
            $tenants->map(fn (Saml2Tenant $tenant) => [
                $tenant->id,
                $tenant->uuid,
                $tenant->key ?? '-',
                $tenant->idp_entity_id,
                $tenant->name_id_format,
                $tenant->deleted_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
