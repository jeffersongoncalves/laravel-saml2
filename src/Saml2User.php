<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2;

use Illuminate\Support\Str;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;
use OneLogin\Saml2\Auth as OneLoginAuth;

class Saml2User
{
    public function __construct(
        protected OneLoginAuth $auth,
        protected Saml2Tenant $tenant,
    ) {}

    public function tenant(): Saml2Tenant
    {
        return $this->tenant;
    }

    public function nameId(): string
    {
        return (string) $this->auth->getNameId();
    }

    public function nameIdFormat(): ?string
    {
        return $this->auth->getNameIdFormat() ?: null;
    }

    public function sessionIndex(): ?string
    {
        return $this->auth->getSessionIndex() ?: null;
    }

    /**
     * ID of the assertion consumed by this request; useful to audit logins.
     */
    public function assertionId(): ?string
    {
        return $this->auth->getLastAssertionId() ?: null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function attributes(): array
    {
        return $this->auth->getAttributes();
    }

    /**
     * @return array<string, list<string>>
     */
    public function attributesWithFriendlyName(): array
    {
        return $this->auth->getAttributesWithFriendlyName();
    }

    /**
     * All values of an attribute, looked up by Name first and FriendlyName second.
     *
     * @return list<string>|null
     */
    public function attribute(string $name): ?array
    {
        return $this->attributes()[$name] ?? $this->attributesWithFriendlyName()[$name] ?? null;
    }

    /**
     * First value of an attribute.
     */
    public function first(string $name): ?string
    {
        return $this->attribute($name)[0] ?? null;
    }

    /**
     * Resolve the "attribute_map" config (or the given map) against this assertion.
     *
     * Single-valued attributes come back as a string, multi-valued ones as a list.
     *
     * @param  array<string, string|list<string>>|null  $map
     * @return array<string, string|list<string>|null>
     */
    public function mapped(?array $map = null): array
    {
        /** @var array<string, string|list<string>> $map */
        $map ??= (array) config('saml2.attribute_map', []);
        $result = [];

        foreach ($map as $key => $candidates) {
            $result[$key] = null;

            foreach ((array) $candidates as $candidate) {
                if ($values = $this->attribute($candidate)) {
                    $result[$key] = count($values) === 1 ? $values[0] : $values;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Lower-cased e-mail from the mapped attributes, falling back to an e-mail shaped NameID.
     */
    public function email(): ?string
    {
        $email = $this->mapped()['email'] ?? null;
        $email = is_array($email) ? $email[0] : $email;

        if (! $email && filter_var($this->nameId(), FILTER_VALIDATE_EMAIL)) {
            $email = $this->nameId();
        }

        return $email ? Str::lower(trim($email)) : null;
    }
}
