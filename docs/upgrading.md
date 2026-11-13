# Upgrading from 0.0.2

- [Changes you should make](#changes-you-should-make)
- [Changes in behaviour](#changes-in-behaviour)
- [New features](#new-features)

Nothing has been removed, and existing code keeps working, but some failures that used to give a PHP warning or a
`TypeError` now throw a specific exception, so it is worth checking how your callback handles them.

## Changes you should make
- **Use `handleGoogleSsoCallback($_GET)` instead of `handleGoogleSsoLogin()`.** The old method still works, but is
  deprecated. The new one takes the query parameters, so in a framework you can pass
  `$request->getQueryParams()`, rather than relying on `$_GET`.
- **Catch `ExceptionGoogleLoginError`**, for users who cancel at Google. Previously, a cancelled login gave PHP
  warnings, and then a less helpful error. See [Exceptions](exceptions.md).
- **Check `getEmailVerified()` if you match users by email address.** This has always been needed, but the README didn't
  say so. See [Security](security.md#matching-users-to-accounts).
- **In a PSR-7 framework, use `createGoogleSsoLoginUrl()`** rather than `sendUserToGoogleLogin()`, which calls `die()`.

## Changes in behaviour
- **The ID token's `aud` and `iss` are checked.** A token issued to a different client, or not by Google, throws
  `ExceptionInvalidIdToken`. If this happens after upgrading, the client ID and secret you are using are from
  different clients.
- **The CSRF token is single use.** It is removed from the session by the callback, whether the login succeeds or not.
  Refreshing the callback page now throws `ExceptionMissingCsrfToken`. (Previously it sent the used code to Google
  again, which failed anyway.) A missing CSRF token in the session throws, rather than giving a warning.
- **The state is compared with `hash_equals()`.**
- **The login URL is no longer cached in a static variable**, which every instance (and in a long-running server,
  every user) shared. Calling `createGoogleSsoLoginUrl()` several times in a session still returns the same URL.
- **Offline access is no longer asked for.** The login URL no longer has `access_type=offline`, so the consent screen
  is smaller. The refresh token it got was never available to you, so nothing is lost.
- **Google's errors throw `ExceptionUnexpectedResponse`**, with the HTTP status and Google's error (e.g.
  `invalid_grant`) in its message. Previously, an error from the token endpoint gave warnings, and then a `TypeError`.
  `getResponse()` on the exception now works (it used to throw an `Error`).
- **Google's certificates are fetched with your HTTP client**, instead of `file_get_contents()`. They now use its
  timeouts and proxy settings, and `allow_url_fopen` isn't needed. A failure throws, and is never cached.
- **The token request is form-encoded**, as Google documents, rather than JSON.
- **60 seconds of clock difference is allowed** when checking the ID token's times. It was 0. See `jwtLeewaySeconds`
  in [Configuration](configuration.md).
- **Missing optional claims are empty strings.** Users without a family name, a picture, etc. can now log in, rather
  than getting a `TypeError`. A missing required claim throws `ExceptionInvalidIdToken`.
- **`firebase/php-jwt` 7 is supported**, as well as 6.11. As every version of 6 has a security advisory, Composer will
  now install 7.
- **`ExceptionCsrfTokenMismatch`'s message says "CSRF"**, rather than "CRSF", in case you match on it.
- **Google's certificates are cached for 6 hours by default**, rather than a day, if you use a `CacheConfig`. Google
  says they can be cached for around 7 hours, so this picks up a new key Google starts using sooner. Pass
  `jwtCachePeriod` to choose your own.
- **`sessionCsrfTokenKey` is deprecated.** It still works, but pass `new NativeSessionCsrfTokenStore("yourKey")` as the
  new `csrfTokenStore` option instead.

## New features
- `GoogleUserData::getHostedDomain()`, for the `hd` claim, to only let in your Workspace's users.
- `ExceptionGoogleLoginError::isAccessDenied()` and `getError()`.
- `ExceptionMissingCallbackParameter::getParameterName()`.
- The `jwtLeewaySeconds` constructor option.
- The `csrfTokenStore` constructor option, and `CsrfTokenStoreInterface`, to keep the CSRF token in your framework's
  session (e.g. Laravel's) rather than `$_SESSION`. See [Frameworks and sessions](frameworks.md).
