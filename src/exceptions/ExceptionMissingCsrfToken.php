<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

/**
 * Thrown when Google sends the user back, but there is no CSRF token in their session to compare the "state" with.
 * This happens if the session wasn't started, it expired, or the token was already used by an earlier callback (e.g.
 * the user pressed back and reloaded the callback page). It extends ExceptionCsrfTokenMismatch, so code that catches
 * that catches this too.
 */
final class ExceptionMissingCsrfToken extends ExceptionCsrfTokenMismatch
{
    /**
     * @param string $googleResponseStateToken - the "state" that came back in the callback's query parameters.
     */
    public function __construct(string $googleResponseStateToken)
    {
        $message = (
            "There is no CSRF token in the session to check the response's state against. The session may not have"
            . " been started, may have expired, or the login may have already been handled."
        );

        parent::__construct($googleResponseStateToken, "", $message);
    }
}
