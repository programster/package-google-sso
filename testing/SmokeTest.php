<?php

/*
 * A small test runner for the smoke tests in testing/tests/. Each test is a named callback, which
 * fails if it throws (including a failed assert()) or raises a warning, notice or deprecation.
 */

declare(strict_types = 1);

namespace Programster\GoogleSso\Testing;


final class SmokeTest
{
    private int $m_numPassed = 0;
    private array $m_failures = [];
    private array $m_warnings = [];


    public function __construct()
    {
        error_reporting(-1);

        $recordWarning = function (int $errorNumber, string $message, string $file, int $line) : bool {
            # Respect the @ operator, in case a dependency deliberately uses it.
            if (error_reporting() & $errorNumber)
            {
                $this->m_warnings[] = "[E{$errorNumber}] {$message} @ " . basename($file) . ":{$line}";
            }

            return true;
        };

        set_error_handler($recordWarning);
    }


    /**
     * Run one test, recording it as failed if it throws, or raises any warnings/deprecations. Each test starts with
     * an empty $_SESSION and $_GET, as the client reads and writes them.
     *
     * @param string $label - the name of the test, shown if it fails.
     *
     * @param callable $test - the test to run.
     *
     * @return void
     */
    public function run(string $label, callable $test) : void
    {
        # GOOGLESSO_TEST_ONLY runs only the tests whose name contains it.
        $only = getenv("GOOGLESSO_TEST_ONLY");
        $isSelected = ($only === false || $only === "" || str_contains($label, $only));

        if ($isSelected)
        {
            $numWarningsBefore = count($this->m_warnings);
            $error = "";
            $_SESSION = [];
            $_GET = [];

            try
            {
                $test();
            }
            catch (\Throwable $e)
            {
                $location = basename($e->getFile()) . ":" . $e->getLine();
                $error = get_class($e) . ": " . $e->getMessage() . " @ {$location}";
            }

            $newWarnings = array_slice($this->m_warnings, $numWarningsBefore);

            if ($error === "" && count($newWarnings) === 0)
            {
                $this->m_numPassed++;
            }
            else
            {
                $this->m_failures[] = trim("{$label}: {$error} " . implode(" | ", $newWarnings));
            }
        }
    }


    /**
     * Assert that a callback throws a specific type of exception, and get it, so the test can check it further.
     *
     * @param string $exceptionClass - the class of exception that should be thrown.
     *
     * @param callable $callback - the code that should throw it.
     *
     * @param string $messageContains - optionally, text the exception's message must contain, for when the same class
     * of exception can be thrown for different reasons.
     *
     * @return \Throwable - the exception that was thrown, which is always an instance of $exceptionClass.
     *
     * @throws \Throwable - if the callback throws a different exception, it is rethrown.
     * @throws \Exception - if the callback doesn't throw at all, or the message doesn't contain $messageContains.
     */
    public static function expectException(
        string $exceptionClass,
        callable $callback,
        string $messageContains = ""
    ) : \Throwable
    {
        $caught = null;

        try
        {
            $callback();
        }
        catch (\Throwable $e)
        {
            $caught = $e;
        }

        if ($caught === null)
        {
            throw new \Exception("Expected {$exceptionClass} to be thrown, but nothing was thrown.");
        }
        elseif (!($caught instanceof $exceptionClass))
        {
            throw $caught;
        }
        elseif (!str_contains($caught->getMessage(), $messageContains))
        {
            $message = $caught->getMessage();
            $expected = "Expected the {$exceptionClass} message to contain \"{$messageContains}\"";
            throw new \Exception("{$expected}, but it was: {$message}");
        }

        return $caught;
    }


    # Accessors
    public function getNumPassed() : int { return $this->m_numPassed; }
    public function getFailures() : array { return $this->m_failures; }
}
