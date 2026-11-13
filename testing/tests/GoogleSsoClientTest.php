<?php

/*
 * Smoke tests for GoogleSsoClient, which log in against a fake Google (see FakeGoogleHttpClient). These are run by
 * testing/main.php, which provides $smoke, and gives each test an empty $_SESSION and $_GET.
 */

declare(strict_types = 1);

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use GuzzleHttp\Psr7\Response;
use Programster\GoogleSso\Exceptions\ExceptionCsrfTokenMismatch;
use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;
use Programster\GoogleSso\Exceptions\ExceptionInvalidIdToken;
use Programster\GoogleSso\Exceptions\ExceptionMissingCallbackParameter;
use Programster\GoogleSso\Exceptions\ExceptionMissingCsrfToken;
use Programster\GoogleSso\Exceptions\ExceptionUnexpectedResponse;
use Programster\GoogleSso\Testing\FakeGoogleHttpClient;
use Programster\GoogleSso\Testing\SmokeTest;
use Programster\GoogleSso\Testing\TestFixtures;
use Programster\GoogleSso\Testing\TestSigningKey;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */


# Logging in

$testLoginUrl = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $url = $client->createGoogleSsoLoginUrl();
    assert(str_starts_with($url, "https://accounts.google.com/o/oauth2/auth?"), "unexpected login URL: {$url}");
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    assert($query['scope'] === "email openid profile" && $query['response_type'] === "code");
    assert($query['client_id'] === TestFixtures::CLIENT_ID && $query['redirect_uri'] === TestFixtures::CALLBACK_URL);
    assert($query['state'] === $_SESSION[TestFixtures::SESSION_KEY], "the state should be the session's CSRF token");
    assert(strlen($query['state']) === 32, "the state should be 16 random bytes in hex");
    assert(!isset($query['access_type']), "the login shouldn't ask for offline access");
};

$smoke->run("createGoogleSsoLoginUrl builds Google's login URL", $testLoginUrl);

$testLoginUrlState = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $firstUrl = $client->createGoogleSsoLoginUrl();
    assert($client->createGoogleSsoLoginUrl() === $firstUrl, "several links on one page should share a state");

    # A different user (session) must get a different state, even from the same client instance.
    $firstState = $_SESSION[TestFixtures::SESSION_KEY];
    $_SESSION = [];
    assert(TestFixtures::startLogin($client) !== $firstState, "another session should get another state");
};

$smoke->run("createGoogleSsoLoginUrl reuses the state within a session, but not between sessions", $testLoginUrlState);

$testSuccessfulLogin = function () : void {
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims(['hd' => 'example.com']));
    $client = TestFixtures::createClient($fakeGoogle);
    $state = TestFixtures::startLogin($client);
    $user = $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state));
    assert($user->getEmail() === 'someone@example.com' && $user->getEmailVerified() === true);
    assert($user->getHostedDomain() === 'example.com' && $user->getAud() === TestFixtures::CLIENT_ID);
    assert($fakeGoogle->getNumCertsRequests() === 1, "the certificates should be fetched through the injected client");
};

$smoke->run("handleGoogleSsoCallback logs the user in", $testSuccessfulLogin);

$testTokenRequest = function () : void {
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $client = TestFixtures::createClient($fakeGoogle);
    $client->handleGoogleSsoCallback(TestFixtures::callbackParameters(TestFixtures::startLogin($client)));
    $request = $fakeGoogle->getLastTokenRequest();
    assert($request->getHeaderLine('Content-Type') === "application/x-www-form-urlencoded");
    parse_str((string)$request->getBody(), $body);

    $expectedBody = [
        'grant_type' => "authorization_code",
        'code' => TestFixtures::callbackParameters("")['code'],
        'client_id' => TestFixtures::CLIENT_ID,
        'client_secret' => TestFixtures::CLIENT_SECRET,
        'redirect_uri' => TestFixtures::CALLBACK_URL,
        'code_verifier' => $body['code_verifier'] ?? "",
    ];

    assert($body === $expectedBody, "unexpected token request body: " . $request->getBody());
    assert(preg_match('/^[A-Za-z0-9_-]{43}$/', $expectedBody['code_verifier']) === 1, "the code verifier is invalid");
};

