<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

use Programster\GoogleSso\Exceptions\ExceptionInvalidIdToken;


class GoogleUserData
{
    private readonly string $m_iss;
    private readonly string $m_azp;
    private readonly string $m_aud;
    private readonly string $m_sub;
    private readonly string $m_email;
    private readonly bool $m_emailVerified;
    private readonly string $m_hostedDomain;
    private readonly string $m_atHash;
    private readonly string $m_firstName;
    private readonly string $m_lastName;
    private readonly string $m_fullName;
    private readonly string $m_pictureUrl;
    private readonly int $m_expiry;
    private readonly int $m_issuedAt;
    # The raw values of the "amr" claim, e.g. ["pwd", "mfa"], or [] if Google didn't send it.
    private readonly array $m_authenticationMethods;
    private readonly int $m_authTime;
    # Every claim in the ID token, as Google sent them (so unchecked), keyed by name. See getClaims().
    private readonly array $m_claims;


    private function __construct(
        string $iss,
        string $azp,
        string $aud,
        string $sub,
        string $email,
        bool $emailVerified,
        string $hostedDomain,
        string $atHash,
        string $firstName,
        string $lastName,
        string $fullName,
        string $pictureUrl,
        int $expiry,
        int $issuedAt,
        array $authenticationMethods,
        int $authTime,
        array $claims,
    )
    {
        $this->m_iss = $iss;
        $this->m_azp = $azp;
        $this->m_aud = $aud;
        $this->m_sub = $sub;
        $this->m_email = $email;
        $this->m_emailVerified = $emailVerified;
        $this->m_hostedDomain = $hostedDomain;
        $this->m_atHash = $atHash;
        $this->m_firstName = $firstName;
        $this->m_lastName = $lastName;
        $this->m_fullName = $fullName;
        $this->m_pictureUrl = $pictureUrl;
        $this->m_expiry = $expiry;
        $this->m_issuedAt = $issuedAt;
        $this->m_authenticationMethods = $authenticationMethods;
        $this->m_authTime = $authTime;
        $this->m_claims = $claims;
    }


