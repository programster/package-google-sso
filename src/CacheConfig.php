<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

use Psr\Cache\CacheItemPoolInterface;

class CacheConfig
{
    private readonly CacheItemPoolInterface $m_cacheItemPool;
    private readonly int $m_jwtCachePeriod;
    private readonly string $m_cacheKey;


    /**
     * Create a configuration for caching Google's JWT certificates, so they don't have to be fetched on every login.
     *
     * @param CacheItemPoolInterface $cacheItemPool - the PSR-6 cache pool to store the certificates in.
     *
     * @param int $jwtCachePeriod - how many seconds to keep the certificates for. Defaults to 6 hours, a little less
     * than the 7 or so hours Google says they can be cached for, so a new key Google starts using is soon picked up.
     *
     * @param string $cacheKey - the key to store the certificates under in the pool.
     */
    public function __construct(
        CacheItemPoolInterface $cacheItemPool,
        int $jwtCachePeriod = 21600,
        string $cacheKey = "jwtCerts"
    )
    {
        $this->m_cacheItemPool = $cacheItemPool;
        $this->m_jwtCachePeriod = $jwtCachePeriod;
        $this->m_cacheKey = $cacheKey;
    }


    # Accessors
    public function getCacheKey() : string { return $this->m_cacheKey; }
    public function getJwtCachePeriod() : int { return $this->m_jwtCachePeriod; }
    public function getCacheItemPool() : CacheItemPoolInterface { return $this->m_cacheItemPool; }
}
