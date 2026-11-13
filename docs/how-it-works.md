# How it works

- [The login, step by step](#the-login-step-by-step)
- [What is checked](#what-is-checked)
- [What is sent to Google](#what-is-sent-to-google)
- [The CSRF token (state)](#the-csrf-token-state)

## The login, step by step
The package uses OpenID Connect's "authorization code" flow, which is what Google recommends for a site with a server:

```
 Browser                        Your site                               Google
    |                               |                                      |
    |  1. clicks "Login with Google"|                                      |
    |------------------------------>|                                      |
    |                               | createGoogleSsoLoginUrl():           |
    |                               | puts a random token in the session   |
    |  2. redirected to Google, with the token as the "state"              |
    |<------------------------------|                                      |
    |--------------------------------------------------------------------->|
    |                               |        3. logs in, and agrees to     |
    |                               |           share their details        |
    |  4. redirected back to your callback, with ?code=...&state=...       |
    |<---------------------------------------------------------------------|
    |------------------------------>|                                      |
    |                               | handleGoogleSsoCallback():           |
    |                               | 5. checks the state against the      |
    |                               |    session's token, and uses it up   |
    |                               | 6. trades the code for an ID token   |
    |                               |------------------------------------->|
    |                               |<-------------------------------------|
    |                               | 7. fetches Google's certificates     |
    |                               |    (unless they're cached)           |
    |                               |------------------------------------->|
    |                               |<-------------------------------------|
    |                               | 8. checks the ID token, and returns  |
    |                               |    a GoogleUserData                  |
```

1. The user clicks your "Login with Google" link or button.
2. `createGoogleSsoLoginUrl()` (or `createGoogleSsoLoginResponse()`, which redirects to it) makes a random CSRF
   token, keeps it in the user's session (through the CSRF token store), and builds Google's login URL, with the
   token as the `state`.
3. At Google, the user logs in if they aren't already, and agrees to share their name, email address and picture.
   If they have done that before, Google may skip straight to the next step.
4. Google sends the user back to your callback URL, with a one-off `code` and the same `state`. If they cancelled, it
   sends an `error` (e.g. `access_denied`) instead of a code.
5. `handleGoogleSsoCallback()` takes the token out of the session, and checks the `state` matches it.
6. It sends the code, along with your client ID and secret, to Google's token endpoint, which returns an ID token.
   This is a JWT (a signed JSON object) of "claims" about the user.
7. It gets Google's public certificates, from the cache if you have configured one, and it has the key the ID token
   was signed with. Otherwise, it fetches them from Google, which picks up a key Google has started using since they
   were cached.
8. It checks the ID token (see below), and returns its claims as a `GoogleUserData`.

The ID token comes from Google directly, over HTTPS, in step 6, rather than through the browser, so the user can't
change it. It is still checked, as recommended, in case something between your server and Google isn't what it seems.

## What is checked
Before you get a `GoogleUserData`, the package checks:

| Check | Why | Fails with |
|---|---|---|
| The callback isn't an error | The user may have cancelled | `ExceptionGoogleLoginError` |
| The callback has a `state` and `code` | Someone may have gone to the callback URL directly | `ExceptionMissingCallbackParameter` |
| The session has a CSRF token, and the `state` matches it | Stops someone logging the user in as someone else (login CSRF), and stops a callback being used twice | `ExceptionMissingCsrfToken`, `ExceptionCsrfTokenMismatch` |
| Google's token endpoint returned an ID token | The code may have expired, or already been used | `ExceptionUnexpectedResponse` |
| The ID token is signed by one of Google's keys | Stops a made-up token being accepted | `\Firebase\JWT\SignatureInvalidException` |
| It hasn't expired, and wasn't issued in the future (allowing 60 seconds' difference between clocks by default) | Stops an old token being reused | `\Firebase\JWT\ExpiredException`, `\Firebase\JWT\BeforeValidException` |
| Its `iss` is `accounts.google.com` or `https://accounts.google.com` | Stops a token from another issuer being accepted | `ExceptionInvalidIdToken` |
| Its `aud` is your client ID | Stops a token that Google issued to another app being accepted | `ExceptionInvalidIdToken` |
| It has the claims Google always includes (`sub`, `email`, etc.) | Stops you getting a half-filled object | `ExceptionInvalidIdToken` |
| Google has verified the user's email address (`email_verified`), unless you pass `UnverifiedEmailPolicyEnum::ALLOW` | Stops someone using an email address they don't own to get into its owner's account | `ExceptionUnverifiedEmail` |

What isn't checked is up to you, as it depends on your app: which domain the user is from. See
[Security](security.md).

## What is sent to Google
- **The login URL** has your client ID, your callback URL, the `state`, and the scopes `openid email profile`. It
  doesn't ask for offline access, so Google doesn't give a refresh token, and the consent screen is the smallest it can
  be.
  It also has any [login options](login-options.md) you give it (`prompt`, `hd` and `login_hint`).
- **The token request** is a `POST` to `https://oauth2.googleapis.com/token`, as `application/x-www-form-urlencoded`,
  with the code, your client ID and secret, and your callback URL.
- **The certificates request** is a `GET` to `https://www.googleapis.com/oauth2/v3/certs`.

Both requests are sent with the PSR-18 client you give `GoogleSsoClient`, so they use its timeouts, proxy settings and
any logging it has.

## The CSRF token (state)
- It is 16 random bytes from `random_bytes()`, as 32 hexadecimal characters, kept in the CSRF token store. By default,
  that is `$_SESSION['googleSsoCsrfToken']`, but it can be your framework's session instead (see
  [Frameworks and sessions](frameworks.md)).
- Calling `createGoogleSsoLoginUrl()` again in the same session reuses the token, so several login links on a page all
  work. A different session always gets a different token.
- `handleGoogleSsoCallback()` removes it from the session before doing anything else, whether the login then succeeds
  or not. So each token works for one callback, and refreshing the callback page, or going back to it, fails with
  `ExceptionMissingCsrfToken`, rather than trying to use the code again.
- It is compared with `hash_equals()`, which takes the same time however much of it matches, so it can't be guessed a
  character at a time.