$smoke->run("handleGoogleSsoCallback sends a form-encoded token request", $testTokenRequest);

$testDeprecatedHandler = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $state = TestFixtures::startLogin($client);
    $_GET = TestFixtures::callbackParameters($state);
    assert($client->handleGoogleSsoLogin()->getEmail() === 'someone@example.com', "it should read \$_GET");

    # Its parameters override $_GET, for frameworks that don't fill it in.
    $state = TestFixtures::startLogin($client);
    $_GET = ['code' => 'ignored', 'state' => 'ignored'];
    $user = $client->handleGoogleSsoLogin(TestFixtures::callbackParameters($state)['code'], $state);
    assert($user->getEmail() === 'someone@example.com');
};

$smoke->run("handleGoogleSsoLogin (deprecated) still works", $testDeprecatedHandler);


# The callback's state (CSRF token)

$testReplayedCallback = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $parameters = TestFixtures::callbackParameters(TestFixtures::startLogin($client));
    $client->handleGoogleSsoCallback($parameters);
    assert(!isset($_SESSION[TestFixtures::SESSION_KEY]), "the state should be used up by the callback");

    $replayCallback = function () use ($client, $parameters) : void {
        $client->handleGoogleSsoCallback($parameters);
    };

    SmokeTest::expectException(ExceptionMissingCsrfToken::class, $replayCallback);
};

$smoke->run("handleGoogleSsoCallback uses up the state, so a replayed callback is refused", $testReplayedCallback);

$testWrongState = function () : void {
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $client = TestFixtures::createClient($fakeGoogle);
    $state = TestFixtures::startLogin($client);

    $callbackWithWrongState = function () use ($client, $state) : void {
        $client->handleGoogleSsoCallback(TestFixtures::callbackParameters(strrev($state)));
    };

    $exception = SmokeTest::expectException(ExceptionCsrfTokenMismatch::class, $callbackWithWrongState);
    assert(!($exception instanceof ExceptionMissingCsrfToken));
    assert($exception->getGoogleResponseToken() === strrev($state) && $exception->getCachedStateToken() === $state);
    assert(!isset($_SESSION[TestFixtures::SESSION_KEY]), "a failed check should use up the state too");
    assert($fakeGoogle->getNumTokenRequests() === 0, "the code shouldn't be sent to Google");
};

$smoke->run("handleGoogleSsoCallback refuses the wrong state, and uses it up", $testWrongState);

$testNoSessionToken = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));

    $callbackWithoutLogin = function () use ($client) : void {
        $client->handleGoogleSsoCallback(TestFixtures::callbackParameters("a-state-we-never-issued"));
    };

    $exception = SmokeTest::expectException(ExceptionMissingCsrfToken::class, $callbackWithoutLogin);
    assert($exception instanceof ExceptionCsrfTokenMismatch, "it should still be caught as a CSRF mismatch");
    assert($exception->getGoogleResponseToken() === "a-state-we-never-issued");
    assert($exception->getCachedStateToken() === "", "there was no stored token");
};

$smoke->run("handleGoogleSsoCallback refuses a callback when the session has no state", $testNoSessionToken);


# Bad callbacks

$testCancelledLogin = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $state = TestFixtures::startLogin($client);

    $callbackAfterCancelling = function () use ($client, $state) : void {
        $client->handleGoogleSsoCallback(['error' => 'access_denied', 'state' => $state]);
    };

    $exception = SmokeTest::expectException(ExceptionGoogleLoginError::class, $callbackAfterCancelling);
    assert($exception instanceof ExceptionGoogleLoginError && $exception->isAccessDenied());
    assert($exception->getError() === 'access_denied');
    assert(!isset($_SESSION[TestFixtures::SESSION_KEY]), "a cancelled login should use up the state");
};

$smoke->run("handleGoogleSsoCallback throws ExceptionGoogleLoginError when the user cancels", $testCancelledLogin);

