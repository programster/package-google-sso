<?php

/*
 * Smoke tests for PKCE (RFC 7636), which binds the code Google gives back to the login it was given for. These are run
 * by testing/main.php, which provides $smoke, and gives each test an empty $_SESSION and $_GET.
 */

declare(strict_types = 1);

use GuzzleHttp\Psr7\HttpFactory;
use Programster\GoogleSso\GoogleSsoClient;
use Programster\GoogleSso\Testing\FakeGoogleHttpClient;
use Programster\GoogleSso\Testing\TestFixtures;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */

$getLoginUrlParameters = function (string $loginUrl) : array {
    parse_str(parse_url($loginUrl, PHP_URL_QUERY), $query);
    return $query;
};

$getSentCodeVerifier = function (FakeGoogleHttpClient $fakeGoogle) : string {
    parse_str((string)$fakeGoogle->getLastTokenRequest()->getBody(), $body);
    return $body['code_verifier'] ?? "";
};

$hashCodeVerifier = function (string $codeVerifier) : string {
    return rtrim(strtr(base64_encode(hash("sha256", $codeVerifier, true)), "+/", "-_"), "=");
};

$testChallengeMatches = function () use ($getLoginUrlParameters, $getSentCodeVerifier, $hashCodeVerifier) : void {
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $client = TestFixtures::createClient($fakeGoogle);
    $query = $getLoginUrlParameters($client->createGoogleSsoLoginUrl());
    assert($query['code_challenge_method'] === "S256", "the challenge should be S256, not plain");
    assert(preg_match('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']) === 1, "the challenge should be base64url");
    $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($query['state']));
    $codeVerifier = $getSentCodeVerifier($fakeGoogle);
    assert(preg_match('/^[A-Za-z0-9_-]{43,128}$/', $codeVerifier) === 1, "the verifier should be valid for PKCE");
    assert($hashCodeVerifier($codeVerifier) === $query['code_challenge'], "the verifier should match the challenge");
};

$smoke->run("PKCE: the token request's code verifier matches the login URL's challenge", $testChallengeMatches);

$testAnotherProcess = function () use ($getLoginUrlParameters, $getSentCodeVerifier, $hashCodeVerifier) : void {
    # The login URL and the callback are usually created by different requests, with different client instances.
    $loginClient = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $query = $getLoginUrlParameters($loginClient->createGoogleSsoLoginUrl());
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $callbackClient = TestFixtures::createClient($fakeGoogle);
    $callbackClient->handleGoogleSsoCallback(TestFixtures::callbackParameters($query['state']));
    $codeVerifier = $getSentCodeVerifier($fakeGoogle);
    $message = "another instance should get the same verifier";
    assert($hashCodeVerifier($codeVerifier) === $query['code_challenge'], $message);
};

$smoke->run("PKCE: a different client instance handling the callback sends the matching verifier", $testAnotherProcess);

$testUniquePerLogin = function () use ($getLoginUrlParameters) : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $firstQuery = $getLoginUrlParameters($client->createGoogleSsoLoginUrl());
    $_SESSION = [];
    $secondQuery = $getLoginUrlParameters($client->createGoogleSsoLoginUrl());
    assert($firstQuery['code_challenge'] !== $secondQuery['code_challenge'], "each login should get its own challenge");
};

$smoke->run("PKCE: each login gets its own challenge", $testUniquePerLogin);

$testDependsOnSecret = function () use ($getLoginUrlParameters) : void {
    # The state is in the login URL, so the verifier mustn't be something anyone could work out from it.
    $_SESSION[TestFixtures::SESSION_KEY] = "a-known-state";
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $challenge = $getLoginUrlParameters($client->createGoogleSsoLoginUrl())['code_challenge'];

    $clientWithOtherSecret = new GoogleSsoClient(
        clientId: TestFixtures::CLIENT_ID,
        clientSecret: "another-secret",
        googleCallbackUrl: TestFixtures::CALLBACK_URL,
        requestFactory: new HttpFactory(),
        responseFactory: new HttpFactory(),
        httpClient: FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()),
    );

    $otherChallenge = $getLoginUrlParameters($clientWithOtherSecret->createGoogleSsoLoginUrl())['code_challenge'];
    assert($challenge !== $otherChallenge, "the verifier should depend on the client secret, not just the state");
};

$smoke->run("PKCE: the verifier can't be worked out from the state without the client secret", $testDependsOnSecret);
