<?php

/*
 * Smoke tests for CacheConfig. These are run by testing/main.php, which provides $smoke. How the client uses the cache
 * is tested in GoogleSsoClientTest.php.
 */

declare(strict_types = 1);

use Programster\GoogleSso\CacheConfig;
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
