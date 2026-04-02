<?php
/**
 * EU / külső API válaszok cache-elése (external_data_cache tábla).
 * TTL: admin → cache_ttl_minutes, vagy EU_OPEN_DATA_CACHE_TTL_MINUTES env.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

class ExternalDataCache
{
    private static ?bool $tablesOk = null;

    public static function tablesAvailable(): bool
    {
        if (self::$tablesOk !== null) {
            return self::$tablesOk;
        }
        try {
            db()->query('SELECT 1 FROM external_data_cache LIMIT 1');
            self::$tablesOk = true;
        } catch (Throwable $e) {
            self::$tablesOk = false;
        }
        return self::$tablesOk;
    }

    /**
     * @return array{payload:?array, fetched_at:?string, expires_at:?string, status:?string, cached:bool}|null
     */
    public static function getValid(string $sourceKey, string $cacheKey): ?array
    {
        if (!self::tablesAvailable()) {
            return null;
        }
        $sourceKey = self::sanitizeKey($sourceKey, 64);
        $cacheKey = self::sanitizeKey($cacheKey, 255);
        try {
            $stmt = db()->prepare('
                SELECT payload_json, fetched_at, expires_at, status
                FROM external_data_cache
                WHERE source_key = ? AND cache_key = ? AND expires_at > NOW() AND status = \'ok\'
                LIMIT 1
            ');
            $stmt->execute([$sourceKey, $cacheKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $payload = null;
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string)$row['payload_json'], true);
                $payload = is_array($decoded) ? $decoded : null;
            }
            return [
                'payload' => $payload,
                'fetched_at' => $row['fetched_at'] ?? null,
                'expires_at' => $row['expires_at'] ?? null,
                'status' => $row['status'] ?? null,
                'cached' => true,
            ];
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('ExternalDataCache::getValid: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function set(
        string $sourceKey,
        string $cacheKey,
        array $payload,
        ?int $ttlMinutes = null,
        string $status = 'ok',
        ?string $errorMessage = null
    ): void {
        if (!self::tablesAvailable()) {
            return;
        }
        $sourceKey = self::sanitizeKey($sourceKey, 64);
        $cacheKey = self::sanitizeKey($cacheKey, 255);
        $ttl = $ttlMinutes;
        if ($ttl === null && function_exists('eu_open_data_cache_ttl_minutes')) {
            $ttl = eu_open_data_cache_ttl_minutes();
        }
        if ($ttl === null || $ttl < 1) {
            $env = getenv('EU_OPEN_DATA_CACHE_TTL_MINUTES');
            $ttl = ($env !== false && $env !== '' && is_numeric($env)) ? max(1, (int)$env) : 60;
        }
        $ttl = max(1, min(10080, $ttl));

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $err = $errorMessage !== null ? mb_substr($errorMessage, 0, 512) : null;

        try {
            $sql = '
                INSERT INTO external_data_cache (source_key, cache_key, payload_json, fetched_at, expires_at, status, error_message)
                VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?)
                ON DUPLICATE KEY UPDATE
                  payload_json = VALUES(payload_json),
                  fetched_at = VALUES(fetched_at),
                  expires_at = VALUES(expires_at),
                  status = VALUES(status),
                  error_message = VALUES(error_message)
            ';
            $stmt = db()->prepare($sql);
            $stmt->execute([$sourceKey, $cacheKey, $json, $ttl, $status, $err]);
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('ExternalDataCache::set: ' . $e->getMessage());
            }
        }
    }

    public static function logProvider(string $sourceKey, string $action, string $status, ?string $message = null): void
    {
        try {
            db()->query('SELECT 1 FROM external_data_provider_logs LIMIT 1');
        } catch (Throwable $e) {
            return;
        }
        $sourceKey = self::sanitizeKey($sourceKey, 64);
        $action = self::sanitizeKey($action, 120);
        $status = self::sanitizeKey($status, 32);
        try {
            $stmt = db()->prepare('
                INSERT INTO external_data_provider_logs (source_key, action, status, message)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$sourceKey, $action, $status, $message]);
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('ExternalDataCache::logProvider: ' . $e->getMessage());
            }
        }
    }

    /** @return int törölt sorok száma */
    public static function purgeExpired(): int
    {
        if (!self::tablesAvailable()) {
            return 0;
        }
        try {
            return db()->exec('DELETE FROM external_data_cache WHERE expires_at < NOW()') ?: 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function sanitizeKey(string $key, int $maxLen): string
    {
        $key = trim($key);
        if (mb_strlen($key) > $maxLen) {
            $key = mb_substr($key, 0, $maxLen);
        }
        return $key;
    }
}
