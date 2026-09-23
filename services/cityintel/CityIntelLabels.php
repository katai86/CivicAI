<?php
/**
 * City Intelligence – emberi olvasható címkék és gov műveleti javaslatok (i18n).
 */
require_once __DIR__ . '/../../util.php';

final class CityIntelLabels
{
    /** @return array<string,string> */
    public static function indicatorLabels(): array
    {
        return [
            'climate.temp_c' => t('gov.city_intel_ind.climate_temp'),
            'climate.heat_risk' => t('gov.city_intel_ind.climate_heat'),
            'climate.drought_index' => t('gov.city_intel_ind.climate_drought'),
            'climate.precip_mm' => t('gov.city_intel_ind.climate_precip'),
            'green.ndvi' => t('gov.city_intel_ind.green_ndvi'),
            'green.drought_risk' => t('gov.city_intel_ind.green_drought'),
            'green.canopy_proxy' => t('gov.city_intel_ind.green_canopy'),
            'osm.green_feature_density_per_km2' => t('gov.city_intel_ind.osm_green'),
            'osm.building_density_per_km2' => t('gov.city_intel_ind.osm_building'),
            'osm.amenity_density_per_km2' => t('gov.city_intel_ind.osm_amenity'),
            'osm.cycleways' => t('gov.city_intel_ind.osm_cycle'),
            'osm.ev_chargers' => t('gov.city_intel_ind.osm_ev'),
            'air.no2' => t('gov.city_intel_ind.air_no2'),
            'air.pm25' => t('gov.city_intel_ind.air_pm25'),
            'air.pm10' => t('gov.city_intel_ind.air_pm10'),
            'energy.pv_yield' => t('gov.city_intel_ind.energy_pv'),
            'bio.occurrence_count' => t('gov.city_intel_ind.bio_gbif'),
            'citizen.open_reports' => t('gov.city_intel_ind.citizen_open'),
            'citizen.green_reports_7d' => t('gov.city_intel_ind.citizen_green'),
            'vision.vegetation_stress_signals' => t('gov.city_intel_ind.vision_veg'),
            'vision.severity_score' => t('gov.city_intel_ind.vision_severity'),
            'trees.needing_water' => t('gov.city_intel_ind.trees_water'),
            'spatial.observation_density' => t('gov.city_intel_ind.spatial_density'),
        ];
    }