$testMissingParameters = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    TestFixtures::startLogin($client);

    $handleWithoutState = function () use ($client) : void {
        $client->handleGoogleSsoCallback(['code' => 'a-code']);
    };

    $exception = SmokeTest::expectException(ExceptionMissingCallbackParameter::class, $handleWithoutState);
    assert($exception instanceof ExceptionMissingCallbackParameter && $exception->getParameterName() === 'state');
    $stateWithoutCode = ['state' => TestFixtures::startLogin($client)];

    $handleWithoutCode = function () use ($client, $stateWithoutCode) : void {
        $client->handleGoogleSsoCallback($stateWithoutCode);
    };

    $exception = SmokeTest::expectException(ExceptionMissingCallbackParameter::class, $handleWithoutCode);
    assert($exception instanceof ExceptionMissingCallbackParameter && $exception->getParameterName() === 'code');

    # PHP turns ?code[]=x into an array, which mustn't be sent to Google.
    $parameters = ['code' => ['x'], 'state' => TestFixtures::startLogin($client)];

    $handleArrayCode = function () use ($client, $parameters) : void {
        $client->handleGoogleSsoCallback($parameters);
    };

    SmokeTest::expectException(ExceptionMissingCallbackParameter::class, $handleArrayCode, "\"code\"");
};

$smoke->run("handleGoogleSsoCallback throws for a missing code or state", $testMissingParameters);


# Google's token endpoint failing

$testTokenEndpointErrors = function () : void {
    $invalidGrantBody = json_encode(['error' => 'invalid_grant', 'error_description' => 'Bad Request']);
    # An error status counts as a failure, even if the body somehow has an ID token in it.
    $errorWithIdToken = json_encode(['id_token' => TestSigningKey::get()->signIdToken(TestFixtures::validClaims())]);

    $json = ['Content-Type' => 'application/json'];

    $failures = [
        'HTTP 400, error: invalid_grant - Bad Request' => new Response(400, $json, $invalidGrantBody),
        'HTTP 500, error: no error given' => new Response(500, $json, $errorWithIdToken),
        "wasn't valid JSON" => new Response(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>'),
        'HTTP 200, error: no error given' => new Response(200, $json, '{"access_token":"x"}'),
    ];

    foreach ($failures as $expectedMessage => $tokenResponse)
    {
        $client = TestFixtures::createClient(FakeGoogleHttpClient::respondingToTokenRequestsWith($tokenResponse));
        $parameters = TestFixtures::callbackParameters(TestFixtures::startLogin($client));

        $handleCallback = function () use ($client, $parameters) : void {
            $client->handleGoogleSsoCallback($parameters);
        };

        $exception = SmokeTest::expectException(ExceptionUnexpectedResponse::class, $handleCallback, $expectedMessage);
        assert($exception instanceof ExceptionUnexpectedResponse && $exception->getResponse() === $tokenResponse);
    }
};

$smoke->run("handleGoogleSsoCallback throws ExceptionUnexpectedResponse for token errors", $testTokenEndpointErrors);


# Checking the ID token

$testRefusedIdTokens = function () : void {
    $refusals = [
        'aud' => [ExceptionInvalidIdToken::class, TestFixtures::validClaims(['aud' => 'another-app'])],
        'iss' => [ExceptionInvalidIdToken::class, TestFixtures::validClaims(['iss' => 'https://evil.example.com'])],
        'expired' => [ExpiredException::class, TestFixtures::validClaims(['exp' => time() - 120])],
        'sub' => [ExceptionInvalidIdToken::class, array_diff_key(TestFixtures::validClaims(), ['sub' => true])],
    ];

    foreach ($refusals as $label => [$exceptionClass, $claims])
    {
        $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken($claims));
        $parameters = TestFixtures::callbackParameters(TestFixtures::startLogin($client));

        $handleCallback = function () use ($client, $parameters) : void {
            $client->handleGoogleSsoCallback($parameters);
        };

        try
        {
            SmokeTest::expectException($exceptionClass, $handleCallback);
        }
        catch (\Throwable $e)
        {
            throw new \Exception("{$label}: " . $e->getMessage(), 0, $e);
        }
    }
};

$smoke->run("handleGoogleSsoCallback refuses ID tokens for another client, issuer, or expired", $testRefusedIdTokens);

