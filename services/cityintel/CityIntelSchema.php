<?php
/**
 * City Intelligence – séma ellenőrzés / lazy create (migration 2026-32).
 */
require_once __DIR__ . '/../../db.php';

final class CityIntelSchema
{
    private static ?bool $ok = null;

    public static function ensure(): bool
    {
        if (self::$ok !== null) {
            return self::$ok;
        }
        try {
            $pdo = db();
            $pdo->query('SELECT 1 FROM city_data_sources LIMIT 1');
            $pdo->query('SELECT 1 FROM city_observations LIMIT 1');
            $pdo->query('SELECT 1 FROM city_indicator_values LIMIT 1');
            $pdo->query('SELECT 1 FROM city_baselines LIMIT 1');
            $pdo->query('SELECT 1 FROM city_anomalies LIMIT 1');
            $pdo->query('SELECT 1 FROM city_insights LIMIT 1');
            self::$ok = true;
            return true;
        } catch (Throwable $e) {
            // try create from migration file
        }
        $path = dirname(__DIR__, 2) . '/sql/2026-32-city-intelligence.sql';
        if (!is_file($path)) {
            self::$ok = false;
            return false;
        }
        try {
            $sql = (string)file_get_contents($path);
            $pdo = db();
            foreach (self::splitStatements($sql) as $stmt) {
                if ($stmt === '') {
                    continue;
                }
                $pdo->exec($stmt);
            }
            self::$ok = true;
            return true;
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('CityIntelSchema::ensure: ' . $e->getMessage());
            }
            self::$ok = false;
            return false;
        }
    }

    /** @return list<string> */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $buf = '';
        $out = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $buf .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $out[] = trim($buf);
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
