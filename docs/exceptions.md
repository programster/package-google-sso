# Exceptions

- [Catching them](#catching-them)
- [The package's exceptions](#the-packages-exceptions)
- [Exceptions from elsewhere](#exceptions-from-elsewhere)
- [What to tell the user](#what-to-tell-the-user)

## Catching them
Methods throw an exception when something goes wrong, rather than returning `false` or `null`. The package's own
exceptions are in the `Programster\GoogleSso\Exceptions` namespace, and all extend the abstract `ExceptionGoogleSso`
(which extends `\Exception`), so you can catch them all at once. Exceptions from the libraries the package uses, such
as php-jwt's `ExpiredException` or your HTTP client's, are passed on as they are, so they don't extend it (see
[Exceptions from elsewhere](#exceptions-from-elsewhere)).

Most apps only need to treat one exception specially, the user cancelling, and log the rest:

```php
use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;

try
{
    $googleUser = $googleSso->handleGoogleSsoCallback($_GET);
}
catch (ExceptionGoogleLoginError $e)
{
    // The user cancelled, or Google wouldn't log them in. Nothing is wrong with your site.
    $error = $e->isAccessDenied() ? "You cancelled the login." : "Google couldn't log you in.";
}
catch (\Exception $e)
{
    // Anything else means something is wrong, or someone is tampering with the login.
    $logger->warning("Google login failed", ['exception' => $e]);
    $error = "Sorry, we couldn't log you in with Google. Please try again.";
}
```

## The package's exceptions
All of these extend `ExceptionGoogleSso`, which is never thrown itself. Those indented also extend the one above them,
so catching that one catches them too.

| Exception | Thrown when | Extra methods |
|---|---|---|
| `ExceptionGoogleLoginError` | Google sent the user back with an `error` instead of a code, e.g. `access_denied` when they cancel. | `getError()`, `isAccessDenied()`, `isInteractionRequired()` |
| `ExceptionMissingCallbackParameter` | The callback has no `code` or `state` (or it isn't a string), e.g. someone went to the callback URL directly. | `getParameterName()` |
| `ExceptionCsrfTokenMismatch` | The `state` doesn't match the CSRF token in the session. | `getGoogleResponseToken()`, `getCachedStateToken()` |
| &nbsp;&nbsp;&nbsp;&nbsp;`ExceptionMissingCsrfToken` | There's no CSRF token in the session: it wasn't started, has expired, the callback was already handled (e.g. the page was refreshed), or the cookie wasn't sent. | |
| `ExceptionUnexpectedResponse` | Google's token or certificates endpoint returned an error, or something that isn't the JSON expected. The message includes the HTTP status and Google's error, e.g. `invalid_grant`. | `getResponse()` |
| `ExceptionInvalidIdToken` | The ID token wasn't issued by Google, was issued to a different client, or is missing a claim Google always includes. | |

The constructor also throws `\InvalidArgumentException` if `jwtLeewaySeconds` is negative.

## Exceptions from elsewhere
Some exceptions come from the libraries the package uses, and are passed on as they are:

| Exception | Thrown when |
|---|---|
| `\Firebase\JWT\SignatureInvalidException` | The ID token's signature doesn't match any of Google's keys. |
| `\Firebase\JWT\ExpiredException` | The ID token has expired. |
| `\Firebase\JWT\BeforeValidException` | The ID token says it was issued in the future, usually because your server's clock is wrong (see `jwtLeewaySeconds` in [Configuration](configuration.md#jwtleewayseconds)). |
| `\UnexpectedValueException` | The ID token is malformed, or signed with a key that isn't in Google's certificates, even after fetching them again (the three above extend it too). |
| `\DomainException` | The ID token or certificates can't be decoded, e.g. an unsupported algorithm. |
| `\Psr\Http\Client\ClientExceptionInterface` | The HTTP client couldn't reach Google at all, e.g. a DNS failure or timeout. |
| `\Psr\Cache\InvalidArgumentException` | The cache pool doesn't accept the cache key (only if you changed it). |
| `\Random\RandomException` | `createGoogleSsoLoginUrl()` couldn't get random bytes for the CSRF token, which only happens if the system has no source of randomness. |

## What to tell the user

| What happened | Exceptions | Suggested message |
|---|---|---|
| They cancelled | `ExceptionGoogleLoginError` with `isAccessDenied()` | "You cancelled the login." Show the login page again. |
| They refreshed the callback page, or came back to it | `ExceptionMissingCsrfToken`, `ExceptionUnexpectedResponse` (`invalid_grant`) | "Your login has expired. Please try again." If they're already logged in, just send them on. |
| Their session expired while they were at Google | `ExceptionMissingCsrfToken` | "Your login has expired. Please try again." |
| Google is unreachable or returned an error | `ClientExceptionInterface`, `ExceptionUnexpectedResponse` | "We couldn't reach Google. Please try again in a few minutes." |
| Anything else | The rest | "Sorry, we couldn't log you in with Google." Log it, as it may be a misconfiguration or someone tampering. |

Don't show the exception's own message to the user. Some include details that should stay on your server, such as the
CSRF token that was expected.
