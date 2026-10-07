<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Exceptions;

use RuntimeException;
use Throwable;

class Saml2Exception extends RuntimeException
{
    /** @var list<string> Toolkit error codes, e.g. ["invalid_response"]. */
    public readonly array $errors;

    /**
     * @param  array<array-key, string>  $errors
     */
    public function __construct(string $reason, array $errors = [], ?Throwable $previous = null)
    {
        $this->errors = array_values($errors);

        parent::__construct($reason, 0, $previous);
    }
}
