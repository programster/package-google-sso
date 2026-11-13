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
Google changes the keys it signs ID tokens with every so often. Each ID token says which key it was signed with (its
`kid`), so if a login is signed with a key that isn't in the cached certificates, the package fetches them from Google
again (once), and caches the new ones. So logins keep working when Google changes its keys, however long you cache
the certificates for.

The default of 6 hours stays under the `max-age` that Google's own response allows (usually around seven hours), so
that a key Google stops using is soon dropped from the cache too. There's rarely a reason to change it. Caching them
for less time is always safe, and only costs an extra request to Google now and again.

## What is cached
Only the certificates, as JSON, under the cache key. Nothing about any user is ever cached. The certificates are only
cached once they have been fetched, and checked to be a valid set of keys, so an error from Google (e.g. a `503`) is
never cached. If the cache holds something that isn't a valid set of keys (e.g. left by an older version of this
package, which cached whatever it got), or doesn't have the key a login was signed with, they are fetched again, and
the cached copy is replaced.
