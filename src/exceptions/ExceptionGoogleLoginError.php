<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

/**
 * Thrown when Google sends the user back with an "error" query parameter instead of a code. Most often this is
 * "access_denied", because the user cancelled the login, or declined to share their details.
 */
class ExceptionGoogleLoginError extends \Exception
{
    private readonly string $m_error;


    /**
     * @param string $error - the value of the "error" query parameter, e.g. "access_denied".
     */
    public function __construct(string $error)
    {
        $this->m_error = $error;
        parent::__construct("Google did not log the user in, and returned the error: {$error}");
    }


    /**
     * Find out if the user cancelled the login, or declined to share their details, rather than something going
     * wrong.
     *
     * @return bool - true if the error was "access_denied".
     */
    public function isAccessDenied() : bool
    {
        return $this->m_error === "access_denied";
    }


    # Accessors
    public function getError() : string { return $this->m_error; }
}
