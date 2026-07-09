<?php
/**
 * Közös logika az Intelligence Platform adatforrás-modulokhoz (M4–M9).
 */
trait IntelligenceModuleTrait
{
    abstract protected function moduleKey(): string;

    abstract protected function sourceKey(): string;

    protected function isModuleEnabled(): bool
    {
        return get_module_setting($this->moduleKey(), 'enabled') === '1';
    }

    /** Lite dashboard: cache vagy referencia, élő HTTP nélkül. */
    protected function liteFetchGuard(): bool
    {
        return class_exists('IntelligenceHub', false)
            && IntelligenceHub::isLiteFetchMode();
    }

    protected function cacheTtlMinutes(int $default = 360): int
    {
        $v = get_module_setting($this->moduleKey(), 'cache_ttl_minutes');
        if ($v !== null && $v !== '' && is_numeric($v)) {
            return max(15, min(10080, (int)$v));
        }
        return $default;
    }

    protected function mapLayerEnabled(): bool
    {
        $v = get_module_setting($this->moduleKey(), 'map_layer');
        return $v === null || $v === '' || $v === '1';
    }

    /** @param array<string,mixed> $payload */
    protected function cacheGet(string $cacheKey): ?array
    {
        $hit = ExternalDataCache::getValid($this->sourceKey(), $cacheKey);
        if ($hit && !empty($hit['payload']) && is_array($hit['payload'])) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }
        return null;
    }

    /** @param array<string,mixed> $payload */
    protected function cacheSet(string $cacheKey, array $payload, ?string $error = null): void
    {
        ExternalDataCache::set($this->sourceKey(), $cacheKey, $payload, $this->cacheTtlMinutes(), 'ok', $error);
        $this->recordSyncOk();
    }

    protected function recordSyncOk(): void
    {
        try {
            set_module_setting($this->moduleKey(), 'last_sync_at', gmdate('c'));
            set_module_setting($this->moduleKey(), 'last_error', null);
        } catch (Throwable $e) {
        }
    }

    protected function recordError(string $message): void
    {
        try {
            set_module_setting($this->moduleKey(), 'last_error', mb_substr($message, 0, 500));
        } catch (Throwable $e) {
        }
        ExternalDataCache::logProvider($this->sourceKey(), 'fetch', 'error', $message);
    }

    /** @return ?array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} */
    protected static function authorityBbox(?int $authorityId): ?array
    {
        if ($authorityId === null || $authorityId <= 0) {
            return null;
        }
        try {
            $st = db()->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
            $st->execute([$authorityId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['min_lat'] === null) {
                return null;
            }
            return [
                'min_lat' => (float)$row['min_lat'],
                'max_lat' => (float)$row['max_lat'],
                'min_lng' => (float)$row['min_lng'],
                'max_lng' => (float)$row['max_lng'],
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox */
    protected static function bboxCenter(array $bbox): array
    {
        return [
            'lat' => ($bbox['min_lat'] + $bbox['max_lat']) / 2,
            'lng' => ($bbox['min_lng'] + $bbox['max_lng']) / 2,
        ];
    }
}
