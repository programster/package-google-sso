<?php

/*
 * Smoke tests for MfaPolicyEnum: asking Google how the user signed in, and requiring multi-factor authentication.
 * These are run by testing/main.php, which provides $smoke, and gives each test an empty $_SESSION and $_GET.
 */

declare(strict_types = 1);

use Programster\GoogleSso\Exceptions\ExceptionMfaRequired;
use Programster\GoogleSso\MfaPolicyEnum;
use Programster\GoogleSso\Testing\FakeGoogleHttpClient;
use Programster\GoogleSso\Testing\SmokeTest;
use Programster\GoogleSso\Testing\TestFixtures;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */

$getLoginUrlParameters = function (string $loginUrl) : array {
    parse_str(parse_url($loginUrl, PHP_URL_QUERY), $query);
    return $query;
};

$testClaimsRequested = function () use ($getLoginUrlParameters) : void {
    $fakeGoogle = FakeGoogleHttpClient::issuingIdToken(TestFixtures::validClaims());
    $expectedClaims = ['id_token' => ['amr' => ['essential' => true], 'auth_time' => ['essential' => true]]];

    foreach ([MfaPolicyEnum::REPORT, MfaPolicyEnum::REQUIRE] as $policy)
    {
        $client = TestFixtures::createClientWithMfaPolicy($fakeGoogle, $policy);
        $query = $getLoginUrlParameters($client->createGoogleSsoLoginUrl());
        $claims = json_decode($query['claims'] ?? "", true);
        assert($claims === $expectedClaims, "{$policy->name} should ask for amr and auth_time");
    }

    $client = TestFixtures::createClientWithMfaPolicy($fakeGoogle, MfaPolicyEnum::IGNORE);
    assert(!isset($getLoginUrlParameters($client->createGoogleSsoLoginUrl())['claims']), "IGNORE shouldn't ask");
    $defaultQuery = $getLoginUrlParameters(TestFixtures::createClient($fakeGoogle)->createGoogleSsoLoginUrl());
    assert(!isset($defaultQuery['claims']), "IGNORE should be the default");
};

$smoke->run("the login URL asks for amr and auth_time unless the MFA policy is IGNORE", $testClaimsRequested);

$testRequireRefuses = function () : void {
    $claimsWithoutMfa = [
        "no amr" => TestFixtures::validClaims(),
        "password" => TestFixtures::validClaims(['amr' => ["pwd"]]),
        "password and text message" => TestFixtures::validClaims(['amr' => ["pwd", "sms"]]),
    ];

    foreach ($claimsWithoutMfa as $description => $claims)
    {
        $fakeGoogle = FakeGoogleHttpClient::issuingIdToken($claims);
        $client = TestFixtures::createClientWithMfaPolicy($fakeGoogle, MfaPolicyEnum::REQUIRE);
        $state = TestFixtures::startLogin($client);

        $handleCallback = function () use ($client, $state) : void {
            $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state));
        };

        $exception = SmokeTest::expectException(ExceptionMfaRequired::class, $handleCallback, "multi-factor");
        assert($exception->getAuthenticationMethods() === ($claims['amr'] ?? []), "{$description}: wrong methods");
    }
};

$smoke->run("MfaPolicyEnum::REQUIRE refuses users without MFA, or without an amr claim", $testRequireRefuses);

$testRequireAccepts = function () : void {
    foreach (["mfa", "hwk", "swk"] as $method)
    {
        $claims = TestFixtures::validClaims(['amr' => ["pwd", $method], 'auth_time' => time() - 60]);
        $fakeGoogle = FakeGoogleHttpClient::issuingIdToken($claims);
        $client = TestFixtures::createClientWithMfaPolicy($fakeGoogle, MfaPolicyEnum::REQUIRE);
        $state = TestFixtures::startLogin($client);
        $googleUser = $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state));
        assert($googleUser->hasMultiFactorAuthentication(), "{$method} should be let in");
    }
};

$smoke->run("MfaPolicyEnum::REQUIRE lets in users with mfa, hwk or swk", $testRequireAccepts);

$testReportRefusesNobody = function () : void {
    foreach ([MfaPolicyEnum::REPORT, MfaPolicyEnum::IGNORE] as $policy)
    {
        $claims = TestFixtures::validClaims(['amr' => ["pwd"]]);
        $client = TestFixtures::createClientWithMfaPolicy(FakeGoogleHttpClient::issuingIdToken($claims), $policy);
        $state = TestFixtures::startLogin($client);
        $googleUser = $client->handleGoogleSsoCallback(TestFixtures::callbackParameters($state));
        assert($googleUser->getAuthenticationMethods() === ["pwd"], "{$policy->name} should still pass on amr");
    }
};

$smoke->run("MfaPolicyEnum::REPORT and IGNORE refuse nobody", $testReportRefusesNobody);