    /**
     * Create a GoogleUserData object from the decoded claims of the ID token that Google provided. Claims that Google
     * leaves out for some accounts (such as the family name or picture) default to an empty string, or for amr and
     * auth_time, which Google only sends when they are asked for, an empty array and 0.
     *
     * @param \stdClass $jwt - the decoded claims, as returned by \Firebase\JWT\JWT::decode().
     *
     * @return GoogleUserData
     *
     * @throws ExceptionInvalidIdToken - if a claim that Google always includes is missing, or of the wrong type.
     */
    public static function createFromJwt(\stdClass $jwt) : GoogleUserData
    {
        $emailVerified = $jwt->email_verified ?? false;

        # Any claims within claims (JSON objects) become arrays too, so the result is plain data all the way down.
        $claims = json_decode(json_encode($jwt, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return new GoogleUserData(
            iss: self::getRequiredString($jwt, "iss"),
            azp: self::getOptionalString($jwt, "azp"),
            aud: self::getRequiredString($jwt, "aud"),
            sub: self::getRequiredString($jwt, "sub"),
            email: self::getRequiredString($jwt, "email"),
            emailVerified: ($emailVerified === true || $emailVerified === "true"),
            hostedDomain: self::getOptionalString($jwt, "hd"),
            atHash: self::getOptionalString($jwt, "at_hash"),
            firstName: self::getOptionalString($jwt, "given_name"),
            lastName: self::getOptionalString($jwt, "family_name"),
            fullName: self::getOptionalString($jwt, "name"),
            pictureUrl: self::getOptionalString($jwt, "picture"),
            expiry: self::getRequiredInt($jwt, "exp"),
            issuedAt: self::getRequiredInt($jwt, "iat"),
            authenticationMethods: self::getStrings($jwt, "amr"),
            authTime: (isset($jwt->auth_time) && is_numeric($jwt->auth_time)) ? (int)$jwt->auth_time : 0,
            claims: $claims,
        );
    }


    /**
     * Find out if the user signed in to Google with the given method, according to the "amr" claim. It describes their
     * last sign-in to their Google account (see getAuthTime()), which may be long before this login to your site.
     *
     * @param AuthenticationMethodEnum $method - the method to look for.
     *
     * @return bool - true if Google says it was used, or false if not, or if Google didn't send the amr claim.
     */
    public function hasAuthenticationMethod(AuthenticationMethodEnum $method) : bool
    {
        return in_array($method->value, $this->m_authenticationMethods, true);
    }


    /**
     * Find out if the user signed in to Google with more than a password: a completed multi-factor challenge, or a
     * hardware or software key, such as a passkey. See AuthenticationMethodEnum::isMultiFactor().
     *
     * @return bool - true if so, or false if not, or if Google didn't send the amr claim, so a missing claim is never
     * mistaken for multi-factor authentication.
     */
    public function hasMultiFactorAuthentication() : bool
    {
        $hasMultiFactor = false;

        foreach (AuthenticationMethodEnum::cases() as $method)
        {
            if ($method->isMultiFactor() && $this->hasAuthenticationMethod($method))
            {
                $hasMultiFactor = true;
            }
        }

        return $hasMultiFactor;
    }


    /**
     * @throws ExceptionInvalidIdToken
     */
    private static function getRequiredString(\stdClass $jwt, string $claim) : string
    {
        if (!isset($jwt->$claim) || !is_string($jwt->$claim))
        {
            throw new ExceptionInvalidIdToken("The ID token's \"{$claim}\" claim is missing, or isn't a string.");
        }

        return $jwt->$claim;
    }


    private static function getOptionalString(\stdClass $jwt, string $claim) : string
    {
        return (isset($jwt->$claim) && is_string($jwt->$claim)) ? $jwt->$claim : "";
    }


    /**
     * Get an optional claim that is a list of strings, leaving out anything in it that isn't a string.
     * @return string[] - the strings, or an empty array if the claim is missing, or isn't a list.
     */
    private static function getStrings(\stdClass $jwt, string $claim) : array
    {
        $strings = [];

        if (isset($jwt->$claim) && is_array($jwt->$claim))
        {
            foreach ($jwt->$claim as $value)
            {
                if (is_string($value))
                {
                    $strings[] = $value;
                }
            }
        }

        return $strings;
    }


    /**
     * @throws ExceptionInvalidIdToken
     */
    private static function getRequiredInt(\stdClass $jwt, string $claim) : int
    {
        if (!isset($jwt->$claim) || !is_numeric($jwt->$claim))
        {
            throw new ExceptionInvalidIdToken("The ID token's \"{$claim}\" claim is missing, or isn't a number.");
        }

        return (int)$jwt->$claim;
    }


    # Accessors
    public function getIss() : string { return $this->m_iss; }
    public function getAzp() : string { return $this->m_azp; }
    public function getAud() : string { return $this->m_aud; }
    public function getSub() : string { return $this->m_sub; }
    public function getEmail() : string { return $this->m_email; }
    public function getEmailVerified() : bool { return $this->m_emailVerified; }
    public function getHostedDomain() : string { return $this->m_hostedDomain; }
    public function getAtHash() : string { return $this->m_atHash; }
    public function getFirstName() : string { return $this->m_firstName; }
    public function getLastName() : string { return $this->m_lastName; }
    public function getFullName() : string { return $this->m_fullName; }
    public function getPictureUrl() : string { return $this->m_pictureUrl; }
    public function getExpiry() : int { return $this->m_expiry; }
    public function getIssuedAt() : int { return $this->m_issuedAt; }
    public function getAuthenticationMethods() : array { return $this->m_authenticationMethods; }
    public function getAuthTime() : int { return $this->m_authTime; }
    public function getClaims() : array { return $this->m_claims; }
}
