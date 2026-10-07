<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Events;

use Illuminate\Http\Request;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;

/**
 * A SAMLResponse (step "login") or logout message (step "logout") was rejected.
 */
class Saml2Failed
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly Saml2Tenant $tenant,
        public readonly string $step,
        public readonly array $errors,
        public readonly string $reason,
        public readonly Request $request,
    ) {}
}
