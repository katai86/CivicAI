<?php
/**
 * M4 – City situation map aggregate (hotspots + priorities + anomalies).
 */
require_once __DIR__ . '/CitySpatialEngine.php';
require_once __DIR__ . '/UnifiedPriorityEngine.php';
require_once __DIR__ . '/CityIndicatorEngines.php';
require_once __DIR__ . '/CityIntelLabels.php';

final class CitySituationEngine
{
    /** @return array<string,mixed> */
    public function build(int $authorityId): array
    {
        $spatial = (new CitySpatialEngine())->analyze($authorityId, 3, 14);
        $priorities = (new UnifiedPriorityEngine())->computeForAuthority($authorityId, 15);
        $anom = (new CityAnomalyEngine())->listRecent($authorityId, 10);
        $markers = [];
        foreach ($spatial['hotspots'] ?? [] as $h) {
            $type = (string)($h['type'] ?? 'hotspot');
            $rawLabel = (string)($h['label'] ?? $h['spatial_key'] ?? '');
            $markers[] = [
                'type' => 'hotspot',
                'hotspot_type' => $type,
                'label' => $this->formatHotspotLabel($type, $rawLabel, $h),
                'score' => (float)($h['score'] ?? 0),
                'severity' => 'high',
                'bbox' => $h['bbox'] ?? null,
                'lat' => isset($h['bbox']['min_lat'], $h['bbox']['max_lat'])
                    ? (((float)$h['bbox']['min_lat'] + (float)$h['bbox']['max_lat']) / 2) : null,
                'lng' => isset($h['bbox']['min_lng'], $h['bbox']['max_lng'])
                    ? (((float)$h['bbox']['min_lng'] + (float)$h['bbox']['max_lng']) / 2) : null,
            ];
        }
        foreach (array_slice($priorities, 0, 8) as $p) {
            $etype = (string)($p['entity_type'] ?? '');
            $factors = is_array($p['factors'] ?? null) ? $p['factors'] : [];
            $markers[] = [
                'type' => 'priority',
                'entity_type' => $etype,
                'entity_id' => (int)($p['entity_id'] ?? 0),
                'label' => $this->formatPriorityLabel($etype, (int)($p['entity_id'] ?? 0), $factors),
                'score' => (float)($p['priority_score'] ?? 0),
                'severity' => ($p['priority_score'] ?? 0) >= 70 ? 'high' : 'medium',
                'category' => isset($factors['category']) ? (string)$factors['category'] : null,
                'lat' => isset($p['lat']) ? (float)$p['lat'] : (isset($factors['lat']) ? (float)$factors['lat'] : null),
                'lng' => isset($p['lng']) ? (float)$p['lng'] : (isset($factors['lng']) ? (float)$factors['lng'] : null),
            ];
        }
        return [
            'ok' => true,
            'authority_id' => $authorityId,
            'markers' => $markers,
            'hotspots' => $spatial['hotspots'] ?? [],
            'priorities' => $priorities,
            'anomalies' => $anom,
            'generated_at' => date('c'),
        ];
    }

    /** @param array<string,mixed> $h */
    private function formatHotspotLabel(string $type, string $raw, array $h): string
    {
        $sk = (string)($h['spatial_key'] ?? $raw);
        if ($type === 'grid' || preg_match('/^grid/i', $sk)) {
            if (preg_match('/(\d+)[_\-](\d+)/', $sk, $m)) {
                return function_exists('t')
                    ? str_replace(['%r%', '%c%'], [$m[1], $m[2]], t('gov.city_intel_grid_cell'))
                    : ('Grid ' . $m[1] . '×' . $m[2]);
            }
            if (preg_match('/grid[_l]?[_\-]?(\d+)/i', $sk, $m)) {
                return function_exists('t')
                    ? str_replace('%n%', $m[1], t('gov.city_intel_grid_cell_one'))
                    : ('Grid ' . $m[1]);
            }
            // Already humanized label from spatial engine
            if ($raw !== '' && !preg_match('/^grid/i', $raw)) {
                return $raw;
            }
            return function_exists('t') ? t('gov.city_intel_hotspot.grid') : 'grid';
        }
        if ($type === 'zone' || str_starts_with($sk, 'zone:')) {
            $zone = $h['zone'] ?? preg_replace('/^zone:/', '', $sk);
            $zone = trim((string)$zone);
            if (function_exists('civic_fix_hu_mojibake')) {
                $zone = civic_fix_hu_mojibake($zone);
            }
            $prefix = function_exists('t') ? t('gov.city_intel_hotspot.zone') : 'zone';
            return $zone !== '' && $zone !== '—' ? ($prefix . ': ' . $zone) : $prefix;
        }
        return $raw !== '' ? $raw : (function_exists('t') ? t('gov.city_intel_hotspot_label') : 'hotspot');
    }

    /** @param array<string,mixed> $factors */
    private function formatPriorityLabel(string $etype, int $entityId, array $factors): string
    {
        if ($etype === 'report_category') {
            $cat = (string)($factors['category'] ?? '');
            $catLabel = $this->categoryLabel($cat);
            $open = (int)($factors['open_count'] ?? 0);
            $base = function_exists('t') ? t('gov.city_intel_entity.report_category') : 'Reports';
            return $base . ': ' . $catLabel . ($open > 0 ? ' (' . $open . ')' : '');
        }
        if ($etype === 'tree') {
            $base = function_exists('t') ? t('gov.city_intel_entity.tree') : 'Tree';
            $risk = (string)($factors['risk'] ?? '');
            $health = (string)($factors['health'] ?? '');
            $extra = trim(($health !== '' ? $health : '') . ($risk !== '' ? ' / ' . $risk : ''), ' /');
            return $base . ' #' . $entityId . ($extra !== '' ? ' · ' . $extra : '');
        }
        if ($etype === 'observation') {
            $ind = (string)($factors['indicator'] ?? '');
            $indLabel = $ind !== '' ? CityIntelLabels::indicatorLabel($ind) : '';
            $base = function_exists('t') ? t('gov.city_intel_entity.observation') : 'Observation';
            return $base . ($indLabel !== '' && $indLabel !== '—' ? ': ' . $indLabel : (' #' . $entityId));
        }
        if ($etype === 'report') {
            $base = function_exists('t') ? t('gov.city_intel_entity.report') : 'Report';
            return $base . ' #' . $entityId;
        }
        return ($etype !== '' ? $etype : 'item') . ($entityId > 0 ? ' #' . $entityId : '');
    }

    private function categoryLabel(string $cat): string
    {
        if ($cat === '') {
            return '—';
        }
        $key = 'cat.' . $cat;
        if (function_exists('t')) {
            $lab = t($key);
            if ($lab !== $key) {
                return $lab;
            }
        }
        return $cat;
    }
}
