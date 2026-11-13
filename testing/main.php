<?php

/*
 * Runs the smoke tests in testing/tests/, which log in against a fake Google, and check the
 * results and failures of (nearly) every public method in the library. It reports any test that
 * fails, throws an error, or raises a warning/deprecation. There is one file of tests per class,
 * e.g. GoogleSsoClientTest.php. This is used by run-all-php-versions.sh to check the library works
 * on every supported version of PHP, but it can also be run directly: php testing/main.php
 * Exits with 0 if everything passed, or 1 if anything failed.
 */

declare(strict_types = 1);

require_once(__DIR__ . '/../vendor/autoload.php');

use Programster\GoogleSso\Testing\SmokeTest;


# The tests check things with assert(), which does nothing when zend.assertions is 0 or -1, so every test would pass
# without checking anything. It can be switched from 0 to 1 here, but -1 (the usual production setting) can only be
# changed in php.ini or on the command line.
if (ini_get('zend.assertions') !== '1' && @ini_set('zend.assertions', '1') === false)
{
    echo "zend.assertions must be 1 for the tests to check anything. Run: php -d zend.assertions=1 " . __FILE__ . "\n";
    exit(1);
}

ini_set('assert.exception', '1');

$smoke = new SmokeTest();
$testFiles = glob(__DIR__ . '/tests/*Test.php');
sort($testFiles);

foreach ($testFiles as $testFile)
{
    require $testFile;
}

$failures = $smoke->getFailures();

# With GOOGLESSO_TEST_ONLY, a name that matches nothing would otherwise pass without checking anything.
$only = getenv("GOOGLESSO_TEST_ONLY");

if ($only !== false && $only !== "" && $smoke->getNumPassed() === 0 && count($failures) === 0)
{
    $failures[] = "GOOGLESSO_TEST_ONLY: no test's name contains \"{$only}\", so nothing was run.";
}

printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $smoke->getNumPassed(), count($failures));

foreach ($failures as $failure)
{
    echo "  FAIL {$failure}\n";
}

exit(count($failures) === 0 ? 0 : 1);
