<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

/**
 * What handleGoogleSsoCallback() does with a user whose email address Google hasn't verified (its "email_verified"
 * claim isn't true), meaning Google doesn't know that the address belongs to them.
 */
enum UnverifiedEmailPolicyEnum
{
    # Throw an ExceptionUnverifiedEmail, so getEmail() on every user that gets through can be trusted. This is the
    # default, and is what any site that ever finds users by email address needs.
    case REFUSE;

    # Let them through, for a site that only ever finds users by their Google ID (getSub()), and doesn't trust the email
    # address for anything. Never find a user by getEmail() with this, unless getEmailVerified() is true.
    case ALLOW;
}
