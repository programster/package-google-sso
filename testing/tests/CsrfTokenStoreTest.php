<?php

/*
 * Smoke tests for the CSRF token stores, and how GoogleSsoClient uses them. These are run by testing/main.php, which
 * provides $smoke, and gives each test an empty $_SESSION and $_GET.
 */

declare(strict_types = 1);

use Programster\GoogleSso\Exceptions\ExceptionMissingCsrfToken;
use Programster\GoogleSso\NativeSessionCsrfTokenStore;
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

    # Without a session at all, it has no token, rather than raising a warning.
    unset($_SESSION);
    assert($store->getToken() === "");
    $store->removeToken();
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

$testCustomStore = function () : void {
    $store = new InMemoryCsrfTokenStore();
    $client = TestFixtures::createClientWithCsrfOptions(NativeSessionCsrfTokenStore::DEFAULT_KEY, $store);
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

$testDeprecatedSessionKey = function () : void {
    # The deprecated constructor option still works on its own, as it did before stores existed.
    $client = TestFixtures::createClientWithCsrfOptions("legacyKey", new NativeSessionCsrfTokenStore());
    $state = TestFixtures::startLoginWithSessionKey($client, "legacyKey");
    assert($client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state))->getSub() !== "");

    # As does a native store given the same key, which is pointless, but harmless.
    $client = TestFixtures::createClientWithCsrfOptions("legacyKey", new NativeSessionCsrfTokenStore("legacyKey"));
    assert(TestFixtures::startLoginWithSessionKey($client, "legacyKey") !== "");
};

$smoke->run("the deprecated sessionCsrfTokenKey still works", $testDeprecatedSessionKey);

$testConflictingOptions = function () : void {
    $createWithCustomStore = function () : void {
        TestFixtures::createClientWithCsrfOptions("legacyKey", new InMemoryCsrfTokenStore());
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createWithCustomStore, "sessionCsrfTokenKey");

    $createWithOtherKey = function () : void {
        TestFixtures::createClientWithCsrfOptions("legacyKey", new NativeSessionCsrfTokenStore("otherKey"));
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createWithOtherKey, "sessionCsrfTokenKey");
};

$smoke->run("sessionCsrfTokenKey can't be silently ignored by a csrfTokenStore", $testConflictingOptions);
