# Troubleshooting

- [Google shows "Error 400: redirect_uri_mismatch"](#google-shows-error-400-redirect_uri_mismatch)
- [Google shows "Access blocked" or "This app is blocked"](#google-shows-access-blocked-or-this-app-is-blocked)
- [ExceptionMissingCsrfToken on every login](#exceptionmissingcsrftoken-on-every-login)
- [ExceptionMissingCsrfToken now and again](#exceptionmissingcsrftoken-now-and-again)
- [ExceptionCsrfTokenMismatch](#exceptioncsrftokenmismatch)
- [ExceptionUnexpectedResponse with invalid_grant or redirect_uri_mismatch](#exceptionunexpectedresponse-with-invalid_grant-or-redirect_uri_mismatch)
- [ExceptionUnexpectedResponse with invalid_client or unauthorized_client](#exceptionunexpectedresponse-with-invalid_client-or-unauthorized_client)
- [ExceptionInvalidIdToken about the aud claim](#exceptioninvalididtoken-about-the-aud-claim)
- [ExceptionMfaRequired for everyone](#exceptionmfarequired-for-everyone)
- [BeforeValidException or ExpiredException](#beforevalidexception-or-expiredexception)
- [UnexpectedValueException saying "kid" is invalid](#unexpectedvalueexception-saying-kid-is-invalid)
- [The login page hangs](#the-login-page-hangs)
- [Composer won't install the package](#composer-wont-install-the-package)

## Google shows "Error 400: redirect_uri_mismatch"
The `googleCallbackUrl` you gave `GoogleSsoClient` isn't one of the client's authorised redirect URIs. They must match
exactly, including `http` or `https`, the port, the path, and a trailing slash. Copy the URL from the error's details
into the client's settings in the Google Cloud console (see [Setting up Google](google-setup.md#redirect-uris)). It can
take a few minutes for a change to work.

A common cause is a site behind a proxy or load balancer, where the callback URL was worked out from the request, and
came out as `http://` or with an internal host name. Set `googleCallbackUrl` from your configuration instead.

## Google shows "Access blocked" or "This app is blocked"
- If the app is in **Testing**, only its test users can log in. Add the user as a test user, or publish the app (see
  [Setting up Google](google-setup.md#testing-or-in-production)).
- If the app is **Internal**, only users in your organisation can log in.
- A Google Workspace admin can block apps for their users. The user's admin would need to allow it.

## ExceptionMissingCsrfToken on every login
The session that `createGoogleSsoLoginUrl()` put the CSRF token in isn't there when Google sends the user back. Check:

- **`session_start()` is called before both** creating the login URL and handling the callback. Without it, the token
  is put in a `$_SESSION` array that is never saved.
- **The session cookie isn't `SameSite=Strict`**. Browsers don't send `Strict` cookies when coming from another site,
  as the callback does. Use `Lax` (see [Frameworks and sessions](frameworks.md#the-session-cookie)).
- **The login and the callback are on the same host.** A session cookie for `www.example.com` isn't sent to
  `example.com`, so if users start the login on one and come back to the other, the callback has no session. Make
  `googleCallbackUrl` use the same host your site does.
- **Your framework fills in `$_SESSION`**, if you use the default store. Laravel doesn't, and Symfony may not keep a
  session it doesn't know has anything in it. Give the client a store that uses your framework's session instead (see
  [Frameworks and sessions](frameworks.md)).
- **The session cookie is `Secure`**, but the site is on `http://` (e.g. in development), so the browser never sends it.

## ExceptionMissingCsrfToken now and again
This is usually a user doing something you can't stop, rather than a bug:

- they refreshed the callback page, or went back to it, after logging in,
- they were at Google for longer than your session lasts,
- they opened the login in two tabs, and finished both (the second finds the token used up).

Tell them their login has expired, and to try again. If they're already logged in, send them on as if it had worked.

## ExceptionCsrfTokenMismatch
The `state` that came back isn't the one in the session. This happens if the user started a login in one session and
finished it in another (e.g. they logged out in another tab in between, which gave them a new session), and it is also
what you would see if someone tried to log a user in to the attacker's own account (a login CSRF attack). Either way,
refusing it is right. Ask them to try again.

## ExceptionUnexpectedResponse with invalid_grant or redirect_uri_mismatch
Google refused the code. Codes can only be used once, and expire after a few minutes, so this is usually:

- the callback being handled twice, e.g. a browser or proxy that requested it twice,
- the user taking a long time between Google sending them back and your server handling the callback,
- the server's clock being far out.

If the error is `redirect_uri_mismatch` instead, the callback was handled by a `GoogleSsoClient` with a different
`googleCallbackUrl` from the one that created the login URL. Use the same callback URL for both.

The message includes Google's description of the error. If it mentions the code verifier (e.g. "Missing code
verifier."), the PKCE check failed. That can happen:

- for logins that were already under way when you upgraded to a version of this package with PKCE, as their login URL
  had no code challenge. They only need to log in again.
- if the client secret was changed in between the login starting and the callback, as the code verifier is worked out
  with it. Again, logging in again fixes it.
- if the login URL and the callback are handled by clients with different client secrets, e.g. a development and a
  production client sharing a session. Use the same client for both.

## ExceptionUnexpectedResponse with invalid_client or unauthorized_client
Google doesn't accept your client ID or secret. Check that they are both from the same client, that the secret hasn't
been deleted or replaced, and that there's no whitespace or newline around them (e.g. from reading them from a file).

## ExceptionInvalidIdToken about the aud claim
The ID token was issued to a different client from the `clientId` you gave `GoogleSsoClient`. If you have several
clients (e.g. one for development and one for production), check the client ID and secret are from the same one.

## ExceptionMfaRequired for everyone
If `getAuthenticationMethods()` on the exception is empty, Google isn't telling your app how users signed in. It only
does for apps that are published ("In production"), verified, and have "Authentication strength claims" turned on, in
the Google Auth Platform's settings (see Google's
[security bundle](https://developers.google.com/identity/siwg/security-bundle)). Until then, switch `mfaPolicy` to
`MfaPolicyEnum::REPORT`, so nobody is refused.

If it has values, such as `["pwd"]`, those users signed in to Google with only a password. They need to turn on 2-Step
Verification for their Google account, and then sign out of Google and in again, as it describes their last sign-in.

## BeforeValidException or ExpiredException
The ID token's times don't match your server's clock. ID tokens last an hour, and are checked straight away, so this
nearly always means your server's clock is wrong. Keep it in sync with NTP (e.g. `chrony` or `systemd-timesyncd`). If
it can't be fixed, increase `jwtLeewaySeconds` (see [Configuration](configuration.md#jwtleewayseconds)).

## UnexpectedValueException saying "kid" is invalid
The ID token is signed with a key that isn't in Google's certificates. The package already fetches the certificates
again when a key isn't in the cached ones (see [Caching](caching.md#how-long-to-cache-them-for)), so this means the key
wasn't in the freshly fetched ones either. Check:

- that `googleJwtCertsUrl` hasn't been changed from its default,
- that nothing between your server and Google (e.g. a caching proxy) is serving an old copy of the certificates,
- that `googleOauthUrl` hasn't been changed to something other than Google, as the ID token would then not be
  Google's.

## The login page hangs
Your server can't reach Google, and the HTTP client has no timeout. Give it one (e.g.
`new \GuzzleHttp\Client(['timeout' => 10])`), so it throws a `ClientExceptionInterface` instead, and check that your
server can reach `oauth2.googleapis.com` and `www.googleapis.com` on port 443. If it needs a proxy to reach the
internet, configure it on the HTTP client, which the package uses for both requests.

## Composer won't install the package
If Composer refuses because of a security advisory for `firebase/php-jwt`, something else in your project is holding
it at version 6, which every version of has an advisory. This package works with version 7, so update whatever needs
version 6, or run `composer update firebase/php-jwt --with-all-dependencies` to see what's holding it back.
