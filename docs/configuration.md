# Configuration

- [Creating the client](#creating-the-client)
- [The options](#the-options)
- [Choosing an HTTP client](#choosing-an-http-client)
- [Creating it once](#creating-it-once)

## Creating the client
Everything is set when the `GoogleSsoClient` is created. Only the first six options are needed, and the rest have
defaults that suit nearly everyone. Named arguments make it clear which is which:

```php
use Programster\GoogleSso\CacheConfig;
use Programster\GoogleSso\GoogleSsoClient;

$googleSso = new GoogleSsoClient(
    clientId: getenv("GOOGLE_CLIENT_ID"),
    clientSecret: getenv("GOOGLE_CLIENT_SECRET"),
    googleCallbackUrl: "https://example.com/login/callback",
    requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
    responseFactory: new \GuzzleHttp\Psr7\HttpFactory(),
    httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
    cacheConfig: new CacheConfig($cachePool),
    jwtLeewaySeconds: 60,
);
```

## The options

| Option | Default | What it is |
|---|---|---|
| `clientId` | (required) | Your app's client ID, from [Setting up Google](google-setup.md). |
| `clientSecret` | (required) | The client secret that goes with it. Load it from the environment, rather than putting it in your code. |
| `googleCallbackUrl` | (required) | The URL that Google sends users back to, where you call `handleGoogleSsoCallback()`. It must match one of the client's authorised redirect URIs exactly. |
| `requestFactory` | (required) | A PSR-17 `RequestFactoryInterface`, for creating the requests to Google, e.g. `new \GuzzleHttp\Psr7\HttpFactory()`. |
| `responseFactory` | (required) | A PSR-17 `ResponseFactoryInterface`, for creating the redirect that `createGoogleSsoLoginResponse()` returns. Guzzle's `HttpFactory` is both factories, so pass one for each. |
| `httpClient` | (required) | A PSR-18 `ClientInterface`, for sending them, e.g. `new \GuzzleHttp\Client()`. |
| `cacheConfig` | `null` | A `CacheConfig`, to cache Google's certificates rather than fetching them on every login. See [Caching](caching.md). |
| `csrfTokenStore` | `new NativeSessionCsrfTokenStore()` | Where the CSRF token is kept for the user, between them leaving for Google and coming back. The default keeps it in `$_SESSION`. See [Frameworks and sessions](frameworks.md) for using your framework's session instead. |
| `jwtLeewaySeconds` | `60` | How many seconds your server's clock may be out from Google's when checking the ID token's times. Can't be negative. |
| `googleJwtCertsUrl` | `"https://www.googleapis.com/oauth2/v3/certs"` | Where Google's certificates are fetched from. You shouldn't need to change it. |
| `googleOauthUrl` | `"https://oauth2.googleapis.com"` | The base of Google's OAuth endpoints, which `/token` is added to. You shouldn't need to change it. A trailing slash is removed. |

### jwtLeewaySeconds
An ID token says when it was issued (`iat`) and when it expires (`exp`), an hour later. If your server's clock is
behind Google's, a token can look as if it was issued in the future, and be refused with a `BeforeValidException`. The
leeway allows for that. A minute is plenty for a server that keeps its clock in sync (e.g. with NTP), and making it
much longer lets an expired token be used for longer. Setting it to `0` allows no difference at all.

`firebase/php-jwt` keeps its leeway in a static property, `JWT::$leeway`, which affects every token it decodes in the
process. The package only changes it while it decodes the ID token, and puts it back afterwards, so it doesn't affect
anything else that uses `firebase/php-jwt`.

## Choosing an HTTP client
Any PSR-18 client and PSR-17 factory will do. Some common choices:

| Package | `requestFactory` and `responseFactory` | `httpClient` |
|---|---|---|
| `guzzlehttp/guzzle` | `new \GuzzleHttp\Psr7\HttpFactory()` | `new \GuzzleHttp\Client(['timeout' => 10])` |
| `symfony/http-client` and `nyholm/psr7` | `new \Nyholm\Psr7\Factory\Psr17Factory()` | `new \Symfony\Component\HttpClient\Psr18Client()` |

Whichever you use, give it a timeout, so that if Google is slow to respond, your callback page fails after a few
seconds, rather than hanging for as long as PHP allows. Any proxy settings it has are used for both requests to Google.

If the HTTP client can't reach Google at all, it throws its own exception, which implements
`\Psr\Http\Client\ClientExceptionInterface`.

## Creating it once
The client holds no state of its own about any user (that is all in the CSRF token store), so one instance can be
shared by every request, e.g. as a singleton in your dependency injection container, as long as its store always uses
the current request's session. The default store does, as it uses `$_SESSION`. A store that is given one request's
session (like the Laravel example in [Frameworks and sessions](frameworks.md#laravel)) needs a client for each
request instead.
