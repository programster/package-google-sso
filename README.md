Google SSO Package
====================

A package to make it easy to use Google SSO (OAuth 2 / OIDC), without minimal bloat and lots of 
flexibility.

This package makes use of the PSR-17 and PSR-18 interfaces, so this package should be able to work
with any existing mechanism you use to send messages. If you are not sure what this means, then
we would recommend that you just install the `guzzlehttp/guzzle` package, and follow the example
in the README. If you cannot use Guzzle for whatever reason, then `slim/psr7` is a good alternative.

Please be aware that this package requires the use of the `$_SESSION` superglobal


## Usage

### Installation
Install this in your codebase with composer like so:

```bash
composer require programster/google-sso
```

### Example Code
There is an[example codebase on GitHub](https://github.com/programster/Google-SSO-Demo/tree/using-programster-google-sso-package) 
that demonstrates using this package. Also, the following code snippet creates a Google SSO client 
and sends the user to Google to login.

```php
<?php

require_once(__DIR__ . "/../vendor/autoload.php");

$googleSso = new GoogleSsoClient(
    $myGoogleClientId,
    $myGoogleClientSecret,
    $myGoogleSsoCallbackUrl = "https://localhost",
    new \GuzzleHttp\Psr7\HttpFactory(),
    new \GuzzleHttp\Client(),
);

if (isset($_GET['code']))
{
    // we are likely handling a user redirecting back here from having logged in with google.
    $userData = $googleSso->handleGoogleSsoLogin();
    print "Hello {$userData->getFullName()} with email address {$userData->getEmail()}";
}
else
{
    $googleSso->sendUserToGoogleLogin();
}
```

