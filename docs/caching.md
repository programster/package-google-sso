# Caching Google's certificates

- [Why cache them](#why-cache-them)
- [Setting it up](#setting-it-up)
- [How long to cache them for](#how-long-to-cache-them-for)
- [What is cached](#what-is-cached)

## Why cache them
To check an ID token's signature, the package needs Google's public certificates, from
`https://www.googleapis.com/oauth2/v3/certs`. Without a cache, they are fetched on every login, which adds a request to
Google to each one. That is fine for a small site, but caching them makes logins faster, and means a login only needs
Google's certificates endpoint to be up once every few hours, rather than every time.

## Setting it up
Pass a `CacheConfig` with any PSR-6 cache pool (`Psr\Cache\CacheItemPoolInterface`). For example, with
`symfony/cache`:

```php
use Programster\GoogleSso\CacheConfig;
use Programster\GoogleSso\GoogleSsoClient;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$cachePool = new FilesystemAdapter(namespace: "google-sso", directory: "/var/cache/my-app");

$googleSso = new GoogleSsoClient(
    clientId: getenv("GOOGLE_CLIENT_ID"),
    clientSecret: getenv("GOOGLE_CLIENT_SECRET"),
    googleCallbackUrl: "https://example.com/login/callback",
    requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
    httpClient: new \GuzzleHttp\Client(['timeout' => 10]),
    cacheConfig: new CacheConfig($cachePool),
);
```

Other PSR-6 pools work the same way, e.g. Symfony's `RedisAdapter` or `ApcuAdapter`, or Laravel's cache through a PSR-6
bridge. Use a pool that is shared by all your web servers' processes (e.g. files, APCu, or Redis), rather than one that
only lasts for a request (such as `ArrayAdapter`), which would fetch them on every login anyway.

`CacheConfig` takes:

| Option | Default | What it is |
|---|---|---|
| `cacheItemPool` | (required) | The PSR-6 pool to keep the certificates in. |
| `jwtCachePeriod` | `21600` (6 hours) | How many seconds to keep them for. |
| `cacheKey` | `"jwtCerts"` | The key to keep them under in the pool. Change it if your pool already uses that key. |

## How long to cache them for
Google changes the keys it signs ID tokens with every so often, and publishes a new key in the certificates before it
starts using it. If you cache the certificates for too long, a login can be signed with a key that isn't in your cached
copy, and fails (php-jwt throws an `\UnexpectedValueException` saying the `kid` is invalid) until the cache expires.

Google's own response says how long the certificates may be cached for, in its `Cache-Control` header's `max-age`,
which is usually around seven hours. The default of 6 hours stays under that, so a new key is picked up soon after
Google publishes it. Avoid caching them for longer than Google allows. Caching them for less time is always safe,
and only costs an extra request to Google now and again. If you see the "invalid kid" error after Google has changed
its keys, clearing the cache item fixes it straight away.

## What is cached
Only the certificates, as JSON, under the cache key. Nothing about any user is ever cached. The certificates are only
cached once they have been fetched, and checked to be a valid set of keys, so an error from Google (e.g. a `503`) is
never cached. If the cache holds something that isn't a valid set of keys (e.g. left by an older version of this
package, which cached whatever it got), it is fetched again and replaced.
