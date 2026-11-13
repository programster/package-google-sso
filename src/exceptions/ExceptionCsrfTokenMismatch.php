<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

class ExceptionCsrfTokenMismatch extends ExceptionGoogleSso
{
    private readonly string $m_googleResponseStateToken;
    private readonly string $m_cachedStateToken;


    /**
     * Create an exception for when the "state" Google sent back doesn't match the CSRF token we stored in the session.
     * @param string $googleResponseStateToken - the "state" that came back in the callback's query parameters.
     * @param string $cachedStateToken - the token that was stored in the session (an empty string if there was none).
     * @param string $message - optionally, a description of what went wrong.
     */
    public function __construct(
        string $googleResponseStateToken,
        string $cachedStateToken,
        string $message = "The CSRF token in the response did not match the one we created for the login request."
    )
    {
        $this->m_googleResponseStateToken = $googleResponseStateToken;
        $this->m_cachedStateToken = $cachedStateToken;
        parent::__construct($message);
    }


    # Accessors
    public function getGoogleResponseToken() : string { return $this->m_googleResponseStateToken; }
    public function getCachedStateToken() : string { return $this->m_cachedStateToken; }
}
