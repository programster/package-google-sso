<?php

/*
 * Smoke tests for GoogleUserData. These are run by testing/main.php, which provides $smoke.
 */

declare(strict_types = 1);

use Programster\GoogleSso\Exceptions\ExceptionInvalidIdToken;
use Programster\GoogleSso\GoogleUserData;
use Programster\GoogleSso\Testing\SmokeTest;
use Programster\GoogleSso\Testing\TestFixtures;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */

$testReadsEveryClaim = function () : void {
    $extraClaims = [
        'hd' => 'example.com',
        'at_hash' => 'HK6E_P6Dh8Y93mRNtsDB1Q',
        'name' => 'Ada Lovelace',
        'given_name' => 'Ada',
        'family_name' => 'Lovelace',
        'picture' => 'https://lh3.googleusercontent.com/a/test',
    ];

    $claims = TestFixtures::validClaims($extraClaims);
    $user = GoogleUserData::createFromJwt((object)$claims);
    assert($user->getIss() === 'https://accounts.google.com');
    assert($user->getAzp() === TestFixtures::CLIENT_ID && $user->getAud() === TestFixtures::CLIENT_ID);
    assert($user->getSub() === $claims['sub'] && $user->getEmail() === $claims['email']);
    assert($user->getEmailVerified() === true && $user->getHostedDomain() === 'example.com');
    assert($user->getAtHash() === 'HK6E_P6Dh8Y93mRNtsDB1Q' && $user->getFullName() === 'Ada Lovelace');
    assert($user->getFirstName() === 'Ada' && $user->getLastName() === 'Lovelace');
    assert($user->getPictureUrl() === 'https://lh3.googleusercontent.com/a/test');
    assert($user->getIssuedAt() === $claims['iat'] && $user->getExpiry() === $claims['exp']);
};

$smoke->run("createFromJwt reads every claim", $testReadsEveryClaim);

$testOptionalClaimsDefault = function () : void {
    $claims = TestFixtures::validClaims();
    unset($claims['azp']);
    $user = GoogleUserData::createFromJwt((object)$claims);

    $optionalValues = [
        $user->getAzp(),
        $user->getHostedDomain(),
        $user->getAtHash(),
        $user->getFullName(),
        $user->getFirstName(),
        $user->getLastName(),
        $user->getPictureUrl(),
    ];

    assert($optionalValues === ['', '', '', '', '', '', ''], "optional claims should default to empty strings");
};

$smoke->run("createFromJwt defaults missing optional claims to empty strings", $testOptionalClaimsDefault);

$testEmailVerifiedForms = function () : void {
    $verifiedAsString = GoogleUserData::createFromJwt((object)TestFixtures::validClaims(['email_verified' => 'true']));
    assert($verifiedAsString->getEmailVerified() === true, "the string \"true\" should count as verified");
    $unverified = GoogleUserData::createFromJwt((object)TestFixtures::validClaims(['email_verified' => false]));
    assert($unverified->getEmailVerified() === false);
    $claimsWithoutEmailVerified = TestFixtures::validClaims();
    unset($claimsWithoutEmailVerified['email_verified']);
    $missing = GoogleUserData::createFromJwt((object)$claimsWithoutEmailVerified);
    assert($missing->getEmailVerified() === false, "a missing email_verified should count as unverified");
};

$smoke->run("createFromJwt reads email_verified safely", $testEmailVerifiedForms);

$testMissingRequiredClaims = function () : void {
    foreach (['iss', 'aud', 'sub', 'email', 'iat', 'exp'] as $requiredClaim)
    {
        $claims = TestFixtures::validClaims();
        unset($claims[$requiredClaim]);

        $createWithoutClaim = function () use ($claims) : void {
            GoogleUserData::createFromJwt((object)$claims);
        };

        SmokeTest::expectException(ExceptionInvalidIdToken::class, $createWithoutClaim, "\"{$requiredClaim}\"");
    }
};

$smoke->run("createFromJwt throws ExceptionInvalidIdToken for a missing required claim", $testMissingRequiredClaims);

$testWrongClaimTypes = function () : void {
    $createWithArrayEmail = function () : void {
        GoogleUserData::createFromJwt((object)TestFixtures::validClaims(['email' => ['a@example.com']]));
    };

    SmokeTest::expectException(ExceptionInvalidIdToken::class, $createWithArrayEmail, "\"email\"");

    $createWithTextExpiry = function () : void {
        GoogleUserData::createFromJwt((object)TestFixtures::validClaims(['exp' => 'tomorrow']));
    };

    SmokeTest::expectException(ExceptionInvalidIdToken::class, $createWithTextExpiry, "\"exp\"");
};

$smoke->run("createFromJwt throws ExceptionInvalidIdToken for a claim of the wrong type", $testWrongClaimTypes);

$testGetClaims = function () : void {
    $extraClaims = ['amr' => ['pwd', 'mfa'], 'nested' => (object)['level' => 2], 'hd' => 'example.com'];
    $claims = TestFixtures::validClaims($extraClaims);
    $user = GoogleUserData::createFromJwt(json_decode(json_encode($claims)));
    $rawClaims = $user->getClaims();
    assert($rawClaims['amr'] === ['pwd', 'mfa'], "claims without a getter should be there too");
    assert($rawClaims['nested'] === ['level' => 2], "claims within claims should be arrays, not objects");
    assert($rawClaims['sub'] === $claims['sub'] && $rawClaims['hd'] === 'example.com');
    assert(array_keys($rawClaims) === array_keys($claims), "every claim should be there, and nothing else");
};

$smoke->run("getClaims gives every claim in the ID token, as plain data", $testGetClaims);
