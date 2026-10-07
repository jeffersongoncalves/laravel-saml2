<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use JeffersonGoncalves\LaravelSaml2\Saml2;

/**
 * @mixin Command
 */
trait InteractsWithTenants
{
    /**
     * Options shared by create/update.
     */
    protected static function tenantOptions(): string
    {
        return '
            {--key= : Friendly key used in URLs, e.g. "okta" (/saml2/okta/login)}
            {--metadata-url= : IdP metadata URL; fills entity ID, URLs and certificates}
            {--entity-id= : IdP entity ID (issuer)}
            {--login-url= : IdP SingleSignOnService URL}
            {--logout-url= : IdP SingleLogoutService URL}
            {--x509cert= : IdP signing certificate (PEM or base64)}
            {--relay-state-url= : Where to send users after login}
            {--name-id-format= : persistent, transient, emailAddress, unspecified, ... or a full URN}
            {--settings= : JSON merged into the toolkit settings for this tenant}
            {--metadata= : JSON with your own data}';
    }

    protected function saml2(): Saml2
    {
        return $this->laravel->make(Saml2::class);
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function tenantArgument(): string
    {
        $value = $this->argument('tenant');

        return is_string($value) ? $value : '';
    }

    protected function findTenantOrFail(string $identifier, bool $withTrashed = true): ?Saml2Tenant
    {
        $tenant = $this->saml2()->findTenant($identifier, $withTrashed);

        if (! $tenant) {
            $this->components->error("Tenant [{$identifier}] not found.");
        }

        return $tenant;
    }

    /**
     * Apply the given options; metadata URL first so explicit options win.
     *
     * @throws InvalidArgumentException
     */
    protected function applyOptions(Saml2Tenant $tenant): void
    {
        if ($url = $this->stringOption('metadata-url')) {
            $tenant->idp_metadata_url = $url;
            $tenant->refreshFromMetadataUrl();
        }

        $nameIdFormat = $this->stringOption('name-id-format');

        if ($nameIdFormat && ! isset(Saml2Tenant::NAME_ID_FORMATS[$nameIdFormat]) && ! str_starts_with($nameIdFormat, 'urn:')) {
            throw new InvalidArgumentException('Invalid NameID format. Use one of: '.implode(', ', array_keys(Saml2Tenant::NAME_ID_FORMATS)).' or a URN.');
        }

        $tenant->fill(array_filter([
            'key' => $this->stringOption('key'),
            'idp_entity_id' => $this->stringOption('entity-id'),
            'idp_login_url' => $this->stringOption('login-url'),
            'idp_logout_url' => $this->stringOption('logout-url'),
            'idp_x509_cert' => $this->stringOption('x509cert'),
            'relay_state_url' => $this->stringOption('relay-state-url'),
            'name_id_format' => $nameIdFormat,
            'settings' => $this->jsonOption('settings'),
            'metadata' => $this->jsonOption('metadata'),
        ], fn ($value) => $value !== null && $value !== ''));

        if ($tenant->key && $this->saml2()->tenants(true)->where('key', $tenant->key)->whereKeyNot($tenant->getKey())->exists()) {
            throw new InvalidArgumentException("Key [{$tenant->key}] is already used by another tenant.");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function jsonOption(string $name): ?array
    {
        $value = $this->stringOption($name);

        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("--{$name} must be a JSON object.");
        }

        return $decoded;
    }

    protected function renderTenant(Saml2Tenant $tenant): void
    {
        $this->components->twoColumnDetail('<fg=gray>ID</>', (string) $tenant->id);
        $this->components->twoColumnDetail('<fg=gray>UUID</>', $tenant->uuid);
        $this->components->twoColumnDetail('<fg=gray>Key</>', $tenant->key ?? '-');
        $this->components->twoColumnDetail('<fg=gray>IdP entity ID</>', $tenant->idp_entity_id);
        $this->components->twoColumnDetail('<fg=gray>IdP login URL</>', $tenant->idp_login_url);
        $this->components->twoColumnDetail('<fg=gray>IdP logout URL</>', $tenant->idp_logout_url ?? '-');
        $this->components->twoColumnDetail('<fg=gray>IdP certificate</>', Str::limit((string) $tenant->idp_x509_cert, 40));
        $this->components->twoColumnDetail('<fg=gray>IdP metadata URL</>', $tenant->idp_metadata_url ?? '-');
        $this->components->twoColumnDetail('<fg=gray>Relay state URL</>', $tenant->relay_state_url ?? '-');
        $this->components->twoColumnDetail('<fg=gray>NameID format</>', $tenant->name_id_format);
        $this->components->twoColumnDetail('<fg=gray>Deleted</>', $tenant->deleted_at?->toDateTimeString() ?? '-');
    }

    protected function renderCredentials(Saml2Tenant $tenant): void
    {
        $urls = $this->saml2()->serviceProviderUrls($tenant);

        $this->newLine();
        $this->components->info('Give these values to the Identity Provider administrator:');
        $this->components->twoColumnDetail('Identifier (Entity ID)', $urls['entity_id']);
        $this->components->twoColumnDetail('Reply URL (Assertion Consumer Service)', $urls['acs']);
        $this->components->twoColumnDetail('Logout URL (Single Logout Service)', $urls['sls']);
        $this->components->twoColumnDetail('SP metadata', $urls['metadata']);
        $this->components->twoColumnDetail('Sign on URL', $urls['login']);
    }
}
