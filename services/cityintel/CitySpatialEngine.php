<?php
/**
 * Spatial intelligence: grid cells + subcity/zone comparison from observations & reports.
 */
require_once __DIR__ . '/CityIntelSchema.php';
require_once __DIR__ . '/../../db.php';

final class CitySpatialEngine
{
    /**
     * Build spatial summary for an authority.
     * @return array{
     *   ok:bool,
     *   grid:list<array<string,mixed>>,
     *   zones:list<array<string,mixed>>,
     *   hotspots:list<array<string,mixed>>,
     *   error:?string
     * }
     */
    public function analyze(?int $authorityId, int $gridDiv = 3, int $lookbackDays = 30): array
    {
        $out = ['ok' => true, 'grid' => [], 'zones' => [], 'hotspots' => [], 'error' => null];
        if (!CityIntelSchema::ensure()) {
            $out['ok'] = false;
            $out['error'] = 'schema_missing';
            return $out;
        }
        $gridDiv = max(2, min(6, $gridDiv));
        $lookbackDays = max(7, min(180, $lookbackDays));

        $bbox = $this->authorityBbox($authorityId);
        if ($bbox) {
            $out['grid'] = $this->gridFromObservations($authorityId, $bbox, $gridDiv, $lookbackDays);
        }
        $out['zones'] = $this->zonesFromReports($authorityId, $lookbackDays);
        $out['hotspots'] = $this->pickHotspots($out['grid'], $out['zones']);
        return $out;
    }

