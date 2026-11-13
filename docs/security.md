# Security

- [What the package does for you](#what-the-package-does-for-you)
- [Matching users to accounts](#matching-users-to-accounts)
- [Only letting in your Workspace](#only-letting-in-your-workspace)
- [Two-step verification](#two-step-verification)
- [After the login](#after-the-login)
- [Keeping the client secret safe](#keeping-the-client-secret-safe)
- [Checklist](#checklist)

## What the package does for you
Everything that is the same for every app is checked before you get the user's details: the CSRF token, PKCE, the
ID token's signature, expiry, issuer and audience, and Google's responses. See
[How it works](how-it-works.md#what-is-checked) for the full list. If any check fails, `handleGoogleSsoCallback()`
throws, so a login can't get through by your code forgetting to check something.

What's left is what depends on your app: who is allowed in, and which account they get.

## Matching users to accounts
Getting this wrong is the most common way for "Login with Google" to let someone into another person's account.

**Link accounts by Google ID.** `getSub()` is unique to the user, and never changes or is reused. When someone first
logs in with Google, store their `sub` against their account, and look them up by it from then on:

```php
$user = $users->findByGoogleId($googleUser->getSub());
```

**Only match by email address if Google has verified it.** If you also find accounts by email address (e.g. so that
someone who signed up with a password can start logging in with Google), check `getEmailVerified()` first. A Google
account can have an email address that its owner hasn't proved they own, and without the check, anyone could make a
Google account with your user's address, and log in as them:

```php
if (!$googleUser->getEmailVerified())
{
    throw new Exception("Please verify your email address with Google, and then try again.");
}

$user = $users->findByEmail($googleUser->getEmail());

// Store their Google ID, so that from now on they are found by it.
$users->linkGoogleId($user, $googleUser->getSub());
```

**Don't rely on the email address staying the same.** People change their address, and an address can be given to
someone else, e.g. when an employee leaves, or a domain changes hands. Once an account is linked to a `sub`, look it up
by that, rather than by the email address.

## Only letting in your Workspace
To only let in people from your Google Workspace (or Cloud Identity) organisation, check the hosted domain:

```php
if ($googleUser->getHostedDomain() !== "example.com")
{
    throw new Exception("Please log in with your example.com account.");
}
```

Check `getHostedDomain()`, not the end of the email address. A personal Google account can have any email address,
including one ending in `@example.com`, but only an account that your Workspace manages has `example.com` as its hosted
domain.

If your Google Cloud project belongs to your organisation, you can also make the app "Internal" (see
[Setting up Google](google-setup.md#internal-or-external)), so that Google refuses anyone else before they reach you.
Keeping the check in your code as well means it still holds if that setting is ever changed.

## Two-step verification
Google can now say how the user signed in to their Google account, in the `amr` claim (see
[How the user signed in to Google](user-data.md#how-the-user-signed-in-to-google)). It only sends it when the login
asks for it, which this version of the package doesn't do yet, so it can't tell you whether they used two-step
verification yet. If you need everyone to use it now:

1. Require two-step verification for your organisation, in the Google Workspace admin console.
2. Only let in users from your Workspace, as above.

Then Google won't let anyone log in to those accounts without it. This only works for accounts your Workspace manages.

## After the login
The package checks the login, but logging the user in to your site is up to you:

- **Give them a new session ID** as soon as they are logged in, with `session_regenerate_id(true)` (or your framework's
  equivalent). Otherwise, someone who got a user to use a session ID that they already knew (session fixation) would be
  logged in along with them.
- **Make the session cookie `Secure` and `HttpOnly`**, and `SameSite=Lax`. Not `Strict`, which stops the callback
  working (see [Frameworks and sessions](frameworks.md#the-session-cookie)).
- **Logging out of your site doesn't log them out of Google**, and logging out of Google doesn't log them out of your
  site. That is normal for "Login with Google". To log them out of your site, destroy your session as you normally
  would.
- **Don't keep the ID token or `GoogleUserData` as proof that they're logged in.** The ID token expires after an hour,
  and is only meant to be checked once, at login. Keep the user's ID in your own session instead.
- **Don't show the exception message to the user**, for the ones that mean something is wrong (e.g.
  `ExceptionCsrfTokenMismatch` holds the expected CSRF token). Log it, and show a general message instead. See
  [Exceptions](exceptions.md).

## Keeping the client secret safe
- Load the client secret from an environment variable or secrets manager, rather than putting it in your repository.
- If it leaks, create a new secret for the client in the Google Cloud console, and delete the old one.
- Use a separate client for development, so that its secret (which is often on developers' machines) can't be used
  in production.

## Checklist
- [ ] Users are found by `getSub()`, which is stored as a string, with a unique index.
- [ ] If users are ever found by email address, `getEmailVerified()` is checked first.
- [ ] If only your organisation should get in, `getHostedDomain()` is checked.
- [ ] The session ID is regenerated after logging in.
- [ ] The session cookie is `Secure`, `HttpOnly` and `SameSite=Lax`.
- [ ] The client secret isn't in the repository.
- [ ] The HTTP client has a timeout.
- [ ] Exception messages are logged, rather than shown to the user.
