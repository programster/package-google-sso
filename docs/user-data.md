# The user's details (`GoogleUserData`)

- [The details](#the-details)
- [Which to use for what](#which-to-use-for-what)
- [Details that can be empty](#details-that-can-be-empty)
- [The rest of the ID token](#the-rest-of-the-id-token)

## The details
`handleGoogleSsoCallback()` returns a `GoogleUserData`, which holds the claims from the user's ID token. It can't be
changed once created.

| Method | Claim | Example | Notes |
|---|---|---|---|
| `getSub()` | `sub` | `"110169484474386276334"` | The user's Google ID. Unique, and never changes or is reused. |
| `getEmail()` | `email` | `"ada@example.com"` | Their email address. It can change, and may not have been verified. |
| `getEmailVerified()` | `email_verified` | `true` | Whether Google has verified that they own the address. |
| `getHostedDomain()` | `hd` | `"example.com"` | The Google Workspace domain the account belongs to, or `""` for a personal account. |
| `getFullName()` | `name` | `"Ada Lovelace"` | Can be `""`. |
| `getFirstName()` | `given_name` | `"Ada"` | Can be `""`. |
| `getLastName()` | `family_name` | `"Lovelace"` | Can be `""`, e.g. for someone with only one name. |
| `getPictureUrl()` | `picture` | `"https://lh3.googleusercontent.com/a/..."` | Their profile picture. Can be `""`. |
| `getIss()` | `iss` | `"https://accounts.google.com"` | Who issued the token, which has been checked to be Google. |
| `getAud()` | `aud` | `"1234-abc.apps.googleusercontent.com"` | Who the token is for, which has been checked to be your client ID. |
| `getAzp()` | `azp` | `"1234-abc.apps.googleusercontent.com"` | The client that asked for the token. Usually the same as `aud`. Can be `""`. |
| `getAtHash()` | `at_hash` | `"HK6E_P6Dh8Y93mRNtsDB1Q"` | A hash of the access token. Can be `""`. |
| `getIssuedAt()` | `iat` | `1763020800` | When the token was issued, as a Unix timestamp. |
| `getExpiry()` | `exp` | `1763024400` | When the token expires, as a Unix timestamp, an hour after it was issued. |

## Which to use for what
- **To identify the user, use `getSub()`.** It is the only detail that is guaranteed to be unique and never change.
  Store it in your users table, with a unique index. Store it as a string, not a number: it is up to 255 characters,
  and too big for a 64-bit integer.
- **Don't identify the user by email address alone.** People change their address, and an address can end up with
  someone else, e.g. when a company domain changes hands. Matching by email (e.g. to link Google to an account
  someone made with a password) is safe by default, as users whose address Google hasn't verified are refused. If you
  pass `UnverifiedEmailPolicyEnum::ALLOW`, check `getEmailVerified()` first. See
  [Security](security.md#matching-users-to-accounts).
- **To only let in your organisation, use `getHostedDomain()`**, not the end of the email address. See
  [Security](security.md#only-letting-in-your-workspace).
- **The name and picture are for showing**, e.g. "Hello Ada". They can change whenever the user changes them at Google,
  so it is worth updating them from each login, rather than only when the account is created.
- **The expiry is the ID token's, not a login session's.** You don't need to log the user out when it passes. How long
  they stay logged in to your site is up to your own session.

## Details that can be empty
Google always includes `sub`, `email`, `email_verified`, `iss`, `aud`, `iat` and `exp` when the `email` scope is asked
for (which the package always does). If one is missing, or of the wrong type, `handleGoogleSsoCallback()` throws
`ExceptionInvalidIdToken`.

Google leaves out the others for some accounts, e.g. the family name for someone with only one name, the picture for
an account without one, and the hosted domain for a personal account. Those getters return `""` rather than throwing,
so these users can still log in. If your app needs a name, ask for one when the account is first created, rather than
expecting Google to have one.

## The rest of the ID token
`getClaims()` gives you every claim in the ID token, keyed by name, including any that have no getter of their own.
Any claims within claims (JSON objects) are arrays too, so it is plain data all the way down:

```php
$claims = $googleUser->getClaims();
// ['iss' => 'https://accounts.google.com', 'sub' => '110169484474386276334', 'email' => 'ada@example.com', ...]
```

The values are exactly as Google sent them, so check their types before using them. For the claims that have a
getter, use the getter instead, as it has been checked (e.g. `getEmailVerified()` is always a `bool`).

Google doesn't currently include any claims beyond those in [the table above](#the-details) for the scopes the
package asks for. In particular, it doesn't say how the user logged in, e.g. whether they used two-step verification
(the `amr` and `acr` claims, which some other providers include). If Google starts sending them, they will be in
`getClaims()`, but until then, don't treat a missing `amr` as meaning anything.

If you need to know that everyone logging in uses two-step verification, the way to do it with Google is to require it
in your Google Workspace's admin console, and then only let in users from your Workspace (see
[Security](security.md#two-step-verification)).
