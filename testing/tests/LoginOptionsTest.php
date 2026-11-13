<?php

/*
 * Smoke tests for the login URL's optional settings: prompt, hd and login_hint. These are run by testing/main.php,
 * which provides $smoke, and gives each test an empty $_SESSION and $_GET.
 */

declare(strict_types = 1);

use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;
use Programster\GoogleSso\LoginPromptEnum;
use Programster\GoogleSso\Testing\FakeGoogleHttpClient;
use Programster\GoogleSso\Testing\SmokeTest;
use Programster\GoogleSso\Testing\TestFixtures;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */

$getQuery = function (string $loginUrl) : array {
    parse_str(parse_url($loginUrl, PHP_URL_QUERY), $query);
    return $query;
};

$testDefaults = function () use ($getQuery) : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $query = $getQuery($client->createGoogleSsoLoginUrl());
    assert(!isset($query['prompt']), "no prompt should be sent by default, so Google decides");
    assert(!isset($query['hd']) && !isset($query['login_hint']), "no hints should be sent by default");
};

$smoke->run("createGoogleSsoLoginUrl adds no optional settings by default", $testDefaults);

$testPrompts = function () use ($getQuery) : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));

    $expectedPrompts = [
        'select_account' => LoginPromptEnum::SELECT_ACCOUNT,
        'consent' => LoginPromptEnum::CONSENT,
        'select_account consent' => LoginPromptEnum::SELECT_ACCOUNT_AND_CONSENT,
        'none' => LoginPromptEnum::NONE,
    ];

    foreach ($expectedPrompts as $expectedPrompt => $prompt)
    {
        $query = $getQuery($client->createGoogleSsoLoginUrl(prompt: $prompt));
        assert($query['prompt'] === $expectedPrompt, "{$prompt->name} should send prompt={$expectedPrompt}");
    }

    # Every case is covered above, so a new one can't be added without a test.
    assert(count(LoginPromptEnum::cases()) === count($expectedPrompts) + 1, "a LoginPromptEnum case isn't tested");
};

$smoke->run("createGoogleSsoLoginUrl sends the prompt it is given", $testPrompts);

$testHints = function () use ($getQuery) : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));
    $query = $getQuery($client->createGoogleSsoLoginUrl(hostedDomainHint: "example.com", loginHint: "ada@example.com"));
    assert($query['hd'] === "example.com" && $query['login_hint'] === "ada@example.com");
    $query = $getQuery($client->createGoogleSsoLoginUrl(hostedDomainHint: "*", loginHint: "110169484474386276334"));
    assert($query['hd'] === "*", "\"*\" should be allowed, for any Google Workspace account");
    assert($query['login_hint'] === "110169484474386276334", "a Google ID should be allowed as the login hint");
    assert($query['state'] === $_SESSION[TestFixtures::SESSION_KEY], "the options shouldn't change the state");
};

$smoke->run("createGoogleSsoLoginUrl sends the hosted domain and login hints it is given", $testHints);

$testBadDomainHints = function () : void {
    $client = TestFixtures::createClient(FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims()));

    $badHints = [
        "@example.com",
        "https://example.com",
        "example.com/",
        "example",
        "ada@example.com",
        " example.com",
        "example.com\n",
    ];

    foreach ($badHints as $bad)
    {
        $createWithBadHint = function () use ($client, $bad) : void {
            $client->createGoogleSsoLoginUrl(hostedDomainHint: $bad);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $createWithBadHint, "hosted domain hint");
    }

    assert(!isset($_SESSION[TestFixtures::SESSION_KEY]), "a refused hint shouldn't leave a CSRF token behind");
};

$smoke->run("createGoogleSsoLoginUrl refuses a hosted domain hint that isn't a domain", $testBadDomainHints);

$testInteractionRequired = function () : void {
    $interactionErrors = ["login_required", "consent_required", "interaction_required", "account_selection_required"];

    foreach ($interactionErrors as $error)
    {
        $exception = new ExceptionGoogleLoginError($error);
        assert($exception->isInteractionRequired() && !$exception->isAccessDenied(), "{$error} needs interaction");
    }

    $cancelled = new ExceptionGoogleLoginError("access_denied");
    assert(!$cancelled->isInteractionRequired() && $cancelled->isAccessDenied());
};

$smoke->run("ExceptionGoogleLoginError::isInteractionRequired() spots prompt=none's errors", $testInteractionRequired);
