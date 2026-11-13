Google SSO
==========

## Contents
- [About](#about)
    - [Why not Google's own package?](#why-not-googles-own-package)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Common tasks](#common-tasks)
    - [Get the user's details](#get-the-users-details)
    - [Match users to accounts safely](#match-users-to-accounts-safely)
    - [Only allow your Google Workspace domain](#only-allow-your-google-workspace-domain)
    - [Handle a cancelled login](#handle-a-cancelled-login)
    - [Always ask which Google account to use](#always-ask-which-google-account-to-use)
    - [Use it with Slim or another PSR-7 framework](#use-it-with-slim-or-another-psr-7-framework)
    - [Cache Google's certificates](#cache-googles-certificates)
    - [Put several "Login with Google" buttons on a page](#put-several-login-with-google-buttons-on-a-page)
- [Documentation](#documentation)
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
- that it was issued by Google (the `iss` claim), and hasn't expired, allowing for a little clock difference.

Anything that goes wrong, from the user cancelling to Google returning an error, throws a specific exception, so you
can tell the user what happened.

### Why not Google's own package?
Google's [`google/apiclient`](https://github.com/googleapis/google-api-php-client) can do this too, but it is built to
call every one of Google's APIs, from Drive to YouTube, and you get all of them. Installed with Composer:

| | Packages | PHP files | Size of `vendor/` |
|---|---|---|---|
| `google/apiclient` | 16 | 38,212 | 214 MB |
| `programster/google-sso` (with Guzzle) | 12 | 384 | 4.2 MB |

Google's package can be slimmed down with its `Google\Task\Composer::cleanup` script, but logging users in only needs
two HTTP requests and a signature check, so this package does just that. It also isn't tied to one HTTP client: it uses
the PSR-17 and PSR-18 interfaces, so it works with Guzzle, Symfony's HttpClient, or whatever your project already has.

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
    httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
);
```

`login.php` is where your "Login with Google" button goes. It sends the user to Google:

```php
<?php

require_once(__DIR__ . "/google-sso.php");

$googleSso->sendUserToGoogleLogin();
```

`callback.php` is where Google sends them back:

```php
<?php

use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;

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

## Common tasks

### Get the user's details
```php
$googleUser = $googleSso->handleGoogleSsoCallback($_GET);

$googleUser->getSub();           // "110169484474386276334" - their Google ID, which never changes
$googleUser->getEmail();         // "ada@example.com"
$googleUser->getEmailVerified(); // true, if Google has verified they own that address
$googleUser->getFullName();      // "Ada Lovelace"
$googleUser->getFirstName();     // "Ada"
$googleUser->getLastName();      // "Lovelace"
$googleUser->getPictureUrl();    // "https://lh3.googleusercontent.com/a/..."
$googleUser->getHostedDomain();  // "example.com", for a Google Workspace account, or "" for a personal one
$googleUser->getClaims();        // every claim in the ID token, as an array, including any without a getter
```

Google leaves out the name and picture for some accounts, in which case they are empty strings. See
[The user's details](docs/user-data.md) for everything that's there.

### Match users to accounts safely
Link accounts by `getSub()`, Google's permanent ID for the user. If you also find accounts by email address (e.g. to
link Google to an account someone already has), check that Google has verified the address first. Otherwise, someone
could log in to another person's account with a Google account using an address they don't own:

```php
if (!$googleUser->getEmailVerified())
{
    throw new Exception("Please verify your email address with Google, and then try again.");
}

$user = $users->findByEmail($googleUser->getEmail());
```

See [Security](docs/security.md) for more.

### Only allow your Google Workspace domain
Check the hosted domain, rather than the end of the email address, as only the hosted domain shows that the account is
managed by your Workspace:

```php
if ($googleUser->getHostedDomain() !== "example.com")
{
    throw new Exception("Please log in with your example.com account.");
}
```

To make it easier for them, you can also ask Google to offer your Workspace's accounts first, with
`createGoogleSsoLoginUrl(hostedDomainHint: "example.com")`, but that is only a hint, so keep the check (see
[Login options](docs/login-options.md#suggesting-a-workspace-domain-hd)). If your Google Cloud project belongs to your
Workspace, you can also make the app "Internal" in Google's settings, so that Google itself refuses anyone else. See
[Setting up Google](docs/google-setup.md#internal-or-external).

### Handle a cancelled login
If the user cancels at Google, or declines to share their details, `handleGoogleSsoCallback()` throws
`ExceptionGoogleLoginError`:

```php
try
{
    $googleUser = $googleSso->handleGoogleSsoCallback($_GET);
}
catch (ExceptionGoogleLoginError $e)
{
    $message = $e->isAccessDenied() ? "You cancelled the login." : "Google couldn't log you in ({$e->getError()}).";
}
```

### Always ask which Google account to use
People are often logged in to several Google accounts, and by default, Google may pick one without asking. To always
ask which account to use:

```php
use Programster\GoogleSso\LoginPromptEnum;

$loginUrl = $googleSso->createGoogleSsoLoginUrl(prompt: LoginPromptEnum::SELECT_ACCOUNT);
```

`sendUserToGoogleLogin()` takes the same options. See [Login options](docs/login-options.md) for the others.

### Use it with Slim or another PSR-7 framework
`sendUserToGoogleLogin()` sends a header and exits, so in a framework where you return a response, redirect to
`createGoogleSsoLoginUrl()` yourself, and pass the request's query parameters to `handleGoogleSsoCallback()`:

```php
$sendToGoogle = function (ServerRequestInterface $request, ResponseInterface $response) use ($googleSso) {
    return $response->withHeader('Location', $googleSso->createGoogleSsoLoginUrl())->withStatus(302);
};

$handleGoogleCallback = function (ServerRequestInterface $request, ResponseInterface $response) use ($googleSso) {
    $googleUser = $googleSso->handleGoogleSsoCallback($request->getQueryParams());
    // ... log the user in
    return $response->withHeader('Location', '/')->withStatus(302);
};

$app->get('/login', $sendToGoogle);
$app->get('/login/callback', $handleGoogleCallback);
```

For Laravel, Symfony and long-running servers, which have their own sessions, give the client a CSRF token store that
uses them. See [Frameworks and sessions](docs/frameworks.md), which has stores for Laravel and Symfony.

### Cache Google's certificates
By default, Google's certificates are fetched on every login. To cache them, pass any PSR-6 cache pool:

```php
$googleSso = new GoogleSsoClient(
    clientId: getenv("GOOGLE_CLIENT_ID"),
    clientSecret: getenv("GOOGLE_CLIENT_SECRET"),
    googleCallbackUrl: "https://example.com/callback.php",
    requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
    httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
    cacheConfig: new \Programster\GoogleSso\CacheConfig($cachePool),
);
```

See [Caching Google's certificates](docs/caching.md) for how long to cache them for.

### Put several "Login with Google" buttons on a page
Call `createGoogleSsoLoginUrl()` for each one. Within a session, every call returns the same URL, so all the buttons
work, whichever the user clicks:

```php
<a href="<?= htmlspecialchars($googleSso->createGoogleSsoLoginUrl()) ?>">Login with Google</a>
```

## Documentation
The [`docs`](docs/) folder has a page for each area, with examples, and the details that are easy to be caught out by.
Every public method also has a docblock, which your IDE will show, saying what it does, what each parameter means, and
the exceptions it can throw.

- [Setting up Google](docs/google-setup.md): creating the client ID and secret, and the consent screen.
- [How it works](docs/how-it-works.md): what happens between the user clicking "Login with Google" and you getting their
  details, and what is checked along the way.
- [Configuration](docs/configuration.md): every constructor option.
- [Login options](docs/login-options.md): always asking which account to use, and suggesting a domain or account.
- [The user's details](docs/user-data.md): everything in `GoogleUserData`, and which details can be empty.
- [Frameworks and sessions](docs/frameworks.md): Slim, Laravel, Symfony, and long-running servers.
- [Security](docs/security.md): matching users to accounts, sessions, and what this package does and doesn't do for you.
- [Caching Google's certificates](docs/caching.md)
- [Exceptions](docs/exceptions.md): what each one means, and what to tell the user.
- [Troubleshooting](docs/troubleshooting.md): `redirect_uri_mismatch`, `invalid_grant`, CSRF errors, and others.
- [Upgrading](docs/upgrading.md)
- [Testing](docs/testing.md): running the package's own tests.

## Design principles
- **Safe by default.** Everything a login needs checking is checked, without you having to remember to. The one thing
  left to you is whether an unverified email address is acceptable, as that depends on what you do with it (see
  [Security](docs/security.md)).
- **Failures throw.** Methods throw a specific exception if something goes wrong, rather than returning `false` or
  `null`, or giving you a PHP warning and a half-filled object. Every exception the package throws extends
  `ExceptionGoogleSso`, so you can catch them all at once.
- **Asks for as little as possible.** Only the `openid`, `email` and `profile` scopes are requested, and no offline
  access, so the user sees the smallest consent screen Google has.
- **Minimal dependencies.** Just the PSR interfaces and `firebase/php-jwt`. You bring your own HTTP client and cache.

## License
MIT. See [LICENSE](LICENSE).
