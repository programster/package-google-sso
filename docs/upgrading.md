# Upgrading

- [From 0.x to 1.0.0](#from-0x-to-100)
- [From 0.2.0 to 0.3.0](#from-020-to-030)
- [From 0.1.0 to 0.2.0](#from-010-to-020)
- [From 0.0.2 to 0.1.0](#from-002-to-010)
    - [Changes you should make](#changes-you-should-make)
    - [Changes in behaviour](#changes-in-behaviour)
    - [New features](#new-features)

## From 0.x to 1.0.0
1.0.0 removes what 0.x deprecated, so first upgrade to the latest 0.x, and fix any deprecation warnings your IDE shows.
Then:

- **Users whose email address Google hasn't verified are refused.** `handleGoogleSsoCallback()` throws an
  `ExceptionUnverifiedEmail` for them, so catch it, and ask them to verify their address with Google. If you only ever
  find users by `getSub()`, you can let them in as before, with
  `handleGoogleSsoCallback($_GET, UnverifiedEmailPolicyEnum::ALLOW)`. See
  [Security](security.md#matching-users-to-accounts).
- **Pass a `responseFactory` to the constructor.** It's a PSR-17 `ResponseFactoryInterface`, and is now required.
  Guzzle's `HttpFactory` (and Nyholm's `Psr17Factory`) is both factories, so pass the same class as your
  `requestFactory`:

  ```php
  $googleSso = new GoogleSsoClient(
      clientId: getenv("GOOGLE_CLIENT_ID"),
      clientSecret: getenv("GOOGLE_CLIENT_SECRET"),
      googleCallbackUrl: "https://example.com/login/callback",
      requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
      responseFactory: new \GuzzleHttp\Psr7\HttpFactory(),
      httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
  );
  ```

- **`sendUserToGoogleLogin()` has been removed**, as it called `header()` and `die()`. In plain PHP, use
  `header("Location: " . $googleSso->createGoogleSsoLoginUrl());`. In a PSR-7 framework, return the new
  `createGoogleSsoLoginResponse()`, which takes the same options. See [Frameworks and sessions](frameworks.md).
- **`handleGoogleSsoLogin()` has been removed.** Use `handleGoogleSsoCallback($_GET)`, or pass your framework's query
  parameters.
- **The `sessionCsrfTokenKey` constructor option has been removed.** Pass
  `csrfTokenStore: new NativeSessionCsrfTokenStore("yourKey")` instead.
- **The constructor's options are in a new order**: `csrfTokenStore` and `jwtLeewaySeconds` now come straight after
  `cacheConfig`, before the two URLs. Code that uses named arguments, as the docs do, isn't affected. Code that passes
  them by position fails with a `TypeError`, rather than putting a value in the wrong place.
- **The classes are `final`**: `GoogleSsoClient`, `GoogleUserData`, `CacheConfig`, and every exception except
  `ExceptionCsrfTokenMismatch` (which `ExceptionMissingCsrfToken` extends) and the abstract `ExceptionGoogleSso`.
  `GoogleSsoClient`, `GoogleUserData` and `CacheConfig` are also `readonly`. If you extended one, wrap it in a class of
  your own instead. To fake Google in tests, give the client a fake PSR-18 HTTP client, as the package's own tests do.

## From 0.2.0 to 0.3.0
Nothing has been removed or changed in a way that needs your code to change.

- **The login URL takes three optional settings**: `prompt` (a `LoginPromptEnum`, e.g. `SELECT_ACCOUNT` to always ask
  which Google account to use), `hostedDomainHint` and `loginHint`. `sendUserToGoogleLogin()` takes them too. See
  [Login options](login-options.md).
- **`ExceptionGoogleLoginError::isInteractionRequired()`**, for the errors Google sends back with
  `LoginPromptEnum::NONE` when the user would have to do something.

## From 0.1.0 to 0.2.0
Nothing has been removed or changed in a way that needs your code to change.

- **Every exception the package throws extends the new abstract `ExceptionGoogleSso`**, so you can catch them all at
  once. They still extend `\Exception`, so existing `catch` blocks keep working. See [Exceptions](exceptions.md).
- **`GoogleUserData::getClaims()`** gives every claim in the ID token, including any without a getter of their own.
  See [The user's details](user-data.md#the-rest-of-the-id-token).
- **Cached certificates are fetched again when a login is signed with a key that isn't in them**, e.g. because Google
  has started using a new key since they were cached. Previously, those logins failed with an "invalid kid" error
  until the cache expired. See [Caching](caching.md#how-long-to-cache-them-for).

## From 0.0.2 to 0.1.0
Nothing has been removed, and existing code keeps working, but some failures that used to give a PHP warning or a
`TypeError` now throw a specific exception, so it is worth checking how your callback handles them.

### Changes you should make
- **Use `handleGoogleSsoCallback($_GET)` instead of `handleGoogleSsoLogin()`.** The old method still works, but is
  deprecated. The new one takes the query parameters, so in a framework you can pass
  `$request->getQueryParams()`, rather than relying on `$_GET`.
- **Catch `ExceptionGoogleLoginError`**, for users who cancel at Google. Previously, a cancelled login gave PHP
  warnings, and then a less helpful error. See [Exceptions](exceptions.md).
- **Check `getEmailVerified()` if you match users by email address.** This has always been needed, but the README didn't
  say so. See [Security](security.md#matching-users-to-accounts).
- **In a PSR-7 framework, use `createGoogleSsoLoginUrl()`** rather than `sendUserToGoogleLogin()`, which calls `die()`.

### Changes in behaviour
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

### New features
- `GoogleUserData::getHostedDomain()`, for the `hd` claim, to only let in your Workspace's users.
- `ExceptionGoogleLoginError::isAccessDenied()` and `getError()`.
- `ExceptionMissingCallbackParameter::getParameterName()`.
- The `jwtLeewaySeconds` constructor option.
- The `csrfTokenStore` constructor option, and `CsrfTokenStoreInterface`, to keep the CSRF token in your framework's
  session (e.g. Laravel's) rather than `$_SESSION`. See [Frameworks and sessions](frameworks.md).
