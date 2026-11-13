<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

/**
 * Whether to ask Google how the user signed in to their Google account (the "amr" and "auth_time" claims), and whether
 * to refuse users who didn't use multi-factor authentication. Google only sends these claims to apps that are
 * published, verified, and have "Authentication strength claims" and "Session age claims" turned on.
 * https://developers.google.com/identity/siwg/security-bundle
 */
enum MfaPolicyEnum
{
    # Don't ask Google how the user signed in. GoogleUserData::getAuthenticationMethods() is always empty. The default.
    case IGNORE;

    # Ask Google how the user signed in, so GoogleUserData::getAuthenticationMethods() and getAuthTime() are filled in
    # (when Google sends them), but don't refuse anyone.
    case REPORT;

    # Ask Google how the user signed in, and throw an ExceptionMfaRequired unless GoogleUserData's
    # hasMultiFactorAuthentication() is true. A user Google doesn't say anything about is refused too, as the login URL
    # can be edited to stop it asking, so only use this once your app gets the claims.
    case REQUIRE;
}
