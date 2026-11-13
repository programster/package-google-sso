<?php

/*
 * Smoke tests for the CSRF token stores, and how GoogleSsoClient uses them. These are run by testing/main.php, which
 * provides $smoke, and gives each test an empty $_SESSION and $_GET.
 */

declare(strict_types = 1);

use Programster\GoogleSso\Exceptions\ExceptionMissingCsrfToken;
use Programster\GoogleSso\NativeSessionCsrfTokenStore;
use Programster\GoogleSso\Testing\FakeGoogleHttpClient;
use Programster\GoogleSso\Testing\InMemoryCsrfTokenStore;
use Programster\GoogleSso\Testing\SmokeTest;
use Programster\GoogleSso\Testing\TestFixtures;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */

$testNativeStore = function () : void {
    $store = new NativeSessionCsrfTokenStore();
    assert($store->getKey() === "googleSsoCsrfToken" && $store->getToken() === "");
    $store->setToken("abc");
    assert($_SESSION["googleSsoCsrfToken"] === "abc" && $store->getToken() === "abc");
    $store->removeToken();
    assert(!isset($_SESSION["googleSsoCsrfToken"]) && $store->getToken() === "");

    # Something other than a string under the key (e.g. left by other code) counts as no token.
    $_SESSION["googleSsoCsrfToken"] = ["not", "a", "token"];
    assert($store->getToken() === "");

};

$smoke->run("NativeSessionCsrfTokenStore keeps the token in \$_SESSION", $testNativeStore);

$testNativeStoreKey = function () : void {
    $store = new NativeSessionCsrfTokenStore("myToken");
    $store->setToken("abc");
    assert($_SESSION === ["myToken" => "abc"], "the token should be kept under the given key");

    $createWithEmptyKey = function () : void {
        new NativeSessionCsrfTokenStore("");
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createWithEmptyKey, "can't be empty");
};

$smoke->run("NativeSessionCsrfTokenStore uses the given key, which can't be empty", $testNativeStoreKey);

$testNoSession = function () : void {
    # The test runner starts a session, so put it aside for this test, and start it again afterwards.
    session_abort();

    try
    {
        $store = new NativeSessionCsrfTokenStore();
        $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));

        $useStore = [
            "getToken" => function () use ($store) : void { $store->getToken(); },
            "setToken" => function () use ($store) : void { $store->setToken("abc"); },
            "removeToken" => function () use ($store) : void { $store->removeToken(); },
            "createGoogleSsoLoginUrl" => function () use ($client) : void { $client->createGoogleSsoLoginUrl(); },
        ];

        foreach ($useStore as $use)
        {
            SmokeTest::expectException(\LogicException::class, $use, "session_start()");
        }
    }
    finally
    {
        session_start(['use_cookies' => 0, 'use_only_cookies' => 1, 'cache_limiter' => '']);
    }
};

$smoke->run("NativeSessionCsrfTokenStore refuses to work without a started session", $testNoSession);

$testCustomStore = function () : void {
    $store = new InMemoryCsrfTokenStore();
    $client = TestFixtures::createClientWithCsrfStore($store);
    $loginUrl = $client->createGoogleSsoLoginUrl();
    $state = $store->getToken();
    assert($state !== "" && str_contains($loginUrl, "state={$state}"), "the state should come from the store");
    assert($client->createGoogleSsoLoginUrl() === $loginUrl, "the store's token should be reused");
    assert($_SESSION === [], "\$_SESSION shouldn't be touched when another store is given");
    $user = $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state));
    assert($user->getEmail() === 'someone@example.com');
    assert($store->getToken() === "", "the callback should use up the store's token");

    $replayCallback = function () use ($client, $state) : void {
        $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state));
    };

    SmokeTest::expectException(ExceptionMissingCsrfToken::class, $replayCallback);
};

$smoke->run("GoogleSsoClient uses the csrfTokenStore it is given, rather than \$_SESSION", $testCustomStore);

$testNativeStoreWithKey = function () : void {
    $client = TestFixtures::createClientWithCsrfStore(new NativeSessionCsrfTokenStore("myToken"));
    $state = TestFixtures::startLoginWithSessionKey($client, "myToken");
    assert(!isset($_SESSION[TestFixtures::SESSION_KEY]), "the default key shouldn't be used");
    assert($client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state))->getSub() !== "");
};

$smoke->run("GoogleSsoClient keeps the token under a NativeSessionCsrfTokenStore's own key", $testNativeStoreWithKey);
