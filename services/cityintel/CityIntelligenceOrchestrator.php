<?php
/**
 * City Intelligence orchestrator:
 * Source → Fetch → Normalize → Store → Aggregate → Baseline → Anomaly → Insight
 */
require_once __DIR__ . '/CityIntelSchema.php';
require_once __DIR__ . '/CityDataSourceRegistry.php';
require_once __DIR__ . '/CityObservationStore.php';
require_once __DIR__ . '/OsmOverpassIngester.php';
require_once __DIR__ . '/CityIndicatorEngines.php';
require_once __DIR__ . '/CityInsightEngine.php';
require_once __DIR__ . '/CitySpatialEngine.php';
require_once __DIR__ . '/CityIntelLabels.php';
require_once __DIR__ . '/UnifiedPriorityEngine.php';
require_once __DIR__ . '/CityDiscoveryEngine.php';
require_once __DIR__ . '/CityChangeIntelligence.php';
require_once __DIR__ . '/CityZoneEngine.php';
require_once __DIR__ . '/CitySituationEngine.php';
require_once __DIR__ . '/PatternCorrelationEngine.php';
require_once __DIR__ . '/CityTimeMachine.php';
require_once __DIR__ . '/ScenarioEngine.php';
require_once __DIR__ . '/CityHealthScoreV2.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';

final class CityIntelligenceOrchestrator
{
    /**
     * Full sync for one authority (or first scoped).
     * @return array<string,mixed>
     */
    public function run(?int $authorityId = null, bool $forceOsm = false): array
    {
        if (!CityIntelSchema::ensure()) {
            return ['ok' => false, 'error' => 'city_intel_schema_missing'];
        }

        $auth = $this->resolveAuthority($authorityId);
        if (!$auth) {
            return ['ok' => false, 'error' => 'no_authority_bbox'];
        }
        $aid = (int)$auth['id'];
        $bbox = [
            'min_lat' => (float)$auth['min_lat'],
            'max_lat' => (float)$auth['max_lat'],
            'min_lng' => (float)$auth['min_lng'],
            'max_lng' => (float)$auth['max_lng'],
        ];
        $city = trim((string)($auth['city'] ?? ''));
        $registry = new CityDataSourceRegistry();
        $store = new CityObservationStore();
        $results = [
            'ok' => true,
            'authority_id' => $aid,
            'city' => $city,
            'sources' => [],
            'observations_inserted' => 0,
            'indicators_written' => 0,
            'baselines' => 0,
            'anomalies' => 0,
            'insights' => 0,
            'blockers' => [],
        ];

        // --- Open-Meteo / climate ---
        $results['sources']['open_meteo'] = $this->ingestOpenMeteo($aid, $bbox, $city, $registry, $store);
        $results['sources']['open_meteo_archive'] = $this->ingestOpenMeteoArchive($aid, $bbox, $city, $registry, $store);

        // --- OSM Overpass ---
        $results['sources']['osm_overpass'] = $this->ingestOsm($aid, $bbox, $city, $registry, $store, $forceOsm);

        // --- Existing module adapters (real fetch; skip if reference-only) ---
        $results['sources']['cams_air'] = $this->ingestCams($aid, $bbox, $registry, $store);
        $results['sources']['clms_urban_atlas'] = $this->ingestClms($aid, $bbox, $registry, $store);
        $results['sources']['pvgis'] = $this->ingestPvgis($aid, $registry, $store);
        $results['sources']['gbif'] = $this->ingestGbif($aid, $registry, $store);
        $results['sources']['openchargemap'] = $this->ingestOcm($aid, $registry, $store);
        $results['sources']['copernicus_ndvi'] = $this->ingestCopernicus($aid, $bbox, $registry, $store);
        $results['sources']['citizen_reports'] = $this->ingestCitizen($aid, $city, $registry, $store);
        $results['sources']['local_trees'] = $this->ingestTrees($aid, $registry, $store);
        $results['sources']['urban_vision'] = $this->ingestVision($aid, $registry, $store);

        foreach ($results['sources'] as $src => $info) {
            if (!empty($info['inserted'])) {
                $results['observations_inserted'] += (int)$info['inserted'];
            }
            if (!empty($info['blocker'])) {
                $results['blockers'][] = ['source' => $src, 'reason' => $info['blocker']];
            }
        }

        $ind = new CityIndicatorEngine();
        $agg = $ind->recompute($aid, 30);
        $results['indicators_written'] = (int)$agg['written'];

        $base = new CityBaselineEngine();
        $results['baselines'] = $base->recompute($aid, 90);

        $anom = new CityAnomalyEngine();
        $ad = $anom->detect($aid, 20.0);
        $results['anomalies'] = (int)$ad['created'];

        $spatialEng = new CitySpatialEngine();
        $results['spatial_cells'] = $spatialEng->persistGridIndicators($aid, 3);
        $results['spatial'] = $spatialEng->analyze($aid, 3, 30);

        $insEng = new CityInsightEngine();
        $ig = $insEng->generate($aid);
        $results['insights'] = (int)$ig['created'];

        $results['priorities'] = count((new UnifiedPriorityEngine())->computeForAuthority($aid, 50));
        $results['discoveries'] = count((new CityDiscoveryEngine())->generate($aid));
        $results['change_intelligence'] = (new CityChangeIntelligence())->snapshot($aid);
        $results['zones'] = count((new CityZoneEngine())->ensureZones($aid));
        $results['situation'] = (new CitySituationEngine())->build($aid);
        $results['patterns'] = (new PatternCorrelationEngine())->analyze($aid);
        $results['health_v2'] = (new CityHealthScoreV2())->compute($aid);

        return $results;
    }

