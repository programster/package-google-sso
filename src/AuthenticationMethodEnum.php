<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

/**
 * The ways a user can sign in to their Google account, as Google lists them in the ID token's "amr" claim.
 * https://developers.google.com/identity/siwg/security-bundle
 */
enum AuthenticationMethodEnum : string
{
    # A password was used.
    case PASSWORD = "pwd";

    # A multi-factor authentication challenge was completed.
    case MULTI_FACTOR = "mfa";

    # A hardware security key was used.
    case HARDWARE_KEY = "hwk";

    # A software key, such as a passkey, was used.
    case SOFTWARE_KEY = "swk";

    # A text message was used for verification.
    case SMS = "sms";

    # A phone call was used for verification.
    case PHONE_CALL = "tel";


    /**
     * Find out if this method counts as more than a password: a completed multi-factor challenge, or a hardware or
     * software key (such as a passkey), which prove the user has a device, rather than only knowing something.
     *
     * @return bool
     */
    public function isMultiFactor() : bool
    {
        return match ($this) {
            self::MULTI_FACTOR, self::HARDWARE_KEY, self::SOFTWARE_KEY => true,
            self::PASSWORD, self::SMS, self::PHONE_CALL => false,
        };
    }
}
