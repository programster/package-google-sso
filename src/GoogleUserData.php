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
    }


    /**
     * Create a GoogleUserData object from the decoded claims of the ID token that Google provided. Claims that Google
     * leaves out for some accounts (such as the family name or picture) default to an empty string.
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
        );
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
}
