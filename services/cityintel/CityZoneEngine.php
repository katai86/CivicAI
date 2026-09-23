<?php
/**
 * M2 – City zones from spatial grid + optional DB persistence.
 */
require_once __DIR__ . '/CitySpatialEngine.php';
require_once __DIR__ . '/../../db.php';

final class CityZoneEngine
{
    /** @return array<int,array<string,mixed>> */
    public function ensureZones(int $authorityId, ?float $centerLat = null, ?float $centerLng = null): array
    {
        $zones = [];
        try {
            $pdo = db();
            if (function_exists('db_table_has_column') && db_table_has_column($pdo, 'city_zones', 'zone_key')) {
                $st = $pdo->prepare('SELECT zone_key, name, zone_type, center_lat, center_lng FROM city_zones WHERE authority_id = ?');
                $st->execute([$authorityId]);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $zones[] = $row;
                }
                if ($zones !== []) {
                    return $zones;
                }
            }
        } catch (Throwable $e) {
            // fall through to grid
        }
        $engine = new CitySpatialEngine();
        $analysis = $engine->analyze($authorityId, 3, 30);
        foreach ($analysis['grid'] ?? [] as $cell) {
            $zones[] = [
                'zone_key' => (string)($cell['spatial_key'] ?? ''),
                'name' => (string)($cell['spatial_key'] ?? ''),
                'zone_type' => 'grid',
                'center_lat' => $cell['center_lat'] ?? null,
                'center_lng' => $cell['center_lng'] ?? null,
            ];
        }
        return $zones;
    }

    public function zoneKeyForPoint(int $authorityId, float $lat, float $lng): string
    {
        $bbox = $this->authorityBbox($authorityId, $lat, $lng);
        if (!$bbox) {
            return sprintf('grid_%.3f_%.3f', round($lat, 3), round($lng, 3));
        }
        $div = 3;
        $latStep = ($bbox['max_lat'] - $bbox['min_lat']) / $div;
        $lngStep = ($bbox['max_lng'] - $bbox['min_lng']) / $div;
        if ($latStep <= 0 || $lngStep <= 0) {
            return sprintf('grid_%.3f_%.3f', round($lat, 3), round($lng, 3));
        }
        $r = (int)min($div - 1, max(0, floor(($lat - $bbox['min_lat']) / $latStep)));
        $c = (int)min($div - 1, max(0, floor(($lng - $bbox['min_lng']) / $lngStep)));
        return 'grid_' . $r . '_' . $c;
    }

    /** @return array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}|null */
    private function authorityBbox(int $authorityId, ?float $lat = null, ?float $lng = null): ?array
    {
        try {
            $st = db()->prepare('SELECT MIN(lat) AS min_lat, MAX(lat) AS max_lat, MIN(lng) AS min_lng, MAX(lng) AS max_lng FROM city_observations WHERE authority_id = ? AND lat IS NOT NULL');
            $st->execute([$authorityId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['min_lat'] !== null) {
                return ['min_lat' => (float)$row['min_lat'], 'max_lat' => (float)$row['max_lat'], 'min_lng' => (float)$row['min_lng'], 'max_lng' => (float)$row['max_lng']];
            }
        } catch (Throwable $e) {
            // ignore
        }
        if ($lat !== null && $lng !== null) {
            return ['min_lat' => $lat - 0.01, 'max_lat' => $lat + 0.01, 'min_lng' => $lng - 0.01, 'max_lng' => $lng + 0.01];
        }
        return null;
    }
}