    /**
     * Persist grid cell indicator snapshots (spatial_key = grid_r_c).
     */
    public function persistGridIndicators(?int $authorityId, int $gridDiv = 3): int
    {
        $analysis = $this->analyze($authorityId, $gridDiv, 30);
        if (!$analysis['ok'] || empty($analysis['grid'])) {
            return 0;
        }
        $n = 0;
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d H:i:s');
        try {
            $st = db()->prepare('
                INSERT INTO city_indicator_values
                  (authority_id, indicator_key, period_start, period_end, spatial_key, value_num, unit,
                   sample_count, quality, confidence, calc_method, evidence_json)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  value_num = VALUES(value_num), sample_count = VALUES(sample_count),
                  evidence_json = VALUES(evidence_json), period_end = VALUES(period_end)
            ');
            foreach ($analysis['grid'] as $cell) {
                $sk = (string)$cell['spatial_key'];
                $evidence = json_encode([
                    'measured' => true,
                    'cell' => $cell,
                    'calc' => 'count of city_observations in grid cell',
                ], JSON_UNESCAPED_UNICODE);
                $st->execute([
                    $authorityId,
                    'spatial.observation_density',
                    $start,
                    $end,
                    $sk,
                    (float)($cell['observation_count'] ?? 0),
                    'count',
                    (int)($cell['observation_count'] ?? 0),
                    0.75,
                    0.75,
                    'grid_count_30d',
                    $evidence,
                ]);
                if (isset($cell['avg_value']) && $cell['avg_value'] !== null && !empty($cell['top_indicator'])) {
                    $st->execute([
                        $authorityId,
                        'spatial.' . preg_replace('/[^a-z0-9._-]+/i', '_', (string)$cell['top_indicator']),
                        $start,
                        $end,
                        $sk,
                        (float)$cell['avg_value'],
                        $cell['unit'] ?? null,
                        (int)($cell['observation_count'] ?? 0),
                        0.7,
                        0.7,
                        'grid_avg_30d',
                        $evidence,
                    ]);
                }
                $n++;
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('CitySpatialEngine::persistGridIndicators: ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** @return array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}|null */
    private function authorityBbox(?int $authorityId): ?array
    {
        if ($authorityId === null || $authorityId <= 0) {
            return null;
        }
        try {
            $st = db()->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
            $st->execute([$authorityId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['min_lat'] === null) {
                return null;
            }
            return [
                'min_lat' => (float)$r['min_lat'],
                'max_lat' => (float)$r['max_lat'],
                'min_lng' => (float)$r['min_lng'],
                'max_lng' => (float)$r['max_lng'],
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     * @return list<array<string,mixed>>
     */
    private function gridFromObservations(?int $authorityId, array $bbox, int $div, int $days): array
    {
        $where = 'observed_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY) AND lat IS NOT NULL AND lng IS NOT NULL';
        $params = [];
        if ($authorityId !== null && $authorityId > 0) {
            $where .= ' AND authority_id = ?';
            $params[] = $authorityId;
        }
        try {
            $st = db()->prepare("SELECT lat, lng, indicator_type, value_num, unit, source_key FROM city_observations WHERE $where LIMIT 5000");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $minLat = $bbox['min_lat'];
        $maxLat = $bbox['max_lat'];
        $minLng = $bbox['min_lng'];
        $maxLng = $bbox['max_lng'];
        $dLat = max(1e-9, $maxLat - $minLat);
        $dLng = max(1e-9, $maxLng - $minLng);

        $cells = [];
        for ($r = 0; $r < $div; $r++) {
            for ($c = 0; $c < $div; $c++) {
                $key = 'grid_' . $r . '_' . $c;
                $cells[$key] = [
                    'spatial_key' => $key,
                    'row' => $r,
                    'col' => $c,
                    'min_lat' => $minLat + ($r / $div) * $dLat,
                    'max_lat' => $minLat + (($r + 1) / $div) * $dLat,
                    'min_lng' => $minLng + ($c / $div) * $dLng,
                    'max_lng' => $minLng + (($c + 1) / $div) * $dLng,
                    'observation_count' => 0,
                    'value_sum' => 0.0,
                    'value_n' => 0,
                    'avg_value' => null,
                    'top_indicator' => null,
                    'unit' => null,
                    'indicator_counts' => [],
                    'sources' => [],
                ];
            }
        }

        foreach ($rows as $row) {
            $lat = (float)$row['lat'];
            $lng = (float)$row['lng'];
            if ($lat < $minLat || $lat > $maxLat || $lng < $minLng || $lng > $maxLng) {
                continue;
            }
            $ri = (int)floor((($lat - $minLat) / $dLat) * $div);
            $ci = (int)floor((($lng - $minLng) / $dLng) * $div);
            $ri = max(0, min($div - 1, $ri));
            $ci = max(0, min($div - 1, $ci));
            $key = 'grid_' . $ri . '_' . $ci;
            $cells[$key]['observation_count']++;
            $ind = (string)($row['indicator_type'] ?? '');
            if ($ind !== '') {
                if (!isset($cells[$key]['indicator_counts'][$ind])) {
                    $cells[$key]['indicator_counts'][$ind] = 0;
                }
                $cells[$key]['indicator_counts'][$ind]++;
            }
            $src = (string)($row['source_key'] ?? '');
            if ($src !== '') {
                $cells[$key]['sources'][$src] = true;
            }
            if ($row['value_num'] !== null && is_numeric($row['value_num'])) {
                $cells[$key]['value_sum'] += (float)$row['value_num'];
                $cells[$key]['value_n']++;
                $cells[$key]['unit'] = $row['unit'];
            }
        }

        $out = [];
        foreach ($cells as $cell) {
            if ($cell['value_n'] > 0) {
                $cell['avg_value'] = round($cell['value_sum'] / $cell['value_n'], 4);
            }
            $top = null;
            $topN = 0;
            foreach ($cell['indicator_counts'] as $ik => $cnt) {
                if ($cnt > $topN) {
                    $topN = $cnt;
                    $top = $ik;
                }
            }
            $cell['top_indicator'] = $top;
            $cell['sources'] = array_keys($cell['sources']);
            unset($cell['value_sum'], $cell['value_n'], $cell['indicator_counts']);
            $out[] = $cell;
        }
        usort($out, static fn ($a, $b) => ($b['observation_count'] <=> $a['observation_count']));
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function zonesFromReports(?int $authorityId, int $days): array
    {
        $where = "created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY)";
        $params = [];
        if ($authorityId !== null && $authorityId > 0) {
            $where .= ' AND authority_id = ?';
            $params[] = $authorityId;
        }
        $zoneExpr = "COALESCE(NULLIF(TRIM(suburb), ''), NULLIF(TRIM(city), ''), '—')";
        try {
            $st = db()->prepare("
                SELECT $zoneExpr AS zone_name, category, COUNT(*) AS cnt
                FROM reports
                WHERE $where
                GROUP BY zone_name, category
                ORDER BY cnt DESC
                LIMIT 40
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $byZone = [];
        foreach ($rows as $r) {
            $z = (string)($r['zone_name'] ?? '—');
            if (!isset($byZone[$z])) {
                $byZone[$z] = [
                    'spatial_key' => 'zone:' . mb_substr($z, 0, 80),
                    'zone' => $z,
                    'report_count' => 0,
                    'by_category' => [],
                ];
            }
            $c = (int)$r['cnt'];
            $byZone[$z]['report_count'] += $c;
            $cat = (string)($r['category'] ?? 'other');
            $byZone[$z]['by_category'][$cat] = ($byZone[$z]['by_category'][$cat] ?? 0) + $c;
        }
        $list = array_values($byZone);
        usort($list, static fn ($a, $b) => ($b['report_count'] <=> $a['report_count']));
        return $list;
    }

    /**
     * @param list<array<string,mixed>> $grid
     * @param list<array<string,mixed>> $zones
     * @return list<array<string,mixed>>
     */
    private function pickHotspots(array $grid, array $zones): array
    {
        $hot = [];
        foreach (array_slice($grid, 0, 3) as $g) {
            if ((int)($g['observation_count'] ?? 0) < 1) {
                continue;
            }
            $sk = (string)($g['spatial_key'] ?? '');
            $gridLabel = $sk;
            if (function_exists('t')) {
                if (preg_match('/(\d+)[_\-](\d+)/', $sk, $m)) {
                    $gridLabel = str_replace(['%r%', '%c%'], [$m[1], $m[2]], t('gov.city_intel_grid_cell'));
                } elseif (preg_match('/grid[_l]?[_\-]?(\d+)/i', $sk, $m)) {
                    $gridLabel = str_replace('%n%', $m[1], t('gov.city_intel_grid_cell_one'));
                }
            }
            $hot[] = [
                'type' => 'grid',
                'spatial_key' => $g['spatial_key'],
                'score' => (int)$g['observation_count'],
                'label' => $gridLabel,
                'top_indicator' => $g['top_indicator'] ?? null,
                'bbox' => [
                    'min_lat' => $g['min_lat'], 'max_lat' => $g['max_lat'],
                    'min_lng' => $g['min_lng'], 'max_lng' => $g['max_lng'],
                ],
                'measured' => true,
            ];
        }
        foreach (array_slice($zones, 0, 3) as $z) {
            if ((int)($z['report_count'] ?? 0) < 1) {
                continue;
            }
            $zoneName = (string)($z['zone'] ?? '');
            if (function_exists('civic_fix_hu_mojibake')) {
                $zoneName = civic_fix_hu_mojibake($zoneName);
            }
            $zonePrefix = function_exists('t') ? t('gov.city_intel_hotspot.zone') : 'zone';
            $hot[] = [
                'type' => 'zone',
                'spatial_key' => $z['spatial_key'],
                'score' => (int)$z['report_count'],
                'label' => ($zoneName !== '' && $zoneName !== '—') ? ($zonePrefix . ': ' . $zoneName) : $zonePrefix,
                'zone' => $zoneName,
                'by_category' => $z['by_category'],
                'measured' => true,
            ];
        }
        return $hot;
    }
}
