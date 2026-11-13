<?php

declare(strict_types = 1);

namespace Programster\GoogleSso\Exceptions;

/**
 * Thrown with MfaPolicyEnum::REQUIRE, when Google doesn't say that the user signed in to their Google account with
 * multi-factor authentication (or a hardware or software key, such as a passkey). Ask them to turn on 2-Step
 * Verification for their Google account, or sign in to Google with it, and then try again. If it is thrown for
 * everyone, Google isn't sending the amr claim to your app at all (see getAuthenticationMethods()).
 */
final class ExceptionMfaRequired extends ExceptionGoogleSso
{
    private readonly array $m_authenticationMethods;


    /**
     * @param string[] $authenticationMethods - the "amr" claim's values, e.g. ["pwd"], or [] if Google didn't send it.
     */
    public function __construct(array $authenticationMethods)
    {
        $this->m_authenticationMethods = $authenticationMethods;
        $methods = ($authenticationMethods === []) ? "Google didn't say how" : implode(", ", $authenticationMethods);

        $message = (
            "The user didn't sign in to Google with multi-factor authentication, which is required"
            . " (authentication methods: {$methods})."
        );

        parent::__construct($message);
    }


    # Accessors
    public function getAuthenticationMethods() : array { return $this->m_authenticationMethods; }
}
