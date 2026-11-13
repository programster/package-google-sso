# Testing

- [Running the tests](#running-the-tests)
- [Testing every supported PHP version](#testing-every-supported-php-version)
- [Running some of the tests](#running-some-of-the-tests)
- [How the tests talk to Google](#how-the-tests-talk-to-google)
- [Writing tests](#writing-tests)

## Running the tests
To run the tests on your current version of PHP, run `composer install`, and then:

```bash
php -d zend.assertions=1 testing/main.php
```

The tests are in `testing/tests/`, with one file per class or feature (e.g. `GoogleSsoClientTest.php`, `PkceTest.php`).
Between them, they log in against a fake Google, and check what every public method returns, and what it throws when
something goes wrong (a cancelled login, a replayed callback, a token issued to another app, Google returning an error,
and so on). A test fails if it throws, fails an `assert()`, or raises a PHP warning or deprecation. `main.php` runs
every `*Test.php` file in that folder, so a new file is picked up automatically. It exits with a non-zero code if
anything fails.

The checks use `assert()`, so `zend.assertions` must be `1`. `main.php` refuses to run otherwise, rather than passing
without checking anything.

## Testing every supported PHP version
This package supports PHP 8.2 and above. To check it works on every supported version, run the following, which
uses Docker to test each version in its own container. Use this before releasing, as it catches code that only
works on newer versions of PHP than the minimum.

```bash
testing/run-all-php-versions.sh             # test every supported version (8.2, 8.3, 8.4 and 8.5)
testing/run-all-php-versions.sh 8.2 8.5     # test only specific versions
testing/run-all-php-versions.sh --rebuild   # rebuild the Docker images first, to get the latest PHP patch releases
```

For each version, this lints every file and runs the tests. It exits with a non-zero code if anything fails. The
Docker images are built the first time they are needed, and then reused. When a new version of PHP is released, add
it to `SUPPORTED_VERSIONS` at the top of the script.

The containers use the `vendor/` directory from `composer install`. `composer.json` sets Composer's platform to PHP
8.2, so the development dependencies it installs work on every supported version, whichever version you run Composer
with.

## Running some of the tests
Set `GOOGLESSO_TEST_ONLY` to run only the tests whose name contains it. It fails if no test matches, so a typo can't
pass without checking anything:

```bash
GOOGLESSO_TEST_ONLY="leeway" php -d zend.assertions=1 testing/main.php
```

## How the tests talk to Google
They don't. Nothing is sent over the network, and no Google credentials are needed:

- `TestSigningKey` is an RSA key that plays the part of Google's. It signs real RS256 ID tokens, and provides the JWK
  set for it.
- `FakeGoogleHttpClient` is a PSR-18 client that plays the part of Google's token and certificate endpoints. It
  responds with an ID token signed by that key (or whatever response a test gives it, e.g. an `invalid_grant` error),
  counts the requests it gets, and throws for a request to any other URL.
- `TestFixtures` creates clients that use it, and provides the claims of a valid ID token, so a test only has to say
  what it changes, e.g. `TestFixtures::validClaims(['aud' => 'another-app'])`.

As the tests use the same `firebase/php-jwt` code to check the signatures as a real login does, they check the real
verification, rather than a mock of it.

## Writing tests
Add a test to the file for its class in `testing/tests/` with `$smoke->run("Name of the test", $callback)`, or create a
new `*Test.php` file for a new class. `main.php` gives each file `$smoke`, and gives each test an empty `$_SESSION` and
`$_GET`. Use `assert()` for each check, and `SmokeTest::expectException()` to check that something throws. It returns
the exception, so it can be checked further:

```php
$callbackAfterCancelling = function () use ($client, $state) : void {
    $client->handleGoogleSsoCallback(['error' => 'access_denied', 'state' => $state]);
};

$exception = SmokeTest::expectException(ExceptionGoogleLoginError::class, $callbackAfterCancelling);
assert($exception instanceof ExceptionGoogleLoginError && $exception->isAccessDenied());
```

Follow the coding standards used throughout `src/` in the tests too, e.g. no arrow functions, and closures assigned to
a descriptively named variable before being passed to `run()` or `expectException()`.