$testIssuerWithoutScheme = function () : void {
    $claims = TestFixtures::validClaims(['iss' => 'accounts.google.com']);
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken($claims));
    $user = $client->handleGoogleSsoCallback(TestFixtures::callbackParameters(TestFixtures::startLogin($client)));
    assert($user->getIss() === 'accounts.google.com');
};

$smoke->run("handleGoogleSsoCallback accepts Google's issuer without https://", $testIssuerWithoutScheme);

$testForgedSignature = function () : void {
    # Signed by a key with the same ID as Google's, but not in Google's certificates.
    $fakeGoogle = FakeGoogleHttpClient::issuingIdTokenSignedWith(TestSigningKey::create(), TestFixtures::validClaims());
    $client = TestFixtures::createClient($fakeGoogle);
    $parameters = TestFixtures::callbackParameters(TestFixtures::startLogin($client));

    $handleForgedCallback = function () use ($client, $parameters) : void {
        $client->handleGoogleSsoCallback($parameters);
    };

    SmokeTest::expectException(SignatureInvalidException::class, $handleForgedCallback);
};

$smoke->run("handleGoogleSsoCallback refuses an ID token that Google didn't sign", $testForgedSignature);

$testLeeway = function () : void {
    # A token issued 30 seconds "in the future" is fine with the default leeway of 60 seconds.
    JWT::$leeway = 7;
    $claims = TestFixtures::validClaims(['iat' => time() + 30]);
    $lenientClient = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken($claims));
    $lenientState = TestFixtures::startLogin($lenientClient);
    $lenientClient->handleGoogleSsoCallback(TestFixtures::callbackParameters($lenientState));
    assert(JWT::$leeway === 7, "php-jwt's global leeway should be put back after a login");

    # ... but not with no leeway at all.
    $strictClient = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken($claims), 0);
    $strictState = TestFixtures::startLogin($strictClient);

    $handleStrictCallback = function () use ($strictClient, $strictState) : void {
        $strictClient->handleGoogleSsoCallback(TestFixtures::callbackParameters($strictState));
    };

    SmokeTest::expectException(BeforeValidException::class, $handleStrictCallback);
    assert(JWT::$leeway === 7, "php-jwt's global leeway should be put back after a failed login");
    JWT::$leeway = 0;
};

$smoke->run("jwtLeewaySeconds allows for clock skew, and leaves php-jwt's global leeway alone", $testLeeway);

$testNegativeLeeway = function () : void {
    $createWithNegativeLeeway = function () : void {
        TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()), -1);
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createWithNegativeLeeway, "-1");
};

$smoke->run("the constructor refuses a negative jwtLeewaySeconds", $testNegativeLeeway);


# Google's certificates

$testCertsEndpointErrors = function () : void {
    $badCertsResponses = [
        new Response(503, [], 'Service Unavailable'),
        # An error status counts as a failure, even if the body is a valid key set.
        new Response(500, ['Content-Type' => 'application/json'], TestSigningKey::get()->getJwks()),
        new Response(200, ['Content-Type' => 'application/json'], '{"keys":[]}'),
        new Response(200, ['Content-Type' => 'text/html'], '<html></html>'),
    ];

    foreach ($badCertsResponses as $certsResponse)
    {
        $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
        $fakeGoogle = $fakeGoogle->withCertsResponse($certsResponse);
        $client = TestFixtures::createClient($fakeGoogle);
        $parameters = TestFixtures::callbackParameters(TestFixtures::startLogin($client));

        $handleCallback = function () use ($client, $parameters) : void {
            $client->handleGoogleSsoCallback($parameters);
        };

        $exception = SmokeTest::expectException(ExceptionUnexpectedResponse::class, $handleCallback, "certificates");
        assert($exception instanceof ExceptionUnexpectedResponse && $exception->getResponse() === $certsResponse);
    }
};

$smoke->run("handleGoogleSsoCallback throws ExceptionUnexpectedResponse for cert errors", $testCertsEndpointErrors);

