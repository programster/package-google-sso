<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

/**
 * Thrown when the ID token Google returned has a valid signature, but its claims mean it can't be trusted for this
 * login: it was issued to a different client (aud), by someone other than Google (iss), or is missing a claim that
 * Google always includes.
 */
class ExceptionInvalidIdToken extends \Exception
{
}
