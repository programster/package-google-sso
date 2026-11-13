<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

/**
 * The base class of every exception this package throws itself, so they can all be caught at once. It is never thrown
 * itself. Exceptions from the libraries the package uses (e.g. firebase/php-jwt's ExpiredException, or the HTTP
 * client's ClientExceptionInterface) are passed on as they are, so don't extend this.
 */
abstract class ExceptionGoogleSso extends \Exception
{
}