$testCertsCaching = function () : void {
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $cachePool = new ArrayAdapter();
    $client = TestFixtures::createCachingClient($fakeGoogle, $cachePool);

    for ($i = 0; $i < 3; $i++)
    {
        $client->handleGoogleSsoCallback(TestFixtures::callbackParameters(TestFixtures::startLogin($client)));
    }

    assert($fakeGoogle->getNumCertsRequests() === 1, "the certificates should be fetched once, then cached");
};

$smoke->run("CacheConfig caches Google's certificates", $testCertsCaching);

$testBadCacheEntry = function () : void {
    # Earlier versions cached whatever file_get_contents() returned, which was false if the fetch failed.
    $cachePool = new ArrayAdapter();
    $item = $cachePool->getItem("jwtCerts");
    $item->set(false);
    $cachePool->save($item);
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $client = TestFixtures::createCachingClient($fakeGoogle, $cachePool);
    $client->handleGoogleSsoCallback(TestFixtures::callbackParameters(TestFixtures::startLogin($client)));
    assert($fakeGoogle->getNumCertsRequests() === 1, "a bad cache entry should be fetched again");
    assert(is_string($cachePool->getItem("jwtCerts")->get()), "the fetched certificates should replace it");
};

$smoke->run("CacheConfig replaces a bad cache entry with freshly fetched certificates", $testBadCacheEntry);

$testRotatedKey = function () : void {
    # The cache holds Google's old certificates, from before it started signing with the current key.
    $cachePool = new ArrayAdapter();
    $item = $cachePool->getItem("jwtCerts");
    $item->set(TestSigningKey::create("an-old-key")->getJwks());
    $cachePool->save($item);
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $client = TestFixtures::createCachingClient($fakeGoogle, $cachePool);
    $user = $client->handleGoogleSsoCallback(TestFixtures::callbackParameters(TestFixtures::startLogin($client)));
    assert($user->getEmail() === 'someone@example.com', "a login with a newly rotated key should still work");
    assert($fakeGoogle->getNumCertsRequests() === 1, "the certificates should be fetched again, once");
    $cachedJwks = $cachePool->getItem("jwtCerts")->get();
    assert(str_contains($cachedJwks, TestSigningKey::KEY_ID), "the fresh certificates should replace the cached ones");

    # The next login finds the new key in the cache, so doesn't fetch them again.
    $client->handleGoogleSsoCallback(TestFixtures::callbackParameters(TestFixtures::startLogin($client)));
    assert($fakeGoogle->getNumCertsRequests() === 1, "the refreshed cache should be used for the next login");
};

$smoke->run("cached certificates are fetched again when Google has started using a new key", $testRotatedKey);

$testUnknownKey = function () : void {
    $cachePool = new ArrayAdapter();
    $item = $cachePool->getItem("jwtCerts");
    $item->set(TestSigningKey::get()->getJwks());
    $cachePool->save($item);
    $unknownKey = TestSigningKey::create("a-key-google-never-published");
    $fakeGoogle = FakeGoogleHttpClient::issuingIdTokenSignedWith($unknownKey, TestFixtures::validClaims());
    $client = TestFixtures::createCachingClient($fakeGoogle, $cachePool);
    $parameters = TestFixtures::callbackParameters(TestFixtures::startLogin($client));

    $handleCallback = function () use ($client, $parameters) : void {
        $client->handleGoogleSsoCallback($parameters);
    };

    SmokeTest::expectException(\UnexpectedValueException::class, $handleCallback, '"kid" invalid');
    assert($fakeGoogle->getNumCertsRequests() === 1, "the certificates should only be fetched again once");
};

$smoke->run("a key that isn't in Google's fresh certificates either is refused after one fetch", $testUnknownKey);

$testSessionClaimsThroughCallback = function () : void {
    $authTime = time() - 60;
    $claims = TestFixtures::validClaims(['amr' => ["swk"], 'auth_time' => $authTime]);
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken($claims));
    $state = TestFixtures::startLogin($client);
    $googleUser = $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state));
    assert($googleUser->getAuthenticationMethods() === ["swk"] && $googleUser->getAuthTime() === $authTime);
    assert($googleUser->hasMultiFactorAuthentication());
};

$smoke->run("handleGoogleSsoCallback passes on the amr and auth_time claims", $testSessionClaimsThroughCallback);