    public static function indicatorLabel(?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }
        $labels = self::indicatorLabels();
        return $labels[$key] ?? self::humanizeKey($key);
    }

    /** @return array<string,string> */
    public static function severityLabels(): array
    {
        return [
            'high' => t('severity.high'),
            'medium' => t('severity.medium'),
            'low' => t('severity.low'),
            'info' => t('gov.city_intel_sev.info'),
        ];
    }

    public static function severityLabel(?string $severity): string
    {
        $s = strtolower(trim((string)$severity));
        return self::severityLabels()[$s] ?? ($s !== '' ? self::humanizeKey($s) : '—');
    }

    /** @return array<string,string> */
    public static function trendLabels(): array
    {
        return [
            'improving' => t('gov.trend_improving'),
            'stable' => t('gov.trend_stable'),
            'deteriorating' => t('gov.trend_declining'),
        ];
    }

    public static function trendLabel(?string $trend): string
    {
        $t = strtolower(trim((string)$trend));
        return self::trendLabels()[$t] ?? ($t !== '' ? self::humanizeKey($t) : '—');
    }

    /** @return array<string,string> */
    public static function sourceStatusLabels(): array
    {
        return [
            'ok' => t('gov.city_intel_src.ok'),
            'error' => t('gov.city_intel_src.error'),
            'idle' => t('gov.city_intel_src.idle'),
            'running' => t('gov.city_intel_src.running'),
            'stale' => t('gov.city_intel_src.stale'),
        ];
    }

    public static function sourceStatusLabel(?string $status): string
    {
        $s = strtolower(trim((string)$status));
        return self::sourceStatusLabels()[$s] ?? ($s !== '' ? self::humanizeKey($s) : '—');
    }

    /** @return array<string,string> */
    public static function hotspotTypeLabels(): array
    {
        return [
            'grid' => t('gov.city_intel_hotspot.grid'),
            'zone' => t('gov.city_intel_hotspot.zone'),
        ];
    }

    /** @return array<string,mixed> */
    public static function dictionary(): array
    {
        return [
            'indicators' => self::indicatorLabels(),
            'severity' => self::severityLabels(),
            'trend' => self::trendLabels(),
            'source_status' => self::sourceStatusLabels(),
            'hotspot_type' => self::hotspotTypeLabels(),
        ];
    }

    /**
     * Gov műveleti javaslatok a dashboard adatokból.
     * @param array<string,mixed> $dashboard
     * @return list<array{priority:string,title:string,detail:string,link_tab?:string}>
     */
    public static function buildActions(array $dashboard): array
    {
        $actions = [];
        $insights = is_array($dashboard['insights'] ?? null) ? $dashboard['insights'] : [];
        $anomalies = is_array($dashboard['anomalies'] ?? null) ? $dashboard['anomalies'] : [];
        $deteriorating = is_array($dashboard['deteriorating'] ?? null) ? $dashboard['deteriorating'] : [];
        $spatial = is_array($dashboard['spatial'] ?? null) ? $dashboard['spatial'] : [];
        $freshness = is_array($dashboard['freshness'] ?? null) ? $dashboard['freshness'] : [];

        foreach ($insights as $ins) {
            if (!is_array($ins)) {
                continue;
            }
            $sev = (string)($ins['severity'] ?? '');
            if (!in_array($sev, ['high', 'medium'], true)) {
                continue;
            }
            $key = (string)($ins['indicator_key'] ?? '');
            $label = self::indicatorLabel($key !== '' ? $key : null);
            $area = trim((string)($ins['affected_area'] ?? ''));
            $act = self::actionForIndicator($key, $sev, $area);
            if ($act) {
                $actions[] = [
                    'priority' => $sev === 'high' ? 'high' : 'medium',
                    'title' => $act['title'],
                    'detail' => ($ins['interpretation_text'] ?? '') !== ''
                        ? self::cleanInterpretation((string)$ins['interpretation_text'])
                        : $act['detail'],
                    'link_tab' => 'city-intel',
                    'insight_id' => (int)($ins['id'] ?? 0),
                ];
            }
            if (count($actions) >= 5) {
                break;
            }
        }

        if (count($actions) < 3) {
            foreach ($deteriorating as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = (string)($row['indicator_key'] ?? '');
                $label = self::indicatorLabel($key);
                $actions[] = [
                    'priority' => 'medium',
                    'title' => self::tr('gov.city_intel_act.trend_watch', ['%ind%' => $label]),
                    'detail' => t('gov.city_intel_act.trend_watch_detail'),
                    'link_tab' => 'city-intel',
                ];
                if (count($actions) >= 5) {
                    break;
                }
            }
        }

        $hotspots = is_array($spatial['hotspots'] ?? null) ? $spatial['hotspots'] : [];
        if (!empty($hotspots[0]) && count($actions) < 6) {
            $hs = $hotspots[0];
            $actions[] = [
                'priority' => ((int)($hs['score'] ?? 0) >= 5) ? 'medium' : 'low',
                'title' => self::tr('gov.city_intel_act.hotspot', ['%zone%' => (string)($hs['label'] ?? $hs['spatial_key'] ?? '')]),
                'detail' => t('gov.city_intel_act.hotspot_detail'),
                'link_tab' => 'city-intel',
            ];
        }

        if ((int)($freshness['error'] ?? 0) > 0 && count($actions) < 7) {
            $actions[] = [
                'priority' => 'low',
                'title' => t('gov.city_intel_act.source_error'),
                'detail' => self::tr('gov.city_intel_act.source_error_detail', ['%n%' => (string)(int)$freshness['error']]),
                'link_tab' => 'city-intel',
            ];
        }

        if (!$actions && !empty($dashboard['indicators'])) {
            $actions[] = [
                'priority' => 'low',
                'title' => t('gov.city_intel_act.stable'),
                'detail' => t('gov.city_intel_act.stable_detail'),
                'link_tab' => 'city-intel',
            ];
        }

        usort($actions, static function ($a, $b) {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($order[$a['priority'] ?? 'low'] ?? 9) <=> ($order[$b['priority'] ?? 'low'] ?? 9);
        });

        return array_slice($actions, 0, 6);
    }

    /**
     * @return array{title:string,detail:string}|null
     */
    private static function actionForIndicator(string $key, string $severity, string $area): ?array
    {
        $zone = $area !== '' && $area !== 'city' ? $area : t('gov.city_intel_area_city');
        $map = [
            'climate.heat_risk' => ['title' => t('gov.city_intel_act.heat'), 'detail' => self::tr('gov.city_intel_act.heat_detail', ['%zone%' => $zone])],
            'climate.temp_c' => ['title' => t('gov.city_intel_act.heat'), 'detail' => self::tr('gov.city_intel_act.heat_detail', ['%zone%' => $zone])],
            'green.drought_risk' => ['title' => t('gov.city_intel_act.drought'), 'detail' => self::tr('gov.city_intel_act.drought_detail', ['%zone%' => $zone])],
            'green.ndvi' => ['title' => t('gov.city_intel_act.ndvi'), 'detail' => t('gov.city_intel_act.ndvi_detail')],
            'citizen.open_reports' => ['title' => t('gov.city_intel_act.reports'), 'detail' => t('gov.city_intel_act.reports_detail')],
            'citizen.green_reports_7d' => ['title' => t('gov.city_intel_act.green_reports'), 'detail' => t('gov.city_intel_act.green_reports_detail')],
            'trees.needing_water' => ['title' => t('gov.city_intel_act.trees'), 'detail' => t('gov.city_intel_act.trees_detail')],
            'air.no2' => ['title' => t('gov.city_intel_act.air'), 'detail' => t('gov.city_intel_act.air_detail')],
            'spatial.observation_density' => ['title' => t('gov.city_intel_act.spatial'), 'detail' => self::tr('gov.city_intel_act.spatial_detail', ['%zone%' => $zone])],
        ];
        if (isset($map[$key])) {
            return $map[$key];
        }
        $label = self::indicatorLabel($key);
        return [
            'title' => self::tr('gov.city_intel_act.generic', ['%ind%' => $label]),
            'detail' => self::tr('gov.city_intel_act.generic_detail', ['%sev%' => self::severityLabel($severity)]),
        ];
    }

    /** @param array<string,string> $replacements */
    private static function tr(string $key, array $replacements): string
    {
        $text = t($key);
        foreach ($replacements as $from => $to) {
            $text = str_replace($from, $to, $text);
        }
        return $text;
    }

    private static function cleanInterpretation(string $text): string
    {
        $text = preg_replace('/^AI INTERPRETATION:\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^MEASURED[^:]*:\s*/iu', '', $text) ?? $text;
        return trim($text);
    }

    private static function humanizeKey(string $key): string
    {
        $key = str_replace(['_', '.'], ' ', $key);
        return mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $dashboard
     * @return array<string,int|float|string>
     */
    public static function buildSummary(array $dashboard): array
    {
        $insights = is_array($dashboard['insights'] ?? null) ? $dashboard['insights'] : [];
        $anomalies = is_array($dashboard['anomalies'] ?? null) ? $dashboard['anomalies'] : [];
        $high = 0;
        $medium = 0;
        foreach ($insights as $i) {
            if (!is_array($i)) {
                continue;
            }
            $s = (string)($i['severity'] ?? '');
            if ($s === 'high') {
                $high++;
            } elseif ($s === 'medium') {
                $medium++;
            }
        }
        $freshness = is_array($dashboard['freshness'] ?? null) ? $dashboard['freshness'] : [];
        $spatial = is_array($dashboard['spatial'] ?? null) ? $dashboard['spatial'] : [];
        return [
            'insights_total' => count($insights),
            'insights_high' => $high,
            'insights_medium' => $medium,
            'anomalies_total' => count($anomalies),
            'improving_count' => count($dashboard['improving'] ?? []),
            'deteriorating_count' => count($dashboard['deteriorating'] ?? []),
            'sources_ok' => (int)($freshness['ok'] ?? 0),
            'sources_error' => (int)($freshness['error'] ?? 0),
            'sources_stale' => (int)($freshness['stale'] ?? 0),
            'hotspots' => count($spatial['hotspots'] ?? []),
            'generated_at' => (string)($dashboard['generated_at'] ?? date('c')),
        ];
    }
}
