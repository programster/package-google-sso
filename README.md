Google SSO
==========

[![Tests](https://github.com/programster/package-google-sso/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/programster/package-google-sso/actions/workflows/tests.yml)

## Contents
- [About](#about)
    - [Why not Google's own package?](#why-not-googles-own-package)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Setting up Google](docs/google-setup.md)
- [How it works](docs/how-it-works.md)
- [Configuration](docs/configuration.md)
- [Login options (`prompt`, `hd`, `login_hint`)](docs/login-options.md)
- [The user's details (`GoogleUserData`)](docs/user-data.md)
- [Frameworks and sessions](docs/frameworks.md)
- [Security](docs/security.md)
- [Caching Google's certificates](docs/caching.md)
- [Exceptions](docs/exceptions.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Upgrading](docs/upgrading.md)
- [Testing](docs/testing.md)
- [Design principles](#design-principles)
- [License](#license)

## About
This package adds "Login with Google" to a PHP site. It sends the user to Google to log in, and when Google sends them
back, it checks everything that needs checking and gives you their details: their Google ID, email address, name and
picture.

It uses Google's OpenID Connect (OAuth 2) "authorization code" flow, which is the one Google recommends for sites with
a server, and the one where your client secret never leaves your server. Between the user leaving and coming back, it
checks:

- that the user coming back is the one who left, with a single-use CSRF token (the OAuth `state`),
- that the code Google gives back can only be used for this login, with PKCE,
- that the ID token really came from Google, by checking its signature against Google's published certificates,
- that it was issued to *your* app, rather than to some other app the user logged in to (the `aud` claim),
- that it was issued by Google (the `iss` claim), and hasn't expired, allowing for a little clock difference,
- that Google has verified the user's email address, so it can't be used to get into someone else's account.

Anything that goes wrong, from the user cancelling to Google returning an error, throws a specific exception, so you
can tell the user what happened.

### Why not Google's own package?
Google's [`google/apiclient`](https://github.com/googleapis/google-api-php-client) can do this too, but it is built to
call every one of Google's APIs, from Drive to YouTube, and you get all of them. Installed with Composer:

| | Packages | PHP files | Size of `vendor/` |
|---|---|---|---|
| `google/apiclient` | 16 | 38,212 | 214 MB |
| `programster/google-sso` with Guzzle 8.5 | 12 | 220 | 2.8 MB |
| `programster/google-sso` with Buzz 1.4 and Nyholm PSR-7 1.8 | 12 | 132 | 1.1 MB |

Of those files, the package itself is 18. The rest are the HTTP client and the PSR interfaces. Google's package can be
slimmed down with its `Google\Task\Composer::cleanup` script, but logging users in only needs two HTTP requests and a
signature check, so this package does just that. It also isn't tied to one HTTP client: it uses the PSR-17 and PSR-18
interfaces, so it works with Guzzle, Buzz, Symfony's HttpClient, or whatever your project already has (see
[Choosing an HTTP client](docs/configuration.md#choosing-an-http-client)).

## Requirements
- PHP 8.2 or later (8.2, 8.3, 8.4 and 8.5 are tested).
- A PSR-18 HTTP client and a PSR-17 request factory. If you don't already have one, install `guzzlehttp/guzzle`, which
  provides both.
- Somewhere to keep a CSRF token for each user. By default, that is PHP's native session (`session_start()` and
  `$_SESSION`). For a framework with its own session system, such as Laravel, see
  [Frameworks and sessions](docs/frameworks.md).
- A client ID and secret from Google. See [Setting up Google](docs/google-setup.md), which takes a few minutes.

## Installation
```bash
composer require programster/google-sso
```

If you don't have a PSR-18 HTTP client yet, install Guzzle too:

```bash
composer require guzzlehttp/guzzle
```

## Quick start
This is a complete login, in plain PHP, with three files. Replace the client ID and secret with your own (see
[Setting up Google](docs/google-setup.md)), and register `https://example.com/callback.php` with Google as an
authorised redirect URI.

`google-sso.php` creates the client, for the other two files to use:

```php
<?php

use Programster\GoogleSso\GoogleSsoClient;

require_once(__DIR__ . "/vendor/autoload.php");

session_start();

$googleSso = new GoogleSsoClient(
    clientId: getenv("GOOGLE_CLIENT_ID"),
    clientSecret: getenv("GOOGLE_CLIENT_SECRET"),
    googleCallbackUrl: "https://example.com/callback.php",
    requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
    responseFactory: new \GuzzleHttp\Psr7\HttpFactory(),
    httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
);
```

`login.php` is where your "Login with Google" button goes. It sends the user to Google:

```php
<?php

require_once(__DIR__ . "/google-sso.php");

header("Location: " . $googleSso->createGoogleSsoLoginUrl());
```

`callback.php` is where Google sends them back:

```php
<?php

use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;
use Programster\GoogleSso\Exceptions\ExceptionUnverifiedEmail;

require_once(__DIR__ . "/google-sso.php");

try
{
    $googleUser = $googleSso->handleGoogleSsoCallback($_GET);
}
catch (ExceptionGoogleLoginError $e)
{
    // The user cancelled at Google, or Google refused to log them in.
    header("Location: /?login=cancelled");
    exit;
}
catch (ExceptionUnverifiedEmail $e)
{
    // Google hasn't verified that they own their email address, so it can't be trusted.
    header("Location: /?login=unverified-email");
    exit;
}

// Find the user's account by their Google ID, which never changes, unlike their email address.
$user = $users->findByGoogleId($googleUser->getSub());

if ($user === null)
{
    $user = $users->create($googleUser->getSub(), $googleUser->getEmail(), $googleUser->getFullName());
}

// Give the logged-in user a new session ID, so a session ID from before they logged in can't be used to hijack it.
session_regenerate_id(true);
$_SESSION['userId'] = $user->getId();
header("Location: /");
```

Any other exception means something is wrong (e.g. someone tampering with the callback, or Google being unreachable),
so let your error handling log it. See [Exceptions](docs/exceptions.md) for them all.

From there, the pages in the [contents](#contents) cover the rest, such as only letting in your Google Workspace
([Security](docs/security.md)), always asking which account to use ([Login options](docs/login-options.md)), and using
Slim, Laravel or Symfony ([Frameworks and sessions](docs/frameworks.md)). Every public method also has a docblock, which
your IDE will show.

## Design principles
- **Safe by default.** Everything a login needs checking is checked, without you having to remember to, including
  that Google has verified the user's email address. Anything less safe, such as letting in users whose address isn't
  verified, needs you to ask for it (see [Security](docs/security.md)).
- **Failures throw.** Methods throw a specific exception if something goes wrong, rather than returning `false` or
  `null`, or giving you a PHP warning and a half-filled object. Every exception the package throws extends
  `ExceptionGoogleSso`, so you can catch them all at once.
- **Asks for as little as possible.** Only the `openid`, `email` and `profile` scopes are requested, and no offline
  access, so the user sees the smallest consent screen Google has.
- **Minimal dependencies.** Just the PSR interfaces and `firebase/php-jwt`. You bring your own HTTP client and cache.

## License
MIT. See [LICENSE](LICENSE).
