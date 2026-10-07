<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OneLogin\Saml2\IdPMetadataParser;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $key
 * @property string $idp_entity_id
 * @property string $idp_login_url
 * @property string|null $idp_logout_url
 * @property string|null $idp_x509_cert
 * @property string|null $idp_metadata_url
 * @property string|null $relay_state_url
 * @property string $name_id_format
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Saml2Tenant extends Model
{
    use SoftDeletes;

    public const NAME_ID_FORMATS = [
        'persistent' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'transient' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
        'emailAddress' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        'unspecified' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        'X509SubjectName' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:X509SubjectName',
        'WindowsDomainQualifiedName' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:WindowsDomainQualifiedName',
        'kerberos' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:kerberos',
        'entity' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:entity',
    ];

    protected $table = 'saml2_tenants';

    protected $fillable = [
        'uuid',
        'key',
        'idp_entity_id',
        'idp_login_url',
        'idp_logout_url',
        'idp_x509_cert',
        'idp_metadata_url',
        'relay_state_url',
        'name_id_format',
        'settings',
        'metadata',
    ];

    protected $attributes = [
        'name_id_format' => 'persistent',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Saml2Tenant $tenant): void {
            $tenant->uuid = $tenant->uuid ?: (string) Str::uuid();
        });
    }

    /**
     * Identifier used in the package URLs: the friendly key when set, the UUID otherwise.
     */
    public function routeIdentifier(): string
    {
        $byKey = in_array('key', (array) config('saml2.resolve_tenant_by', ['uuid', 'key']), true);

        return $byKey && $this->key ? $this->key : $this->uuid;
    }

    /**
     * Full URN of the NameID format. Accepts both short names ("emailAddress") and URNs.
     */
    public function nameIdFormatUrn(): string
    {
        $format = $this->name_id_format ?: 'persistent';

        return self::NAME_ID_FORMATS[$format] ?? $format;
    }

    /**
     * Fill the IdP columns from an IdP metadata XML document.
     *
     * Multiple signing certificates (key rollover) are kept in settings.idp.x509certMulti.
     */
    public function fillFromIdpMetadata(string $xml): static
    {
        $idp = IdPMetadataParser::parseXML($xml, $this->idp_entity_id ?: null)['idp'] ?? null;

        if (! is_array($idp) || empty($idp['entityId']) || empty($idp['singleSignOnService']['url'])) {
            throw new InvalidArgumentException('The IdP metadata does not describe an IdP with a SingleSignOnService.');
        }

        $signing = $idp['x509certMulti']['signing'] ?? [];
        $settings = $this->settings ?? [];
        Arr::forget($settings, 'idp.x509certMulti');

        if (count($signing) > 1) {
            Arr::set($settings, 'idp.x509certMulti', $idp['x509certMulti']);
        }

        $this->fill([
            'idp_entity_id' => $idp['entityId'],
            'idp_login_url' => $idp['singleSignOnService']['url'],
            'idp_logout_url' => $idp['singleLogoutService']['url'] ?? null,
            'idp_x509_cert' => $idp['x509cert'] ?? ($signing[0] ?? null),
            'settings' => Arr::where($settings, fn ($value) => $value !== []) ?: null,
        ]);

        return $this;
    }

    /**
     * Download the metadata from idp_metadata_url and update the IdP columns (without saving).
     */
    public function refreshFromMetadataUrl(): static
    {
        if (! $this->idp_metadata_url) {
            throw new InvalidArgumentException("Tenant [{$this->routeIdentifier()}] has no idp_metadata_url.");
        }

        return $this->fillFromIdpMetadata(
            Http::timeout(15)->get($this->idp_metadata_url)->throw()->body()
        );
    }
}
