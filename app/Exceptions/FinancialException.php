<?php

namespace App\Exceptions;

use GraphQL\Error\ClientAware;

/** Only deliberate, non-sensitive business validation messages reach the client. */
final class FinancialException extends \DomainException implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }
}
