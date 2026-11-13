<?php

/*
 * Shared set-up for the smoke tests: valid ID token claims, and clients that talk to a fake Google.
 */

declare(strict_types = 1);

namespace Programster\GoogleSso\Testing;

use GuzzleHttp\Psr7\HttpFactory;
use Programster\GoogleSso\CacheConfig;
use Programster\GoogleSso\CsrfTokenStoreInterface;
use Programster\GoogleSso\GoogleSsoClient;
use Programster\GoogleSso\MfaPolicyEnum;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;


final class TestFixtures
{
    public const CLIENT_ID = "test-client-id.apps.googleusercontent.com";
    public const CLIENT_SECRET = "test-client-secret";
    public const CALLBACK_URL = "https://example.com/sso/login-handler";
    public const SESSION_KEY = "googleSsoCsrfToken";


    /**
     * Get the claims of a valid ID token for this client, with only the claims Google always includes.
     * @param array<string, mixed> $overrides - claims to change or add, e.g. ['aud' => 'another-client'].
     * @return array<string, mixed>
     */
    public static function validClaims(array $overrides = []) : array
    {
        $claims = [
            'iss' => 'https://accounts.google.com',
            'azp' => self::CLIENT_ID,
            'aud' => self::CLIENT_ID,
            'sub' => '110169484474386276334',
            'email' => 'someone@example.com',
            'email_verified' => true,
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        return array_merge($claims, $overrides);
    }


    /**
     * Create a client that talks to the given fake Google.
     * @param ClientInterface $httpClient - the fake Google, e.g. from FakeGoogleHttpClient::issuingIdToken().
     * @param int $jwtLeewaySeconds - the clock skew to allow.
     * @return GoogleSsoClient
     */
    public static function createClient(ClientInterface $httpClient, int $jwtLeewaySeconds = 60) : GoogleSsoClient
    {
        return new GoogleSsoClient(
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            googleCallbackUrl: self::CALLBACK_URL,
            requestFactory: new HttpFactory(),
            httpClient: $httpClient,
            jwtLeewaySeconds: $jwtLeewaySeconds,
        );
    }


    /**
     * Create a client that talks to the given fake Google, with the given MFA policy.
     * @param ClientInterface $httpClient - the fake Google, e.g. from FakeGoogleHttpClient::issuingIdToken().
     * @param MfaPolicyEnum $mfaPolicy - whether to ask for, and require, multi-factor authentication.
     * @return GoogleSsoClient
     */
    public static function createClientWithMfaPolicy(
        ClientInterface $httpClient,
        MfaPolicyEnum $mfaPolicy
    ) : GoogleSsoClient
    {
        return new GoogleSsoClient(
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            googleCallbackUrl: self::CALLBACK_URL,
            requestFactory: new HttpFactory(),
            httpClient: $httpClient,
            mfaPolicy: $mfaPolicy,
        );
    }


    /**
     * Create a client that talks to the given fake Google, and caches its certificates in the given pool.
     * @param ClientInterface $httpClient - the fake Google.
     * @param CacheItemPoolInterface $cachePool - where to cache the certificates.
     * @return GoogleSsoClient
     */
    public static function createCachingClient(
        ClientInterface $httpClient,
        CacheItemPoolInterface $cachePool
    ) : GoogleSsoClient
    {
        return new GoogleSsoClient(
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            googleCallbackUrl: self::CALLBACK_URL,
            requestFactory: new HttpFactory(),
            httpClient: $httpClient,
            cacheConfig: new CacheConfig($cachePool),
        );
    }


    /**
     * Create a client that talks to a fake Google, with these CSRF token options.
     * @param string $sessionCsrfTokenKey - the deprecated constructor option.
     * @param CsrfTokenStoreInterface $csrfTokenStore - the store to use.
     * @return GoogleSsoClient
     */
    public static function createClientWithCsrfOptions(
        string $sessionCsrfTokenKey,
        CsrfTokenStoreInterface $csrfTokenStore
    ) : GoogleSsoClient
    {
        return new GoogleSsoClient(
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            googleCallbackUrl: self::CALLBACK_URL,
            requestFactory: new HttpFactory(),
            httpClient: FakeGoogleHttpClient::issuingIdToken(self::validClaims()),
            sessionCsrfTokenKey: $sessionCsrfTokenKey,
            csrfTokenStore: $csrfTokenStore,
        );
    }


    /**
     * Start a login, as when the user clicks "Login with Google", and get the state the client put in the session.
     * @param GoogleSsoClient $client - the client to start the login with.
     * @return string - the state, which Google sends back to the callback.
     */
    public static function startLogin(GoogleSsoClient $client) : string
    {
        $client->createGoogleSsoLoginUrl();
        return $_SESSION[self::SESSION_KEY];
    }


    /**
     * Start a login with a client that keeps the CSRF token in $_SESSION under another key, and get the state.
     * @param GoogleSsoClient $client - the client to start the login with.
     * @param string $sessionKey - the $_SESSION key it keeps the token under.
     * @return string - the state, which Google sends back to the callback.
     */
    public static function startLoginWithSessionKey(GoogleSsoClient $client, string $sessionKey) : string
    {
        $client->createGoogleSsoLoginUrl();
        return $_SESSION[$sessionKey];
    }


    /**
     * Get the query parameters that Google sends to the callback after a successful login.
     * @param string $state - the state from startLogin().
     * @return array<string, string>
     */
    public static function callbackParameters(string $state) : array
    {
        return ['code' => '4/0AbCdEfGh-test-code', 'state' => $state, 'scope' => 'email profile openid'];
    }
}
