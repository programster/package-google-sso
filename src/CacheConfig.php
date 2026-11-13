<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

use Psr\Cache\CacheItemPoolInterface;

final readonly class CacheConfig
{
    private CacheItemPoolInterface $m_cacheItemPool;
    private int $m_jwtCachePeriod;
    private string $m_cacheKey;


    # The characters PSR-6 reserves, which a cache key can't contain.
    private const RESERVED_KEY_CHARACTERS = "{}()/\\@:";


    /**
     * Create a configuration for caching Google's JWT certificates, so they don't have to be fetched on every login.
     *
     * @param CacheItemPoolInterface $cacheItemPool - the PSR-6 cache pool to store the certificates in.
     *
     * @param int $jwtCachePeriod - how many seconds to keep the certificates for. Defaults to 6 hours, a little less
     * than the 7 or so hours Google says they can be cached for, so a new key Google starts using is soon picked up.
     *
     * @param string $cacheKey - the key to store the certificates under in the pool.
     *
     * @throws \InvalidArgumentException - if $jwtCachePeriod is less than 1, or $cacheKey is empty, or has one of the
     * characters PSR-6 reserves: {}()/\@:
     */
    public function __construct(
        CacheItemPoolInterface $cacheItemPool,
        int $jwtCachePeriod = 21600,
        string $cacheKey = "jwtCerts"
    )
    {
        if ($jwtCachePeriod < 1)
        {
            $message = "The JWT cache period must be at least 1 second, but {$jwtCachePeriod} was given.";
            throw new \InvalidArgumentException($message);
        }

        if ($cacheKey === "" || strpbrk($cacheKey, self::RESERVED_KEY_CHARACTERS) !== false)
        {
            $reserved = self::RESERVED_KEY_CHARACTERS;
            $message = "The cache key can't be empty, or have any of {$reserved} in it, but \"{$cacheKey}\" was given.";
            throw new \InvalidArgumentException($message);
        }

        $this->m_cacheItemPool = $cacheItemPool;
        $this->m_jwtCachePeriod = $jwtCachePeriod;
        $this->m_cacheKey = $cacheKey;
    }


    # Accessors
    public function getCacheKey() : string { return $this->m_cacheKey; }
    public function getJwtCachePeriod() : int { return $this->m_jwtCachePeriod; }
    public function getCacheItemPool() : CacheItemPoolInterface { return $this->m_cacheItemPool; }
}
