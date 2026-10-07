<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Events;

use Illuminate\Http\Request;
use JeffersonGoncalves\LaravelSaml2\Models\Saml2Tenant;

/**
 * Single Logout happened (IdP-initiated request or confirmation of ours).
 *
 * Fired before the local guard logout, so Auth::user() is still available.
 */
class SignedOut
{
    public function __construct(
        public readonly Saml2Tenant $tenant,
        public readonly ?string $nameId,
        public readonly ?string $sessionIndex,
        public readonly Request $request,
    ) {}
}
