# Frameworks and sessions

- [Where the CSRF token is kept](#where-the-csrf-token-is-kept)
- [Plain PHP](#plain-php)
- [Slim and other PSR-7 frameworks](#slim-and-other-psr-7-frameworks)
- [Laravel](#laravel)
- [Symfony](#symfony)
- [Long-running servers (RoadRunner, Swoole, FrankenPHP workers)](#long-running-servers-roadrunner-swoole-frankenphp-workers)
- [Writing your own store](#writing-your-own-store)
- [The session cookie](#the-session-cookie)

## Where the CSRF token is kept
The package needs two things from your app: a way to redirect the user, and somewhere to keep the CSRF token for the
user, in between them leaving for Google and coming back. That "somewhere" is a CSRF token store
(`CsrfTokenStoreInterface`), given to the constructor as `csrfTokenStore`:

- By default, it is a `NativeSessionCsrfTokenStore`, which keeps the token in `$_SESSION`. This suits plain PHP, Slim,
  and anything else that uses PHP's native sessions.
- For a framework with its own session system, such as Laravel, or a long-running server, give it a store that uses
  your framework's session instead. The store is three short methods, and there are examples for Laravel and Symfony
  below.

The Laravel and Symfony stores below are written against those frameworks' session interfaces, but the package's own
tests only cover the stores that come with it, so check that a login works end to end in your app.

## Plain PHP
Call `session_start()` before both `createGoogleSsoLoginUrl()` and `handleGoogleSsoCallback()`, send the user to the
login URL with `header("Location: ...")`, and pass `$_GET` to the callback. See the
[quick start](../README.md#quick-start).

## Slim and other PSR-7 frameworks
In a framework where routes return a PSR-7 response, return `createGoogleSsoLoginResponse()`, which is a redirect to
Google's login page, created with the `responseFactory` you gave the client. It isn't to be cached (it has a
`Cache-Control: no-store` header), as it carries the user's CSRF token. Pass `$request->getQueryParams()` to the
callback:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$sendToGoogle = function (ServerRequestInterface $request, ResponseInterface $response) use ($googleSso) {
    return $googleSso->createGoogleSsoLoginResponse();
};

$handleGoogleCallback = function (ServerRequestInterface $request, ResponseInterface $response) use ($googleSso) {
    $googleUser = $googleSso->handleGoogleSsoCallback($request->getQueryParams());
    // ... find or create the user's account, and log them in
    return $response->withHeader('Location', '/')->withStatus(302);
};

$app->get('/login', $sendToGoogle);
$app->get('/login/callback', $handleGoogleCallback);
```

Slim doesn't start sessions for you, so call `session_start()` at the start of your app, or use a session middleware
that uses PHP's native sessions. If your session middleware doesn't use `$_SESSION`, write a store for it (see
[Writing your own store](#writing-your-own-store)).

## Laravel
Laravel has its own session system, which doesn't use `$_SESSION`, so give the client a store that uses Laravel's
session:

```php
use Illuminate\Contracts\Session\Session;
use Programster\GoogleSso\CsrfTokenStoreInterface;

final readonly class LaravelCsrfTokenStore implements CsrfTokenStoreInterface
{
    private const KEY = "googleSsoCsrfToken";

    private Session $m_session;


    public function __construct(Session $session)
    {
        $this->m_session = $session;
    }


    public function getToken() : string
    {
        $token = $this->m_session->get(self::KEY, "");
        return is_string($token) ? $token : "";
    }


    public function setToken(string $token) : void
    {
        $this->m_session->put(self::KEY, $token);
    }


    public function removeToken() : void
    {
        $this->m_session->forget(self::KEY);
    }
}
```

The store holds the current request's session, so create the client in each request, rather than once for the whole
app, e.g.:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Programster\GoogleSso\GoogleSsoClient;

$createGoogleSso = function (Request $request) : GoogleSsoClient {
    return new GoogleSsoClient(
        clientId: config('services.google.client_id'),
        clientSecret: config('services.google.client_secret'),
        googleCallbackUrl: url('/login/callback'),
        requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        responseFactory: new \GuzzleHttp\Psr7\HttpFactory(),
        httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
        csrfTokenStore: new LaravelCsrfTokenStore($request->session()),
    );
};

$sendToGoogle = function (Request $request) use ($createGoogleSso) {
    return redirect()->away($createGoogleSso($request)->createGoogleSsoLoginUrl());
};

$handleGoogleCallback = function (Request $request) use ($createGoogleSso) {
    $googleUser = $createGoogleSso($request)->handleGoogleSsoCallback($request->query());
    $user = User::firstWhere('google_id', $googleUser->getSub());
    // ... create the user if there isn't one
    Auth::login($user);
    $request->session()->regenerate();
    return redirect()->intended('/');
};

Route::get('/login', $sendToGoogle);
Route::get('/login/callback', $handleGoogleCallback);
```

The routes need Laravel's session, so they must be in the `web` middleware group (which routes in `routes/web.php`
are by default). Laravel also has Socialite, which you may prefer if your app already uses it for other providers.

## Symfony
Symfony's session is usually backed by PHP's native session, but Symfony manages it itself, and may not keep a session
that only has the package's token in `$_SESSION`, as it doesn't know about it. So give the client a store that uses
Symfony's session. Taking the `RequestStack` means the store always uses the current request's session, so the client
can be a normal shared service:

```php
use Programster\GoogleSso\CsrfTokenStoreInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SymfonyCsrfTokenStore implements CsrfTokenStoreInterface
{
    private const KEY = "googleSsoCsrfToken";

    private RequestStack $m_requestStack;


    public function __construct(RequestStack $requestStack)
    {
        $this->m_requestStack = $requestStack;
    }


    public function getToken() : string
    {
        $token = $this->m_requestStack->getSession()->get(self::KEY, "");
        return is_string($token) ? $token : "";
    }


    public function setToken(string $token) : void
    {
        $this->m_requestStack->getSession()->set(self::KEY, $token);
    }


    public function removeToken() : void
    {
        $this->m_requestStack->getSession()->remove(self::KEY);
    }
}
```

Then pass it as the `csrfTokenStore` when you define the `GoogleSsoClient` service. In your controller, return a
`RedirectResponse` to `createGoogleSsoLoginUrl()`, as Symfony's responses aren't PSR-7 ones, and pass
`$request->query->all()` to `handleGoogleSsoCallback()`.

## Long-running servers (RoadRunner, Swoole, FrankenPHP workers)
In these servers, one PHP process handles many requests, and `$_SESSION` isn't per request unless your session
integration makes it so, as PHP's native sessions expect one process per request. If `$_SESSION` were shared between
requests, every user would share a CSRF token, which would defeat the check.

So in these servers, don't rely on the default store: give the client a store that uses your framework's per-request
session, like the Laravel and Symfony ones above. Either create the client for each request with that request's
session (as in the Laravel example), or give the store a way to find the current request's session (as in the Symfony
example), in which case one client can be shared. The client itself keeps nothing about any user.

## Writing your own store
Implement `Programster\GoogleSso\CsrfTokenStoreInterface`'s three methods:

| Method | Must |
|---|---|
| `getToken() : string` | Return the current user's token, or `""` if they don't have one. |
| `setToken(string $token) : void` | Keep the token for the current user, replacing any they already have. |
| `removeToken() : void` | Remove the current user's token, if they have one. |

The token must be kept per user (e.g. in their session), and never anywhere shared between users, such as a cache
keyed only by something the client controls. It only needs to be kept until the user comes back from Google, which is
usually a minute or two.

## The session cookie
Google sends the user back with a normal top-level redirect from `accounts.google.com`, which browsers count as
coming from another site. A session cookie with `SameSite=Strict` isn't sent on such a request, so the callback finds
no session, and fails with `ExceptionMissingCsrfToken`. Use `SameSite=Lax` for the session cookie (the default in
most frameworks), which browsers do send when following a link or redirect from another site:

```php
session_set_cookie_params(['samesite' => 'Lax', 'secure' => true, 'httponly' => true]);
session_start();
```
