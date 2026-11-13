<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

/**
 * Thrown when the user logged in to Google, but Google hasn't verified that they own their account's email address,
 * so it can't be trusted to be theirs. Ask them to verify their email address with Google, and then try again. To let
 * these users in, e.g. if you only ever find users by their Google ID, pass UnverifiedEmailPolicyEnum::ALLOW to
 * handleGoogleSsoCallback().
 */
final class ExceptionUnverifiedEmail extends ExceptionGoogleSso
{
    private readonly string $m_email;


    /**
     * @param string $email - the unverified address. It isn't put in the message, so it doesn't end up in logs.
     */
    public function __construct(string $email)
    {
        $this->m_email = $email;
        parent::__construct("Google hasn't verified that the user owns their email address, so they were refused.");
    }


    # Accessors
    public function getEmail() : string { return $this->m_email; }
}