    /**
     * Dashboard payload for gov UI.
     * @return array<string,mixed>
     */
    public function dashboard(?int $authorityId): array
    {
        CityIntelSchema::ensure();
        $registry = new CityDataSourceRegistry();
        $ind = new CityIndicatorEngine();
        $anom = new CityAnomalyEngine();
        $ins = new CityInsightEngine();
        $trend = new CityTrendEngine();
        $spatial = (new CitySpatialEngine())->analyze($authorityId, 3, 30);
        $priorities = [];
        $discoveries = [];
        $changeIntel = [];
        if ($authorityId !== null && $authorityId > 0) {
            try {
                $priorities = (new UnifiedPriorityEngine())->computeForAuthority($authorityId, 20);
            } catch (Throwable $e) { /* optional */ }
            try {
                $discoveries = (new CityDiscoveryEngine())->generate($authorityId);
            } catch (Throwable $e) { /* optional */ }
            try {
                $changeIntel = (new CityChangeIntelligence())->snapshot($authorityId);
            } catch (Throwable $e) { /* optional */ }
        }
        $situation = [];
        $patterns = [];
        $healthV2 = [];
        if ($authorityId !== null && $authorityId > 0) {
            try {
                $situation = (new CitySituationEngine())->build($authorityId);
            } catch (Throwable $e) { /* optional */ }
            try {
                $patterns = (new PatternCorrelationEngine())->analyze($authorityId);
            } catch (Throwable $e) { /* optional */ }
            try {
                $healthV2 = (new CityHealthScoreV2())->compute($authorityId);
            } catch (Throwable $e) { /* optional */ }
        }

        $indicators = $ind->latest($authorityId, 40);
        $withTrend = [];
        foreach ($indicators as $row) {
            $t = $trend->compute($authorityId, (string)$row['indicator_key'], 8);
            $row['trend'] = $t['trend'];
            $row['pct_change'] = $t['pct_change'];
            $withTrend[] = $row;
        }

        $improving = array_values(array_filter($withTrend, fn($r) => ($r['trend'] ?? '') === 'improving'));
        $deteriorating = array_values(array_filter($withTrend, fn($r) => ($r['trend'] ?? '') === 'deteriorating'));

        $payload = [
            'freshness' => $registry->freshnessSummary(),
            'sources' => $registry->listAll(true),
            'indicators' => $withTrend,
            'improving' => array_slice($improving, 0, 8),
            'deteriorating' => array_slice($deteriorating, 0, 8),
            'anomalies' => $anom->listRecent($authorityId, 15),
            'insights' => $ins->listActive($authorityId, 15),
            'spatial' => $spatial,
            'priorities' => $priorities,
            'discoveries' => array_slice($discoveries, 0, 10),
            'change_intelligence' => $changeIntel,
            'situation' => $situation,
            'patterns' => $patterns,
            'health_v2' => $healthV2,
            'generated_at' => date('c'),
            'labels' => CityIntelLabels::dictionary(),
            'summary' => [],
            'actions' => [],
        ];
        $payload['summary'] = CityIntelLabels::buildSummary($payload);
        $payload['actions'] = CityIntelLabels::buildActions($payload);

        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function resolveAuthority(?int $authorityId): ?array
    {
        try {
            if ($authorityId !== null && $authorityId > 0) {
                $st = db()->prepare('SELECT id, city, name, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
                $st->execute([$authorityId]);
            } else {
                $st = db()->query('SELECT id, city, name, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE is_active = 1 AND min_lat IS NOT NULL ORDER BY id ASC LIMIT 1');
            }
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['min_lat'] === null || $row['max_lat'] === null || $row['min_lng'] === null || $row['max_lng'] === null) {
                return null;
            }
            return $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox */
    private function ingestOpenMeteo(int $aid, array $bbox, string $city, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('open_meteo');
        $lat = ($bbox['min_lat'] + $bbox['max_lat']) / 2;
        $lng = ($bbox['min_lng'] + $bbox['max_lng']) / 2;
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lng
            . '&current=temperature_2m,precipitation,relative_humidity_2m'
            . '&daily=temperature_2m_max,precipitation_sum&forecast_days=7&timezone=auto';
        require_once __DIR__ . '/../ExternalHttpClient.php';
        $resp = ExternalHttpClient::get($url, 20);
        if (!$resp['ok']) {
            $reg->markFailure('open_meteo', $resp['error'] ?? 'http_error');
            return ['ok' => false, 'inserted' => 0, 'blocker' => 'open_meteo_unreachable'];
        }
        $j = json_decode($resp['body'], true);
        if (!is_array($j) || !isset($j['current'])) {
            $reg->markFailure('open_meteo', 'invalid_json');
            return ['ok' => false, 'inserted' => 0, 'blocker' => 'open_meteo_invalid'];
        }
        $cur = $j['current'];
        $daily = $j['daily'] ?? [];
        $precipSum = 0.0;
        if (!empty($daily['precipitation_sum']) && is_array($daily['precipitation_sum'])) {
            $precipSum = (float)array_sum($daily['precipitation_sum']);
        }
        $temp = isset($cur['temperature_2m']) ? (float)$cur['temperature_2m'] : null;
        $now = date('Y-m-d H:i:s');
        $rows = [];
        if ($temp !== null) {
            $rows[] = $this->obs($aid, 'open_meteo', 'weather', 'climate.temp_c', $temp, 'celsius', $lat, $lng, $city, 0.9, 0.85, ['measured' => true]);
            $heat = ($temp >= 32) ? 80 : (($temp >= 28) ? 50 : 20);
            $rows[] = $this->obs($aid, 'open_meteo', 'weather', 'climate.heat_risk', (float)$heat, 'index', $lat, $lng, $city, 0.85, 0.8, ['measured' => true, 'derived_from' => 'temp_c']);
        }
        $rows[] = $this->obs($aid, 'open_meteo', 'weather', 'climate.precip_mm', $precipSum, 'mm_7d', $lat, $lng, $city, 0.9, 0.85, ['measured' => true]);
        $drought = $precipSum < 5 ? 65 : ($precipSum < 15 ? 35 : 15);
        $rows[] = $this->obs($aid, 'open_meteo', 'weather', 'climate.drought_index', (float)$drought, 'index', $lat, $lng, $city, 0.8, 0.75, ['measured' => true, 'derived_from' => 'precip_7d']);
        $stats = $store->upsertMany($rows);
        $reg->markSuccess('open_meteo', $stats['inserted'], 0.85);
        (new CityIndicatorEngine())->writeSnapshot($aid, 'climate.temp_c', $temp ?? 0, 'celsius', 'open_meteo_current', ['source' => 'open_meteo', 'measured' => true], 0.85, 0.9);
        return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
    }

    /**
     * Historical daily series for real baselines (Open-Meteo Archive API – measured ERA5-land based).
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox
     */
    private function ingestOpenMeteoArchive(int $aid, array $bbox, string $city, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $src = $reg->get('open_meteo');
        // Reuse registry key open_meteo for attempt log; separate marker via metadata
        $lat = ($bbox['min_lat'] + $bbox['max_lat']) / 2;
        $lng = ($bbox['min_lng'] + $bbox['max_lng']) / 2;
        $end = date('Y-m-d', strtotime('-1 day'));
        $start = date('Y-m-d', strtotime('-90 days'));
        $url = 'https://archive-api.open-meteo.com/v1/archive?latitude=' . $lat . '&longitude=' . $lng
            . '&start_date=' . $start . '&end_date=' . $end
            . '&daily=temperature_2m_mean,precipitation_sum&timezone=auto';
        require_once __DIR__ . '/../ExternalHttpClient.php';
        $resp = ExternalHttpClient::get($url, 35);
        if (!$resp['ok']) {
            return ['ok' => false, 'inserted' => 0, 'blocker' => 'open_meteo_archive_unreachable'];
        }
        $j = json_decode($resp['body'], true);
        $daily = is_array($j['daily'] ?? null) ? $j['daily'] : null;
        if (!$daily || empty($daily['time']) || !is_array($daily['time'])) {
            return ['ok' => false, 'inserted' => 0, 'blocker' => 'open_meteo_archive_invalid'];
        }
        $times = $daily['time'];
        $temps = $daily['temperature_2m_mean'] ?? [];
        $precips = $daily['precipitation_sum'] ?? [];
        $rows = [];
        $n = count($times);
        // Sample every 3rd day to keep volume reasonable, plus last 14 days every day
        for ($i = 0; $i < $n; $i++) {
            $day = (string)$times[$i];
            $age = (strtotime($end) - strtotime($day)) / 86400;
            if ($age > 14 && ($i % 3) !== 0) {
                continue;
            }
            if (isset($temps[$i]) && is_numeric($temps[$i])) {
                $t = (float)$temps[$i];
                $rows[] = [
                    'authority_id' => $aid,
                    'source_key' => 'open_meteo',
                    'source_type' => 'weather',
                    'external_id' => 'archive_temp_' . $day,
                    'category' => 'climate',
                    'indicator_type' => 'climate.temp_c',
                    'observed_at' => $day . ' 12:00:00',
                    'lat' => $lat,
                    'lng' => $lng,
                    'admin_area' => $city,
                    'value_num' => $t,
                    'unit' => 'celsius',
                    'confidence' => 0.88,
                    'quality' => 0.85,
                    'metadata' => ['measured' => true, 'archive' => true, 'api' => 'open-meteo-archive'],
                    'raw_ref' => 'open_meteo_archive:' . $day,
                ];
                $heat = ($t >= 32) ? 80 : (($t >= 28) ? 50 : 20);
                $rows[] = [
                    'authority_id' => $aid,
                    'source_key' => 'open_meteo',
                    'source_type' => 'weather',
                    'external_id' => 'archive_heat_' . $day,
                    'category' => 'climate',
                    'indicator_type' => 'climate.heat_risk',
                    'observed_at' => $day . ' 12:00:00',
                    'lat' => $lat,
                    'lng' => $lng,
                    'admin_area' => $city,
                    'value_num' => (float)$heat,
                    'unit' => 'index',
                    'confidence' => 0.8,
                    'quality' => 0.8,
                    'metadata' => ['measured' => true, 'archive' => true, 'derived_from' => 'temp_mean'],
                    'raw_ref' => 'open_meteo_archive:' . $day,
                ];
            }
            if (isset($precips[$i]) && is_numeric($precips[$i])) {
                $p = (float)$precips[$i];
                $rows[] = [
                    'authority_id' => $aid,
                    'source_key' => 'open_meteo',
                    'source_type' => 'weather',
                    'external_id' => 'archive_precip_' . $day,
                    'category' => 'climate',
                    'indicator_type' => 'climate.precip_daily_mm',
                    'observed_at' => $day . ' 12:00:00',
                    'lat' => $lat,
                    'lng' => $lng,
                    'admin_area' => $city,
                    'value_num' => $p,
                    'unit' => 'mm',
                    'confidence' => 0.88,
                    'quality' => 0.85,
                    'metadata' => ['measured' => true, 'archive' => true],
                    'raw_ref' => 'open_meteo_archive:' . $day,
                ];
            }
        }
        if (!$rows) {
            return ['ok' => false, 'inserted' => 0, 'blocker' => 'open_meteo_archive_empty'];
        }
        $stats = $store->upsertMany($rows);
        // Also write weekly precip rollups as indicator snapshots for baseline richness
        $ind = new CityIndicatorEngine();
        $recentPrecip = 0.0;
        $cntP = 0;
        for ($i = max(0, $n - 7); $i < $n; $i++) {
            if (isset($precips[$i]) && is_numeric($precips[$i])) {
                $recentPrecip += (float)$precips[$i];
                $cntP++;
            }
        }
        if ($cntP > 0) {
            $ind->writeSnapshot($aid, 'climate.precip_mm', $recentPrecip, 'mm_7d', 'open_meteo_archive_7d', [
                'measured' => true,
                'archive' => true,
                'days' => $cntP,
            ], 0.85, 0.88, $cntP);
        }
        return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null, 'archive_days' => $n];
    }

    /** @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox */
    private function ingestOsm(int $aid, array $bbox, string $city, CityDataSourceRegistry $reg, CityObservationStore $store, bool $force): array
    {
        $src = $reg->get('osm_overpass');
        if (!$force && $src && !empty($src['last_success_at']) && strtotime((string)$src['last_success_at']) > time() - 20 * 3600) {
            return ['ok' => true, 'inserted' => 0, 'blocker' => null, 'skipped' => 'fresh'];
        }
        $reg->markAttempt('osm_overpass');
        $ing = new OsmOverpassIngester();
        $res = $ing->fetchAggregates($aid, $bbox, $city !== '' ? $city : null);
        if (!$res['ok']) {
            $reg->markFailure('osm_overpass', $res['error'] ?? 'error');
            return ['ok' => false, 'inserted' => 0, 'blocker' => $res['error'] ?? 'overpass_failed'];
        }
        $stats = $store->upsertMany($res['observations']);
        $reg->markSuccess('osm_overpass', $stats['inserted'], 0.8);
        return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null, 'raw_counts' => $res['raw_counts']];
    }

    /** @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox */
    private function ingestCams(int $aid, array $bbox, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('cams_air');
        try {
            require_once __DIR__ . '/../CamsAirQualityService.php';
            $svc = new CamsAirQualityService();
            if (!$svc->isActive()) {
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'cams_disabled'];
            }
            $ctx = $svc->fetchForBBox($bbox);
            if (empty($ctx['ok'])) {
                $reg->markFailure('cams_air', (string)($ctx['error'] ?? 'cams_fail'));
                return ['ok' => false, 'inserted' => 0, 'blocker' => $ctx['error'] ?? 'cams_fail'];
            }
            $lat = isset($ctx['lat']) ? (float)$ctx['lat'] : null;
            $lng = isset($ctx['lng']) ? (float)$ctx['lng'] : null;
            $rows = [];
            if (isset($ctx['no2']) && is_numeric($ctx['no2'])) {
                $rows[] = $this->obs($aid, 'cams_air', 'environment', 'air.no2', (float)$ctx['no2'], 'ug_m3', $lat, $lng, null, 0.75, 0.75, ['measured' => true]);
            }
            if (isset($ctx['pm25']) && is_numeric($ctx['pm25'])) {
                $rows[] = $this->obs($aid, 'cams_air', 'environment', 'air.pm25', (float)$ctx['pm25'], 'ug_m3', $lat, $lng, null, 0.75, 0.75, ['measured' => true]);
            }
            if (isset($ctx['air_quality_index']) && is_numeric($ctx['air_quality_index'])) {
                $rows[] = $this->obs($aid, 'cams_air', 'environment', 'air.aqi', (float)$ctx['air_quality_index'], 'index', $lat, $lng, null, 0.7, 0.7, ['measured' => true]);
            }
            if (!$rows) {
                $reg->markFailure('cams_air', 'no_numeric_pollutant');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'cams_no_numeric_fields'];
            }
            $stats = $store->upsertMany($rows);
            $reg->markSuccess('cams_air', $stats['inserted'], 0.75);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('cams_air', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    /** @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox */
    private function ingestClms(int $aid, array $bbox, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('clms_urban_atlas');
        try {
            require_once __DIR__ . '/../ClmsUrbanAtlasService.php';
            $svc = new ClmsUrbanAtlasService();
            if (!$svc->isActive()) {
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'clms_disabled'];
            }
            $ctx = $svc->fetchSharesForBBox($bbox);
            if (empty($ctx['ok'])) {
                $reg->markFailure('clms_urban_atlas', (string)($ctx['error'] ?? 'clms_fail'));
                return ['ok' => false, 'inserted' => 0, 'blocker' => $ctx['error'] ?? 'clms_fail'];
            }
            $rows = [];
            foreach (['ua_green_urban_share' => 'land.green_urban_share', 'ua_built_share' => 'land.built_share', 'ua_pervious_green_share' => 'land.pervious_green_share'] as $srcKey => $ind) {
                if (isset($ctx[$srcKey]) && is_numeric($ctx[$srcKey])) {
                    $rows[] = $this->obs($aid, 'clms_urban_atlas', 'land_cover', $ind, (float)$ctx[$srcKey], 'share', null, null, null, 0.7, 0.7, ['measured' => true, 'reference_year' => $ctx['ua_reference_year'] ?? 2018]);
                }
            }
            $stats = $store->upsertMany($rows);
            $reg->markSuccess('clms_urban_atlas', $stats['inserted'], 0.7);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('clms_urban_atlas', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    private function ingestPvgis(int $aid, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('pvgis');
        try {
            require_once __DIR__ . '/../intelligence/PvgisDataService.php';
            $svc = new PvgisDataService();
            if (method_exists($svc, 'isActive') && !$svc->isActive()) {
                $reg->markFailure('pvgis', 'module_disabled');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'pvgis_disabled'];
            }
            $ctx = $svc->fetchContext($aid);
            $notes = $ctx['notes'] ?? [];
            if (!empty($ctx['ok']) && is_array($notes) && (in_array('using_reference', $notes, true) || in_array('lite_fetch', $notes, true))) {
                $reg->markFailure('pvgis', 'reference_data_not_ingested');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'pvgis_reference_only_not_ingested'];
            }
            $val = $ctx['annual_kwh'] ?? null;
            if ($val === null || !is_numeric($val)) {
                $reg->markFailure('pvgis', 'no_value');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'pvgis_no_numeric'];
            }
            $stats = $store->upsertMany([
                $this->obs($aid, 'pvgis', 'energy', 'energy.pv_yield', (float)$val, 'kwh_kwp_y', null, null, null, 0.8, 0.8, ['measured' => true, 'source' => $ctx['source'] ?? 'pvgis']),
            ]);
            $reg->markSuccess('pvgis', $stats['inserted'], 0.8);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('pvgis', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    private function ingestGbif(int $aid, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('gbif');
        try {
            require_once __DIR__ . '/../intelligence/GbifDataService.php';
            $svc = new GbifDataService();
            if (method_exists($svc, 'isActive') && !$svc->isActive()) {
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'gbif_disabled'];
            }
            $ctx = $svc->fetchContext($aid);
            $notes = $ctx['notes'] ?? [];
            if (!empty($notes) && (in_array('using_reference', $notes, true) || in_array('lite_fetch', $notes, true))) {
                $reg->markFailure('gbif', 'reference_not_ingested');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'gbif_reference_only_not_ingested'];
            }
            $count = $ctx['occurrence_count'] ?? $ctx['count'] ?? null;
            if ($count === null || !is_numeric($count)) {
                $reg->markFailure('gbif', 'no_count');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'gbif_no_count'];
            }
            $stats = $store->upsertMany([
                $this->obs($aid, 'gbif', 'biodiversity', 'bio.occurrence_count', (float)$count, 'count', null, null, null, 0.7, 0.7, ['measured' => true]),
            ]);
            $reg->markSuccess('gbif', $stats['inserted'], 0.7);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('gbif', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    private function ingestOcm(int $aid, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('openchargemap');
        try {
            require_once __DIR__ . '/../intelligence/OpenChargeMapDataService.php';
            $svc = new OpenChargeMapDataService();
            if (method_exists($svc, 'isActive') && !$svc->isActive()) {
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'ocm_disabled'];
            }
            $ctx = $svc->fetchContext($aid);
            $notes = $ctx['notes'] ?? [];
            if (!empty($notes) && (in_array('using_reference', $notes, true) || in_array('lite_fetch', $notes, true))) {
                $reg->markFailure('openchargemap', 'reference_not_ingested');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'ocm_reference_only_not_ingested'];
            }
            $count = $ctx['charger_count'] ?? null;
            if ($count === null || !is_numeric($count)) {
                $reg->markFailure('openchargemap', 'no_count');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'ocm_no_count'];
            }
            $stats = $store->upsertMany([
                $this->obs($aid, 'openchargemap', 'mobility', 'mobility.ev_stations', (float)$count, 'count', null, null, null, 0.75, 0.75, ['measured' => true]),
            ]);
            $reg->markSuccess('openchargemap', $stats['inserted'], 0.75);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('openchargemap', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    /** @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} $bbox */
    private function ingestCopernicus(int $aid, array $bbox, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('copernicus_ndvi');
        try {
            require_once __DIR__ . '/../CopernicusDataService.php';
            $svc = new CopernicusDataService();
            if (!$svc->isActive()) {
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'copernicus_disabled'];
            }
            $ctx = $svc->fetchNdviMeanForBBox($bbox, 90);
            if (empty($ctx['ok']) || !isset($ctx['mean']) || !is_numeric($ctx['mean'])) {
                $reg->markFailure('copernicus_ndvi', (string)($ctx['error'] ?? 'no_ndvi'));
                return ['ok' => false, 'inserted' => 0, 'blocker' => $ctx['error'] ?? 'copernicus_no_live_ndvi_or_credentials'];
            }
            $stats = $store->upsertMany([
                $this->obs($aid, 'copernicus_ndvi', 'vegetation', 'green.ndvi', (float)$ctx['mean'], 'ndvi', null, null, null, 0.9, 0.9, [
                    'measured' => true,
                    'satellite' => true,
                    'source' => $ctx['source'] ?? 'sentinelhub_statistics',
                    'cached' => !empty($ctx['cached']),
                ]),
            ]);
            $reg->markSuccess('copernicus_ndvi', $stats['inserted'], 0.9);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('copernicus_ndvi', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    private function ingestCitizen(int $aid, string $city, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('citizen_reports');
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM reports WHERE authority_id = ? AND status NOT IN ('solved','closed')");
            $st->execute([$aid]);
            $open = (int)$st->fetchColumn();
            $st2 = db()->prepare("SELECT COUNT(*) FROM reports WHERE authority_id = ? AND category = 'green' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $st2->execute([$aid]);
            $green7 = (int)$st2->fetchColumn();
            $stats = $store->upsertMany([
                $this->obs($aid, 'citizen_reports', 'citizen', 'citizen.open_reports', (float)$open, 'count', null, null, $city, 0.65, 0.65, ['measured' => true]),
                $this->obs($aid, 'citizen_reports', 'citizen', 'citizen.green_reports_7d', (float)$green7, 'count', null, null, $city, 0.65, 0.65, ['measured' => true]),
            ]);
            $reg->markSuccess('citizen_reports', $stats['inserted'], 0.65);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('citizen_reports', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    private function ingestTrees(int $aid, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('local_trees');
        try {
            require_once __DIR__ . '/../GreenIntelligence.php';
            $g = (new GreenIntelligence())->compute($aid);
            $rows = [];
            if (isset($g['drought_risk']) && is_numeric($g['drought_risk'])) {
                $rows[] = $this->obs($aid, 'local_trees', 'green', 'green.drought_risk', (float)$g['drought_risk'], 'ratio', null, null, null, 0.8, 0.85, ['measured' => true, 'from' => 'GreenIntelligence']);
            }
            if (isset($g['ndvi_raw']) && is_numeric($g['ndvi_raw']) && !empty($g['satellite_ndvi_ok'])) {
                $rows[] = $this->obs($aid, 'local_trees', 'green', 'green.ndvi', (float)$g['ndvi_raw'], 'ndvi', null, null, null, 0.9, 0.9, ['measured' => true, 'satellite' => true]);
            } elseif (isset($g['ndvi_score']) && is_numeric($g['ndvi_score'])) {
                $rows[] = $this->obs($aid, 'local_trees', 'green', 'green.canopy_proxy', (float)$g['ndvi_score'], 'score', null, null, null, 0.7, 0.75, ['measured' => true, 'proxy' => true, 'note' => 'tree_based_proxy_not_satellite']);
            }
            $needWater = 0;
            try {
                $st = db()->prepare('SELECT COUNT(*) FROM trees WHERE authority_id = ?');
                $st->execute([$aid]);
                // optional watering need if column exists – best effort via GreenIntelligence env elsewhere
            } catch (Throwable $e) {
            }
            if (isset($g['trees_needing_water']) && is_numeric($g['trees_needing_water'])) {
                $needWater = (int)$g['trees_needing_water'];
                $rows[] = $this->obs($aid, 'local_trees', 'green', 'trees.needing_water', (float)$needWater, 'count', null, null, null, 0.8, 0.85, ['measured' => true]);
            }
            if (!$rows) {
                $reg->markFailure('local_trees', 'no_metrics');
                return ['ok' => false, 'inserted' => 0, 'blocker' => 'trees_no_metrics'];
            }
            $stats = $store->upsertMany($rows);
            $reg->markSuccess('local_trees', $stats['inserted'], 0.85);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('local_trees', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => $e->getMessage()];
        }
    }

    private function ingestVision(int $aid, CityDataSourceRegistry $reg, CityObservationStore $store): array
    {
        $reg->markAttempt('urban_vision');
        try {
            $st = db()->prepare("
                SELECT COUNT(*) FROM urban_observations
                WHERE authority_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND (vegetation_pct IS NOT NULL OR severity IN ('high','medium'))
            ");
            $st->execute([$aid]);
            $n = (int)$st->fetchColumn();
            $stats = $store->upsertMany([
                $this->obs($aid, 'urban_vision', 'vision', 'vision.vegetation_stress_signals', (float)$n, 'count', null, null, null, 0.6, 0.6, ['measured' => true, 'window_days' => 30]),
            ]);
            $reg->markSuccess('urban_vision', $stats['inserted'], 0.6);
            return ['ok' => true, 'inserted' => $stats['inserted'], 'blocker' => null];
        } catch (Throwable $e) {
            $reg->markFailure('urban_vision', $e->getMessage());
            return ['ok' => false, 'inserted' => 0, 'blocker' => 'urban_observations_missing'];
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function obs(
        int $aid,
        string $source,
        string $sourceType,
        string $indicator,
        float $value,
        string $unit,
        ?float $lat,
        ?float $lng,
        ?string $admin,
        float $confidence,
        float $quality,
        array $meta
    ): array {
        return [
            'authority_id' => $aid,
            'source_key' => $source,
            'source_type' => $sourceType,
            'external_id' => $indicator . '@' . date('Y-m-d-H'),
            'category' => explode('.', $indicator)[0] ?? 'general',
            'indicator_type' => $indicator,
            'observed_at' => date('Y-m-d H:i:s'),
            'lat' => $lat,
            'lng' => $lng,
            'admin_area' => $admin,
            'value_num' => $value,
            'unit' => $unit,
            'confidence' => $confidence,
            'quality' => $quality,
            'metadata' => $meta,
            'raw_ref' => $source . ':' . date('c'),
        ];
    }
}
