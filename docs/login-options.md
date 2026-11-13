# Login options

- [Choosing what Google shows (prompt)](#choosing-what-google-shows-prompt)
- [Suggesting a Workspace domain (hd)](#suggesting-a-workspace-domain-hd)
- [Suggesting an account (login_hint)](#suggesting-an-account-login_hint)
- [Logging in without the user noticing](#logging-in-without-the-user-noticing)

`createGoogleSsoLoginUrl()` and `sendUserToGoogleLogin()` take three optional settings, which change what Google shows
the user. Each is for that one login URL, so different links (or users) can use different settings. Named arguments
make it clear which is which:

```php
use Programster\GoogleSso\LoginPromptEnum;

$loginUrl = $googleSso->createGoogleSsoLoginUrl(
    prompt: LoginPromptEnum::SELECT_ACCOUNT,
    hostedDomainHint: "example.com",
    loginHint: "ada@example.com",
);
```

None of them change what is checked when the user comes back. They only change what the user sees at Google.

## Choosing what Google shows (prompt)
By default, Google decides: if the user is logged in to one Google account, and has agreed to share their details
with your app before, it sends them straight back without showing anything. `prompt` changes that:

| `LoginPromptEnum` | Google |
|---|---|
| `DEFAULT` | Decides for itself. This is the default. |
| `SELECT_ACCOUNT` | Always asks which Google account to use, even if the user is only logged in to one. |
| `CONSENT` | Always shows the consent screen, even if the user has agreed before. |
| `SELECT_ACCOUNT_AND_CONSENT` | Asks which account to use, and then shows the consent screen. |
| `NONE` | Shows nothing at all, and sends the user back with an error if it would need to. See [below](#logging-in-without-the-user-noticing). |

`SELECT_ACCOUNT` is the one most sites want. People are often logged in to several Google accounts (e.g. personal and
work), and without it, Google may log them in with the wrong one without asking. It is also what lets someone who just
logged out of your site log back in as someone else, as otherwise Google would quietly log them straight back in to the
same account:

```php
$loginUrl = $googleSso->createGoogleSsoLoginUrl(prompt: LoginPromptEnum::SELECT_ACCOUNT);
```

## Suggesting a Workspace domain (hd)
`hostedDomainHint` tells Google which Google Workspace organisation the user probably belongs to, so it offers that
organisation's accounts first, and skips the others. Use `"*"` to prefer any Workspace account over personal ones:

```php
$loginUrl = $googleSso->createGoogleSsoLoginUrl(hostedDomainHint: "example.com");
```

**This is only a hint.** The user can still choose any account, and as Google says, the login URL can be changed by
anyone, so it can't control who gets in. To only let in your Workspace's users, also check `getHostedDomain()` when they
come back (see [Security](security.md#only-letting-in-your-workspace)).

It must be a bare domain, such as `"example.com"`, `"*"`, or `""` (the default) for no hint. Anything else, such as
`"@example.com"`, `"https://example.com"` or an email address, throws an `\InvalidArgumentException`, rather than
being sent to Google and quietly doing nothing.

## Suggesting an account (login_hint)
`loginHint` suggests which account to log in with, as either an email address or a Google ID (`getSub()`). Google
fills in the email address on its login form, or picks that account if the user is already logged in to it. It suits
someone logging in again, e.g. when their session on your site has expired:

```php
$loginUrl = $googleSso->createGoogleSsoLoginUrl(loginHint: $user->getGoogleId());
```

Like the domain hint, it's only a suggestion, and the user can still choose another account, so always find the user
by what comes back from the callback, never by the hint.

## Logging in without the user noticing
With `LoginPromptEnum::NONE`, Google shows nothing at all. If the user is logged in to Google, and has agreed to your
app before, they come straight back logged in. Otherwise, rather than showing them anything, Google sends them back
with an error, which `handleGoogleSsoCallback()` throws as an `ExceptionGoogleLoginError` whose
`isInteractionRequired()` is true. Then send them to log in normally:

```php
use Programster\GoogleSso\Exceptions\ExceptionGoogleLoginError;

try
{
    $googleUser = $googleSso->handleGoogleSsoCallback($_GET);
}
catch (ExceptionGoogleLoginError $e)
{
    if ($e->isInteractionRequired())
    {
        // They couldn't be logged in without seeing anything, so send them to log in normally.
        $googleSso->sendUserToGoogleLogin();
    }

    throw $e;
}
```

The errors this covers are `login_required`, `consent_required`, `interaction_required` and
`account_selection_required`, which are the ones OpenID Connect defines for this.
