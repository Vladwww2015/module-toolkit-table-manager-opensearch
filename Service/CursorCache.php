<?php

namespace ModuleToolkit\TableManagerOpenSearch\Service;

use Magento\Framework\App\CacheInterface;

class CursorCache
{
    const PREFIX = 'opensearch_cursor';

    public function __construct(
        protected CacheInterface $cache
    ){}

    public function saveCursor(string $index, int $page, int $pageSize, array $sort, array $searchAfter): void
    {
        $key = $this->getKey($index, $page, $pageSize, $sort);
        $this->cache->save(json_encode($searchAfter), $key, ['opensearch_cursor']);
    }

    public function getCursor(string $index, int $page, int $pageSize, array $sort): ?array
    {
        $key = $this->getKey($index, $page, $pageSize, $sort);
        $value = $this->cache->load($key);
        return $value ? json_decode($value, true) : null;
    }

    protected function getKey(string $index, int $page, int $pageSize, array $sort): string
    {
        ksort($sort);
        return sprintf('%s:%s:%d:%d:%s', self::PREFIX, $index, $page, $pageSize, json_encode($sort));
    }
}
