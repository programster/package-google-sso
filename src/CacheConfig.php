<?php

namespace Programster\GoogleSso;
use Psr\Cache\CacheItemPoolInterface;

class CacheConfig
{
    public function __construct(
        private CacheItemPoolInterface $cacheItemPool,
        private readonly int $jwtCachePeriod = 86400,
        private readonly string $cacheKey = "jwtCerts"
    )
    {

    }

    public function getCacheKey() : string { return $this->cacheKey; }
    public function getJwtCachePeriod() : int { return $this->jwtCachePeriod; }
    public function getCacheItemPool() : CacheItemPoolInterface { return $this->cacheItemPool; }
}