<?php

/*
 * Smoke tests for CacheConfig. These are run by testing/main.php, which provides $smoke. How the client uses the cache
 * is tested in GoogleSsoClientTest.php.
 */

declare(strict_types = 1);

use Programster\GoogleSso\CacheConfig;
use Programster\GoogleSso\Testing\SmokeTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */

$testDefaults = function () : void {
    $cachePool = new ArrayAdapter();
    $config = new CacheConfig($cachePool);
    assert($config->getCacheItemPool() === $cachePool && $config->getCacheKey() === "jwtCerts");

    # Google's certificates response allows them to be cached for around 7 hours, so the default must be less.
    assert($config->getJwtCachePeriod() === 6 * 3600, "the default cache period should be 6 hours");
};

$smoke->run("CacheConfig defaults to caching the certificates for 6 hours", $testDefaults);

$testOptions = function () : void {
    $config = new CacheConfig(new ArrayAdapter(), jwtCachePeriod: 3600, cacheKey: "myCerts");
    assert($config->getJwtCachePeriod() === 3600 && $config->getCacheKey() === "myCerts");
};

$smoke->run("CacheConfig keeps the options it is given", $testOptions);

$testBadOptions = function () : void {
    foreach ([0, -1] as $period)
    {
        $createWithBadPeriod = function () use ($period) : void {
            new CacheConfig(new ArrayAdapter(), jwtCachePeriod: $period);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $createWithBadPeriod, "at least 1 second");
    }

    foreach (["", "jwt:certs", "jwt/certs", "{jwtCerts}", "jwt@certs"] as $key)
    {
        $createWithBadKey = function () use ($key) : void {
            new CacheConfig(new ArrayAdapter(), cacheKey: $key);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $createWithBadKey, "cache key");
    }
};

$smoke->run("CacheConfig refuses a period that would never cache, and keys PSR-6 doesn't allow", $testBadOptions);
