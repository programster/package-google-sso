<?php


namespace Programster\GoogleSso;


class GoogleUserData
{
    private string $iss;
    private string $azp;
    private string $aud;
    private string $sub;
    private string $email;
    private bool $emailVerified;
    private string $atHash;
    private string $firstName;
    private string $lastName;
    private string $fullName;
    private string $pictureUrl;
    private int $expiry;
    private int $issuedAt;


    private function __construct()
    {
    }


    /**
     * Create a GoogleUserData object from the JWT provided from Google
     * @param array $affiliateArray
     * @return GoogleUserData
     */
    public static function createFromJwt($jwt) : GoogleUserData
    {
        $user = new GoogleUserData();
        $user->iss = $jwt->iss; // ignore
        $user->azp = $jwt->azp; // ignore
        $user->aud = $jwt->aud; // ignore
        $user->sub = $jwt->sub; // ignore
        $user->email = $jwt->email;
        $user->emailVerified = $jwt->email_verified;
        $user->atHash = $jwt->at_hash;
        $user->fullName = $jwt->name;
        $user->pictureUrl = $jwt->picture;
        $user->firstName = $jwt->given_name;
        $user->lastName = $jwt->family_name;
        $user->issuedAt = $jwt->iat;
        $user->expiry = $jwt->exp;
        return $user;
    }


    public function getIss() : string { return $this->iss; }
    public function getAzp() : string { return $this->azp; }
    public function getAud() : string { return $this->aud; }
    public function getSub() : string { return $this->sub; }
    public function getEmail() : string { return $this->email; }
    public function getEmailVerified() : bool { return $this->emailVerified; }
    public function getAtHash() : string { return $this->atHash; }
    public function getFirstName() : string { return $this->firstName; }
    public function getLastName() : string { return $this->lastName; }
    public function getFullName() : string { return $this->fullName; }
    public function getPictureUrl() : string { return $this->pictureUrl; }
    public function getExpiry() : int { return $this->expiry; }
    public function getIssuedAt() : int { return $this->issuedAt; }
}