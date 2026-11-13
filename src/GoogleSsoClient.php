<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Programster\GoogleSso\Exceptions\ExceptionCsrfTokenMismatch;
use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;
use Programster\GoogleSso\Exceptions\ExceptionInvalidIdToken;
use Programster\GoogleSso\Exceptions\ExceptionMfaRequired;
use Programster\GoogleSso\Exceptions\ExceptionMissingCallbackParameter;
use Programster\GoogleSso\Exceptions\ExceptionMissingCsrfToken;
use Programster\GoogleSso\Exceptions\ExceptionUnexpectedResponse;
use Programster\GoogleSso\Exceptions\ExceptionUnverifiedEmail;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;

final readonly class GoogleSsoClient
{
    /**
     * The values Google may put in an ID token's "iss" claim.
     * https://developers.google.com/identity/openid-connect/openid-connect#validatinganidtoken
     */
    private const VALID_ISSUERS = ["accounts.google.com", "https://accounts.google.com"];

    private string $m_clientId;
    private string $m_clientSecret;
    private string $m_googleCallbackUrl;
    private RequestFactoryInterface $m_requestFactory;
    private ResponseFactoryInterface $m_responseFactory;
    private ClientInterface $m_httpClient;
    private ?CacheConfig $m_cacheConfig;
    private CsrfTokenStoreInterface $m_csrfTokenStore;
    private MfaPolicyEnum $m_mfaPolicy;
    private int $m_jwtLeewaySeconds;
    private string $m_googleJwtCertsUrl;
    private string $m_googleOauthUrl;


    /**
     * Create a client for interfacing with the Google SSO.
     *
     * @param string $clientId - the client ID Google provided you for using their OAuth2 system.
     *
     * @param string $clientSecret - the secret to go with the client ID that Google provided.
     *
     * @param string $googleCallbackUrl - your site's endpoint to provide google, for where they should send the user
     * when they have logged in. This needs to be already registered with your Google client. E.g. this would be
     * your site's FQDN, with the relevant path such as. https://mydomain.com/sso/login-handler
     *
     * @param RequestFactoryInterface $requestFactory - the factory you wish to use for creating HTTP requests, which
     * we will use for sending requests to Google. We recommend \GuzzleHttp\Psr7\HttpFactory().
     *
     * @param ResponseFactoryInterface $responseFactory - the factory for creating the redirect response that
     * createGoogleSsoLoginResponse() returns. \GuzzleHttp\Psr7\HttpFactory() is both, so the same one can be passed.
     *
     * @param ClientInterface $httpClient - the HTTP client you wish to use for sending/receiving messages
     * to Google, including fetching Google's certificates. We recommend \GuzzleHttp\Client().
     *
     * @param CacheConfig|null $cacheConfig - optionally provide a configuration for caching, for us to cache the result
     * of fetching the Google SSO certificate information, so that the server does not have to look this up every time
     * a user logs in. This improves performance at the cost of extra setup/configuration.
     *
     * @param CsrfTokenStoreInterface $csrfTokenStore - where to keep the CSRF token for the current user, between them
     * leaving for Google and coming back. Defaults to $_SESSION. Pass your own to use your framework's session instead,
     * e.g. Laravel's, which doesn't use $_SESSION.
     *
     * @param MfaPolicyEnum $mfaPolicy - whether to ask Google how the user signed in to their Google account (REPORT),
     * and refuse users who didn't use multi-factor authentication (REQUIRE). Google only sends the amr and auth_time
     * claims to apps that are published ("In production"), verified, and have both "Authentication strength claims"
     * and "Session age claims" turned on (https://developers.google.com/identity/siwg/security-bundle). Until then,
     * Google sends nothing, so REPORT never reports MFA, and REQUIRE refuses everyone. Defaults to IGNORE, which
     * doesn't ask.
     *
     * @param int $jwtLeewaySeconds - how many seconds this server's clock may be out from Google's, when checking the
     * ID token's issued-at and expiry times. Defaults to 60.
     *
     * @param string $googleJwtCertsUrl - where google's JWT public key data is held, which we will use to verify that
     * the JWT we received on our handler is actually from Google, and someone isn't trying to hack their way in with
     * a made up JWT that wasn't from Google.
     *
     * @param string $googleOauthUrl - the base of Google's OAuth2 token endpoint, which "/token" is added to. You
     * shouldn't need to change it. It doesn't change the login page's URL, which is always accounts.google.com's.
     *
     * @throws \InvalidArgumentException - if $jwtLeewaySeconds is negative.
     */
    public function __construct(
        string $clientId,
        string $clientSecret,
        string $googleCallbackUrl,
        RequestFactoryInterface $requestFactory,
        ResponseFactoryInterface $responseFactory,
        ClientInterface $httpClient,
        ?CacheConfig $cacheConfig = null,
        CsrfTokenStoreInterface $csrfTokenStore = new NativeSessionCsrfTokenStore(),
        MfaPolicyEnum $mfaPolicy = MfaPolicyEnum::IGNORE,
        int $jwtLeewaySeconds = 60,
        string $googleJwtCertsUrl = "https://www.googleapis.com/oauth2/v3/certs",
        string $googleOauthUrl = "https://oauth2.googleapis.com",
    )
    {
        if ($jwtLeewaySeconds < 0)
        {
            throw new \InvalidArgumentException("The JWT leeway can't be negative, but {$jwtLeewaySeconds} was given.");
        }

        $this->m_clientId = $clientId;
        $this->m_clientSecret = $clientSecret;
        $this->m_googleCallbackUrl = $googleCallbackUrl;
        $this->m_requestFactory = $requestFactory;
        $this->m_responseFactory = $responseFactory;
        $this->m_httpClient = $httpClient;
        $this->m_cacheConfig = $cacheConfig;
        $this->m_csrfTokenStore = $csrfTokenStore;
        $this->m_mfaPolicy = $mfaPolicy;
        $this->m_jwtLeewaySeconds = $jwtLeewaySeconds;
        $this->m_googleJwtCertsUrl = $googleJwtCertsUrl;
        $this->m_googleOauthUrl = rtrim($googleOauthUrl, "/");
    }


    /**
     * Create and return the URL that a user can go to in order to start the "Login with Google" flow. The CSRF token is
     * kept in the CSRF token store, so with the default store, the session must have been started first. Calling this
     * more than once for the same user (e.g. for several links on a page) reuses the same token, until a callback uses
     * it up. The URL also has a PKCE code challenge, so that only this site can exchange the code Google gives back.
     *
     * @param LoginPromptEnum $prompt - what Google should show the user, e.g. LoginPromptEnum::SELECT_ACCOUNT to always
     * ask which Google account to use. Defaults to letting Google decide.
     *
     * @param string $hostedDomainHint - a Google Workspace domain (e.g. "example.com") to suggest to the user, so
     * Google offers that organisation's accounts first, or "*" for any Workspace account. This is only a hint, as the
     * user can still choose another account, so to only let in your Workspace's users, check getHostedDomain() on the
     * result of the callback. Defaults to "", for no hint.
     *
     * @param string $loginHint - the account to suggest, as an email address or Google ID (the "sub"), e.g. for a user
     * logging in again. Google fills it in, or picks that account, if it can. Defaults to "", for no hint.
     *
     * @return string - a Google URL the user needs to go to, in order to login with Google.
     *
     * @throws \InvalidArgumentException - if $hostedDomainHint isn't a domain, "*" or "".
     * @throws RandomException
     */
    public function createGoogleSsoLoginUrl(
        LoginPromptEnum $prompt = LoginPromptEnum::DEFAULT,
        string $hostedDomainHint = "",
        string $loginHint = "",
    ) : string
    {
        // check the hint before the CSRF token is created, so a mistake can't leave a token behind
        $optionalParameters = self::createOptionalLoginParameters($prompt, $hostedDomainHint, $loginHint);

        $existingToken = $this->m_csrfTokenStore->getToken();

        if ($existingToken !== "")
        {
            $stateToken = $existingToken;
        }
        else
        {
            $stateToken = bin2hex(random_bytes(16));
            $this->m_csrfTokenStore->setToken($stateToken);
        }

        $codeVerifier = $this->createPkceCodeVerifier($stateToken);

        // info about scopes
        // https://developers.google.com/identity/protocols/oauth2/scopes
        $queryParameters = [
            'scope' => implode(" ", ["email", "openid", "profile"]),
            'response_type' => 'code',
            'state' => $stateToken,
            'redirect_uri' => $this->m_googleCallbackUrl,
            'client_id' => $this->m_clientId,
            'code_challenge' => self::base64UrlEncode(hash("sha256", $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ];

        if ($this->m_mfaPolicy !== MfaPolicyEnum::IGNORE)
        {
            // ask for how and when the user signed in to Google, which Google only sends when asked
            // https://developers.google.com/identity/siwg/security-bundle
            $sessionClaims = ['id_token' => ['amr' => ['essential' => true], 'auth_time' => ['essential' => true]]];
            $queryParameters['claims'] = json_encode($sessionClaims, JSON_THROW_ON_ERROR);
        }

        $queryParameters += $optionalParameters;
        return "https://accounts.google.com/o/oauth2/auth?" . http_build_query($queryParameters);
    }


    /**
     * Create the response that sends the user's browser to Google to log in: a redirect to createGoogleSsoLoginUrl(),
     * for a framework's route to return, e.g. in Slim. It isn't to be cached, as it carries this user's CSRF token.
     *
     * @param LoginPromptEnum $prompt - what Google should show the user. See createGoogleSsoLoginUrl().
     *
     * @param string $hostedDomainHint - a Google Workspace domain to suggest. See createGoogleSsoLoginUrl().
     *
     * @param string $loginHint - the account to suggest. See createGoogleSsoLoginUrl().
     *
     * @return ResponseInterface - a 302 redirect to Google.
     *
     * @throws \InvalidArgumentException - if $hostedDomainHint isn't a domain, "*" or "".
     * @throws RandomException
     */
    public function createGoogleSsoLoginResponse(
        LoginPromptEnum $prompt = LoginPromptEnum::DEFAULT,
        string $hostedDomainHint = "",
        string $loginHint = "",
    ) : ResponseInterface
    {
        $url = $this->createGoogleSsoLoginUrl($prompt, $hostedDomainHint, $loginHint);

        return $this->m_responseFactory->createResponse(302)
            ->withHeader("Location", $url)
            ->withHeader("Cache-Control", "no-store");
    }


    /**
     * Handle Google redirecting the user back to your site after they have logged in (or cancelled). Call this on the
     * endpoint you gave as $googleCallbackUrl, passing it the request's query parameters, e.g. $_GET, or
     * $request->getQueryParams() in a PSR-7 framework.
     *
     * This checks the state against the stored CSRF token (which is then used up, whether it matched or not),
     * trades the code for an ID token (with the PKCE code verifier), checks the ID token's signature, expiry, issuer
     * and audience, and by default, refuses a user whose email address Google hasn't verified. With
     * MfaPolicyEnum::REQUIRE, it also checks that the user signed in to Google with multi-factor authentication.
     *
     * @param array<string, mixed> $queryParameters - the callback request's query parameters.
     *
     * @param UnverifiedEmailPolicyEnum $unverifiedEmailPolicy - what to do with a user whose email address Google
     * hasn't verified. Defaults to REFUSE, which throws an ExceptionUnverifiedEmail, so the email address of every user
     * returned can be trusted. Only pass ALLOW if you never find users by their email address, only by getSub().
     *
     * @return GoogleUserData
     *
     * @throws ExceptionGoogleLoginError - if Google sent back an error, e.g. the user cancelled (access_denied).
     * @throws ExceptionMissingCallbackParameter - if the state or code is missing.
     * @throws ExceptionMissingCsrfToken - if there was no stored CSRF token, e.g. the session wasn't started.
     * @throws ExceptionCsrfTokenMismatch - if the state doesn't match the stored CSRF token.
     * @throws ExceptionUnexpectedResponse - if Google's token or certificate endpoints responded with an error.
     * @throws ExceptionInvalidIdToken - if the ID token wasn't issued by Google, or to this client.
     * @throws ExceptionUnverifiedEmail - if Google hasn't verified the user's email address, and that is refused.
     * @throws ExceptionMfaRequired - with MfaPolicyEnum::REQUIRE, if Google doesn't say the user used MFA.
     * @throws \UnexpectedValueException - if php-jwt rejects the ID token, e.g. a bad signature, or it has expired.
     * @throws \DomainException - if php-jwt can't use the ID token or a certificate, e.g. an unsupported algorithm.
     * @throws \InvalidArgumentException - if php-jwt can't read one of Google's certificates.
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function handleGoogleSsoCallback(
        array $queryParameters,
        UnverifiedEmailPolicyEnum $unverifiedEmailPolicy = UnverifiedEmailPolicyEnum::REFUSE,
    ) : GoogleUserData
    {
        // use up the stored token straight away, so it can't be used by another callback, whatever happens next
        $sessionToken = $this->m_csrfTokenStore->getToken();
        $this->m_csrfTokenStore->removeToken();

        if (isset($queryParameters['error']))
        {
            $error = is_string($queryParameters['error']) ? $queryParameters['error'] : "unknown";
            throw new ExceptionGoogleLoginError($error);
        }

        $state = self::getRequiredQueryParameter($queryParameters, "state");

        if ($sessionToken === "")
        {
            throw new ExceptionMissingCsrfToken($state);
        }

        if (!hash_equals($sessionToken, $state))
        {
            throw new ExceptionCsrfTokenMismatch($state, $sessionToken);
        }

        $code = self::getRequiredQueryParameter($queryParameters, "code");
        $idToken = $this->exchangeCodeForIdToken($code, $this->createPkceCodeVerifier($state));
        $googleUser = $this->decodeIdToken($idToken);

        if ($unverifiedEmailPolicy === UnverifiedEmailPolicyEnum::REFUSE && !$googleUser->getEmailVerified())
        {
            throw new ExceptionUnverifiedEmail($googleUser->getEmail());
        }

        if ($this->m_mfaPolicy === MfaPolicyEnum::REQUIRE && !$googleUser->hasMultiFactorAuthentication())
        {
            throw new ExceptionMfaRequired($googleUser->getAuthenticationMethods());
        }

        return $googleUser;
    }


    /**
     * Trade the code Google gave the user for the ID token.
     * @param string $code - the code from the callback.
     * @param string $codeVerifier - the PKCE code verifier for the login, from createPkceCodeVerifier().
     * @return string - the encoded ID token.
     * @throws ExceptionUnexpectedResponse
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    private function exchangeCodeForIdToken(string $code, string $codeVerifier) : string
    {
        $parameters = [
            'grant_type' => "authorization_code",
            'code' => $code,
            'client_id' => $this->m_clientId,
            'client_secret' => $this->m_clientSecret,
            'redirect_uri' => $this->m_googleCallbackUrl,
            'code_verifier' => $codeVerifier,
        ];

        $request = $this->m_requestFactory->createRequest("POST", $this->m_googleOauthUrl . "/token");
        $request->getBody()->write(http_build_query($parameters));

        $request = $request
            ->withHeader("Content-Type", "application/x-www-form-urlencoded")
            ->withHeader("Accept", "application/json");

        $response = $this->m_httpClient->sendRequest($request);
        $responseArray = self::decodeJsonResponse($response);

        if (
               $response->getStatusCode() !== 200
            || !isset($responseArray['id_token'])
            || !is_string($responseArray['id_token'])
        )
        {
            $error = is_string($responseArray['error'] ?? null) ? $responseArray['error'] : "no error given";
            $statusCode = $response->getStatusCode();
            $hasDescription = is_string($responseArray['error_description'] ?? null);
            $description = $hasDescription ? " - {$responseArray['error_description']}" : "";
            $details = "HTTP {$statusCode}, error: {$error}{$description}";
            $message = "Google's token endpoint didn't return an ID token ({$details}).";
            throw new ExceptionUnexpectedResponse($response, $message);
        }

        return $responseArray['id_token'];
    }


    /**
     * Check the ID token's signature and expiry, and that it was issued by Google to this client.
     * @throws ExceptionInvalidIdToken
     * @throws ExceptionUnexpectedResponse
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    private function decodeIdToken(string $idToken) : GoogleUserData
    {
        $keys = JWK::parseKeySet($this->getGoogleJwtCertKeys(self::getKeyId($idToken)));

        // php-jwt's leeway is static, so put it back afterwards, rather than changing it for the rest of the process
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->m_jwtLeewaySeconds;

        try
        {
            $claims = JWT::decode($idToken, $keys);
        }
        finally
        {
            JWT::$leeway = $previousLeeway;
        }

        if (!isset($claims->iss) || !in_array($claims->iss, self::VALID_ISSUERS, true))
        {
            throw new ExceptionInvalidIdToken("The ID token wasn't issued by Google (its iss claim is wrong).");
        }

        if (!isset($claims->aud) || $claims->aud !== $this->m_clientId)
        {
            $message = "The ID token was issued to a different client (its aud claim is wrong).";
            throw new ExceptionInvalidIdToken($message);
        }

        return GoogleUserData::createFromJwt($claims);
    }


    /**
     * Work out the PKCE code verifier for a login, from its state (the CSRF token). PKCE binds the code Google gives
     * back to this login: the login URL sends Google a hash of the verifier, and only the verifier itself can then
     * exchange the code, so a code stolen from one login can't be used in another (e.g. by an attacker pasting it into
     * a callback in their own session, as the state check alone can't catch that).
     *
     * It is an HMAC of the state, keyed with the client secret, rather than another random value, so that nothing more
     * has to be kept in the CSRF token store, and the same state always gives the same verifier, even in another
     * process. Without the client secret, it can't be worked out from the state, which is in the login URL.
     *
     * @param string $state - the login's state.
     *
     * @return string - the code verifier: 43 characters of base64url, as PKCE needs between 43 and 128.
     */
    private function createPkceCodeVerifier(string $state) : string
    {
        $hmac = hash_hmac("sha256", "programster/google-sso PKCE code verifier: {$state}", $this->m_clientSecret, true);
        return self::base64UrlEncode($hmac);
    }


    /**
     * Get Google's certificates, to check the ID token's signature with. They come from the cache, if there is one, and
     * it has the key the ID token was signed with. Otherwise, they are fetched from Google (and cached), which picks up
     * a key that Google has started using since they were cached, rather than refusing logins until the cache expires.
     *
     * @param string $keyId - the ID of the key the ID token was signed with (its "kid"), or "" if it doesn't say.
     *
     * @return array<string, mixed> - the decoded JWK set.
     *
     * @throws ExceptionUnexpectedResponse
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    private function getGoogleJwtCertKeys(string $keyId) : array
    {
        $cachedKeySet = $this->getCachedGoogleJwtCertKeys();

        # A token that doesn't say which key it was signed with can't be helped by fetching them again.
        $canUseCache = (
               $cachedKeySet !== []
            && ($keyId === "" || self::keySetHasKey($cachedKeySet, $keyId))
        );

        if ($canUseCache)
        {
            $keySet = $cachedKeySet;
        }
        else
        {
            $keySet = $this->fetchGoogleJwtCertKeys();
            $this->cacheGoogleJwtCertKeys($keySet);
        }

        return $keySet;
    }


    /**
     * Get Google's certificates from the cache.
     * @return array<string, mixed> - the decoded JWK set, or an empty array if there's no cache, or nothing in it.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getCachedGoogleJwtCertKeys() : array
    {
        if ($this->m_cacheConfig === null)
        {
            $keySet = [];
        }
        else
        {
            $item = $this->m_cacheConfig->getCacheItemPool()->getItem($this->m_cacheConfig->getCacheKey());
            $keySet = $item->isHit() ? self::decodeKeySet($item->get()) : [];
        }

        return $keySet;
    }


    /**
     * Cache Google's certificates, if there is a cache.
     * @param array<string, mixed> $keySet - the decoded JWK set, which has been checked to be valid.
     * @return void
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function cacheGoogleJwtCertKeys(array $keySet) : void
    {
        if ($this->m_cacheConfig !== null)
        {
            $cachePool = $this->m_cacheConfig->getCacheItemPool();
            $item = $cachePool->getItem($this->m_cacheConfig->getCacheKey());
            $item->set(json_encode($keySet, JSON_THROW_ON_ERROR));
            $item->expiresAfter($this->m_cacheConfig->getJwtCachePeriod());
            $cachePool->save($item);
        }
    }


    /**
     * Fetch Google's JWT certificates, using the injected HTTP client.
     * @return array<string, mixed> - the decoded JWK set.
     * @throws ExceptionUnexpectedResponse
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    private function fetchGoogleJwtCertKeys() : array
    {
        $request = $this->m_requestFactory->createRequest("GET", $this->m_googleJwtCertsUrl);
        $response = $this->m_httpClient->sendRequest($request->withHeader("Accept", "application/json"));
        $keySet = self::decodeKeySet((string)$response->getBody());

        if ($response->getStatusCode() !== 200 || $keySet === [])
        {
            $statusCode = $response->getStatusCode();
            $message = "Google's certificates endpoint returned HTTP {$statusCode} without a valid key set.";
            throw new ExceptionUnexpectedResponse($response, $message);
        }

        return $keySet;
    }


    /**
     * Decode a JSON response body into an array.
     * @return array<mixed>
     * @throws ExceptionUnexpectedResponse - if the body isn't a JSON object or array.
     */
    private static function decodeJsonResponse(ResponseInterface $response) : array
    {
        try
        {
            $decoded = json_decode(json: (string)$response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $e)
        {
            throw new ExceptionUnexpectedResponse($response, "Google's response wasn't valid JSON.");
        }

        if (!is_array($decoded))
        {
            throw new ExceptionUnexpectedResponse($response, "Google's response wasn't a JSON object.");
        }

        return $decoded;
    }


    /**
     * Decode a JWK set from JSON (fetched, or cached by this or an earlier version of this package).
     * @param mixed $json - the JSON. Anything other than a string (e.g. a corrupt cache entry) counts as invalid.
     * @return array<string, mixed> - the decoded key set, or an empty array if it isn't a valid key set.
     */
    private static function decodeKeySet(mixed $json) : array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        $isValid = (
               is_array($decoded)
            && isset($decoded['keys'])
            && is_array($decoded['keys'])
            && $decoded['keys'] !== []
        );

        return $isValid ? $decoded : [];
    }


    /**
     * Get the ID of the key a JWT says it was signed with (the "kid" in its header), without checking anything else.
     * @param string $jwt - the encoded JWT.
     * @return string - the key's ID, or "" if the JWT doesn't say, or can't be read (which JWT::decode() then reports).
     */
    private static function getKeyId(string $jwt) : string
    {
        $segments = explode(".", $jwt);
        $headerJson = (count($segments) === 3) ? JWT::urlsafeB64Decode($segments[0]) : "";
        $header = json_decode($headerJson, true);
        $hasKeyId = (is_array($header) && isset($header['kid']) && is_string($header['kid']));
        return $hasKeyId ? $header['kid'] : "";
    }


    /**
     * Check whether a JWK set has a key with the given ID.
     * @param array<string, mixed> $keySet - the decoded JWK set, which has been checked to be valid.
     * @param string $keyId - the key's ID (its "kid").
     * @return bool
     */
    private static function keySetHasKey(array $keySet, string $keyId) : bool
    {
        $keyIds = [];

        foreach ($keySet['keys'] as $key)
        {
            if (is_array($key) && isset($key['kid']) && is_string($key['kid']))
            {
                $keyIds[] = $key['kid'];
            }
        }

        return in_array($keyId, $keyIds, true);
    }


    /**
     * Create the login URL's optional query parameters, leaving out any that aren't wanted.
     * @param LoginPromptEnum $prompt - what Google should show the user.
     * @param string $hostedDomainHint - a Google Workspace domain, "*", or "" for none.
     * @param string $loginHint - an email address or Google ID, or "" for none.
     * @return array<string, string>
     * @throws \InvalidArgumentException - if $hostedDomainHint isn't a domain, "*" or "".
     */
    private static function createOptionalLoginParameters(
        LoginPromptEnum $prompt,
        string $hostedDomainHint,
        string $loginHint
    ) : array
    {
        $isDomain = (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+\z/i', $hostedDomainHint) === 1);

        if ($hostedDomainHint !== "" && $hostedDomainHint !== "*" && !$isDomain)
        {
            $message = (
                "The hosted domain hint must be a domain such as \"example.com\" (with no \"@\", \"https://\" or path),"
                . " \"*\" for any Google Workspace account, or \"\" for none, but \"{$hostedDomainHint}\" was given."
            );

            throw new \InvalidArgumentException($message);
        }

        $parameters = [];

        if ($prompt !== LoginPromptEnum::DEFAULT)
        {
            $parameters['prompt'] = $prompt->value;
        }

        if ($hostedDomainHint !== "")
        {
            $parameters['hd'] = $hostedDomainHint;
        }

        if ($loginHint !== "")
        {
            $parameters['login_hint'] = $loginHint;
        }

        return $parameters;
    }


    /**
     * Encode binary data as base64url (RFC 4648), without padding, as PKCE uses.
     * @param string $data - the binary data.
     * @return string
     */
    private static function base64UrlEncode(string $data) : string
    {
        return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
    }


    /**
     * Get a query parameter that Google always sends to the callback.
     * @param array<string, mixed> $queryParameters
     * @throws ExceptionMissingCallbackParameter - if it's missing, empty, or not a string (e.g. "?code[]=").
     */
    private static function getRequiredQueryParameter(array $queryParameters, string $name) : string
    {
        if (!isset($queryParameters[$name]) || !is_string($queryParameters[$name]) || $queryParameters[$name] === "")
        {
            throw new ExceptionMissingCallbackParameter($name);
        }

        return $queryParameters[$name];
    }
}
