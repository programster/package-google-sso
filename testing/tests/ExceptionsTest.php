<?php

/*
 * Smoke tests for the package's exceptions. These are run by testing/main.php, which provides $smoke. When each one is
 * thrown is tested in the files for the classes that throw them.
 */

declare(strict_types = 1);

use GuzzleHttp\Psr7\Response;
use Programster\GoogleSso\Exceptions\ExceptionCsrfTokenMismatch;
use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;
use Programster\GoogleSso\Exceptions\ExceptionGoogleSso;
use Programster\GoogleSso\Exceptions\ExceptionInvalidIdToken;
use Programster\GoogleSso\Exceptions\ExceptionMfaRequired;
use Programster\GoogleSso\Exceptions\ExceptionMissingCallbackParameter;
use Programster\GoogleSso\Exceptions\ExceptionMissingCsrfToken;
use Programster\GoogleSso\Exceptions\ExceptionUnexpectedResponse;

/** @var \Programster\GoogleSso\Testing\SmokeTest $smoke */

$testCommonBase = function () : void {
    $exceptions = [
        new ExceptionCsrfTokenMismatch("state", "token"),
        new ExceptionMissingCsrfToken("state"),
        new ExceptionGoogleLoginError("access_denied"),
        new ExceptionInvalidIdToken("bad token"),
        new ExceptionMfaRequired(["pwd"]),
        new ExceptionMissingCallbackParameter("code"),
        new ExceptionUnexpectedResponse(new Response(500)),
    ];

    foreach ($exceptions as $exception)
    {
        $class = get_class($exception);
        assert($exception instanceof ExceptionGoogleSso, "{$class} should extend ExceptionGoogleSso");
        assert($exception instanceof \Exception, "{$class} should still extend \\Exception");
    }

    # Every exception class in src/exceptions/ should be covered above, so a new one can't be missed.
    $classFiles = glob(__DIR__ . '/../../src/exceptions/Exception*.php');
    $numConcreteClasses = count($classFiles) - 1;
    assert(count($exceptions) === $numConcreteClasses, "expected {$numConcreteClasses} exceptions to be checked");
    assert((new \ReflectionClass(ExceptionGoogleSso::class))->isAbstract(), "the base should never be thrown itself");
};

$smoke->run("every exception the package throws extends ExceptionGoogleSso", $testCommonBase);
