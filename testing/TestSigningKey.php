<?php

/*
 * An RSA key that plays the part of Google's, for signing ID tokens in the tests, along with the JWK set that a fake
 * certificates endpoint serves for it.
 */

declare(strict_types = 1);

namespace Programster\GoogleSso\Testing;

use Firebase\JWT\JWT;


final readonly class TestSigningKey
{
    # The key ID, which Google puts in each ID token's header, so the right certificate can be found.
    public const KEY_ID = "test-key-1";

    private string $m_keyId;
    private string $m_privateKeyPem;
    private string $m_jwks;


    private function __construct(string $keyId, string $privateKeyPem, string $jwks)
    {
        $this->m_keyId = $keyId;
        $this->m_privateKeyPem = $privateKeyPem;
        $this->m_jwks = $jwks;
    }


    /**
     * Get the key that the fake Google signs with. It is generated once and then reused, as generating one is slow.
     * @return TestSigningKey
     */
    public static function get() : TestSigningKey
    {
        static $key = null;

        if ($key === null)
        {
            $key = self::create();
        }

        return $key;
    }


    /**
     * Generate a new key, e.g. to sign a token with a key that Google's certificates don't include.
     * @param string $keyId - the key's ID, e.g. a different one, for a key Google has rotated to.
     * @return TestSigningKey
     */
    public static function create(string $keyId = self::KEY_ID) : TestSigningKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);
        $rsaDetails = openssl_pkey_get_details($key)['rsa'];

        $jwk = [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $keyId,
            'n' => self::base64UrlEncode($rsaDetails['n']),
            'e' => self::base64UrlEncode($rsaDetails['e']),
        ];

        return new TestSigningKey($keyId, $privateKeyPem, json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR));
    }


    /**
     * Sign an ID token, as Google's token endpoint would return it.
     * @param array<string, mixed> $claims - the claims to put in the token.
     * @return string - the signed JWT.
     */
    public function signIdToken(array $claims) : string
    {
        return JWT::encode($claims, $this->m_privateKeyPem, 'RS256', $this->m_keyId);
    }


    private static function base64UrlEncode(string $data) : string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }


    # Accessors
    public function getKeyId() : string { return $this->m_keyId; }
    public function getJwks() : string { return $this->m_jwks; }
}
