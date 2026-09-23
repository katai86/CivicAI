<?php
/**
 * City insights from measured indicators / anomalies + optional cross-signals (citizen/vision).
 * Separates MEASURED FACT vs AI INTERPRETATION (interpretation only if LLM available; else template).
 */
require_once __DIR__ . '/CityIntelSchema.php';
require_once __DIR__ . '/CityIndicatorEngines.php';
require_once __DIR__ . '/CityIntelLabels.php';
require_once __DIR__ . '/../../db.php';

final class CityInsightEngine
{
    private const LABELS = [
        'climate.temp_c' => 'hőmérséklet',
        'climate.heat_risk' => 'hőstressz',
        'climate.drought_index' => 'aszályindex',
        'climate.precip_mm' => 'csapadék',
        'green.ndvi' => 'NDVI',
        'green.drought_risk' => 'zöld aszálykockázat',
        'green.canopy_proxy' => 'lombkorona proxy',
        'osm.green_feature_density_per_km2' => 'zöld OSM sűrűség',
        'osm.building_density_per_km2' => 'beépítettség (OSM)',
        'osm.amenity_density_per_km2' => 'közintézmény-sűrűség',
        'osm.cycleways' => 'kerékpárutak (OSM)',
        'osm.ev_chargers' => 'EV töltők (OSM)',
        'air.no2' => 'NO₂',
        'energy.pv_yield' => 'PV potenciál',
        'bio.occurrence_count' => 'GBIF előfordulások',
        'citizen.open_reports' => 'nyitott lakossági ügyek',
        'citizen.green_reports_7d' => 'zöld témájú bejelentések (7 nap)',
        'vision.vegetation_stress_signals' => 'vision vegetációs jelek',
        'trees.needing_water' => 'öntözendő fák',
    ];

    /**
     * @return array{created:int,insights:list<array<string,mixed>>}
     */
    public function generate(?int $authorityId): array
    {
        if (!CityIntelSchema::ensure()) {
            return ['created' => 0, 'insights' => []];
        }
        $anom = new CityAnomalyEngine();
        $trend = new CityTrendEngine();
        $indEng = new CityIndicatorEngine();
        $anomalies = $anom->listRecent($authorityId, 15);
        $created = 0;
        $insights = [];

        $cross = $this->collectCrossSignals($authorityId);
        $signalBoost = $this->crossSignalBoost($cross);

        foreach ($anomalies as $a) {
            $key = (string)$a['indicator_key'];
            $label = self::LABELS[$key] ?? $key;
            $pct = isset($a['pct_deviation']) ? (float)$a['pct_deviation'] : null;
            $cur = (float)$a['current_value'];
            $base = $a['baseline_value'] !== null ? (float)$a['baseline_value'] : null;
            $tr = $trend->compute($authorityId ?? ($a['authority_id'] !== null ? (int)$a['authority_id'] : null), $key, 8);

            $fact = sprintf(
                'MEASURED: A(z) %s jelenlegi értéke %.3f%s; a %.0f napos baseline %.3f%s; eltérés %s%%.',
                $label,
                $cur,
                $a['unit'] ?? '',
                90,
                $base ?? 0.0,
                $a['unit'] ?? '',
                $pct !== null ? sprintf('%+.1f', $pct) : 'n/a'
            );

            $related = $this->relatedSignalsForIndicator($key, $cross);
            $confidence = isset($a['confidence']) ? (float)$a['confidence'] : 0.7;
            $confidence = min(0.97, $confidence + $signalBoost + (float)($related['boost'] ?? 0));

            $interpretation = $this->templateInterpretation($label, $pct, $tr['trend'], $related);
            $evidence = [
                'layer' => 'evidence_chain',
                'measured_fact' => true,
                'anomaly_id' => (int)$a['id'],
                'indicator_key' => $key,
                'current_value' => $cur,
                'baseline_value' => $base,
                'pct_deviation' => $pct,
                'trend' => $tr,
                'anomaly_evidence' => json_decode((string)($a['evidence_json'] ?? '{}'), true) ?: [],
                'cross_signals' => $related,
                'cross_signal_summary' => $cross,
                'calculation' => '(current - baseline) / |baseline| * 100',
                'confidence_components' => [
                    'base' => isset($a['confidence']) ? (float)$a['confidence'] : 0.7,
                    'global_cross_boost' => $signalBoost,
                    'related_boost' => (float)($related['boost'] ?? 0),
                ],
            ];

            $insightKey = 'anom_' . $key;
            $id = $this->upsertInsight([
                'authority_id' => $a['authority_id'] !== null ? (int)$a['authority_id'] : $authorityId,
                'insight_key' => $insightKey,
                'title' => 'Anomália: ' . $label,
                'fact_text' => $fact,
                'interpretation_text' => $interpretation,
                'severity' => (string)($a['severity'] ?? 'medium'),
                'confidence' => $confidence,
                'trend' => $tr['trend'],
                'indicator_key' => $key,
                'affected_area' => !empty($related['hot_zone']) ? (string)$related['hot_zone'] : 'city',
                'cross_signals_json' => json_encode($related, JSON_UNESCAPED_UNICODE),
                'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            ]);
            if ($id) {
                $created++;
                $insights[] = ['id' => $id, 'insight_key' => $insightKey, 'title' => 'Anomália: ' . $label, 'severity' => $a['severity']];
            }
        }

        // Explicit cross-signal insight when citizen + environmental/vision align
        if ((int)($cross['green_reports']['count'] ?? 0) >= 2
            && ((int)($cross['vision']['count'] ?? 0) >= 1 || (int)($cross['drought_obs']['count'] ?? 0) >= 1)) {
            $fact = sprintf(
                'MEASURED CROSS-SIGNAL: %d zöld/szemét bejelentés (14 nap) + %d vision megfigyelés + %d aszály/NDVI observation egyezik időben.',
                (int)$cross['green_reports']['count'],
                (int)$cross['vision']['count'],
                (int)$cross['drought_obs']['count']
            );
            $id = $this->upsertInsight([
                'authority_id' => $authorityId,
                'insight_key' => 'cross_green_stress',
                'title' => 'Cross-signal: zöld stressz',
                'fact_text' => $fact,
                'interpretation_text' => 'AI INTERPRETATION: Több független jel (lakosság + vision/open data) ugyanarra a környezeti nyomásra utal; az insight confidence emelt.',
                'severity' => 'high',
                'confidence' => min(0.95, 0.7 + $signalBoost),
                'trend' => 'deteriorating',
                'indicator_key' => 'green.drought_risk',
                'affected_area' => (string)($cross['hot_zone'] ?? 'city'),
                'cross_signals_json' => json_encode($cross, JSON_UNESCAPED_UNICODE),
                'evidence_json' => json_encode(['measured_fact' => true, 'cross_signals' => $cross], JSON_UNESCAPED_UNICODE),
            ]);
            if ($id) {
                $created++;
                $insights[] = ['id' => $id, 'insight_key' => 'cross_green_stress', 'title' => 'Cross-signal: zöld stressz', 'severity' => 'high'];
            }
        }

        // Spatial hotspot insight
        try {
            require_once __DIR__ . '/CitySpatialEngine.php';
            $spatial = (new CitySpatialEngine())->analyze($authorityId, 3, 30);
            if (!empty($spatial['hotspots'][0])) {
                $hs = $spatial['hotspots'][0];
                $fact = 'MEASURED: Legforgalmasabb térbeli hotspot: ' . ($hs['label'] ?? $hs['spatial_key'])
                    . ' (score=' . (int)($hs['score'] ?? 0) . ', type=' . ($hs['type'] ?? '') . ').';
                $id = $this->upsertInsight([
                    'authority_id' => $authorityId,
                    'insight_key' => 'spatial_hotspot',
                    'title' => 'Hol: térbeli hotspot',
                    'fact_text' => $fact,
                    'interpretation_text' => 'AI INTERPRETATION: A probléma nem csak városszintű — a megfigyelések/bejelentések ebben a cellában/zónában sűrűsödnek.',
                    'severity' => ((int)($hs['score'] ?? 0) >= 5) ? 'medium' : 'info',
                    'confidence' => 0.8,
                    'trend' => null,
                    'indicator_key' => 'spatial.observation_density',
                    'affected_area' => (string)($hs['label'] ?? $hs['spatial_key']),
                    'cross_signals_json' => json_encode(['hotspot' => $hs, 'cross' => $cross], JSON_UNESCAPED_UNICODE),
                    'evidence_json' => json_encode(['measured_fact' => true, 'spatial' => $spatial], JSON_UNESCAPED_UNICODE),
                ]);
                if ($id) {
                    $created++;
                    $insights[] = ['id' => $id, 'insight_key' => 'spatial_hotspot', 'title' => 'Hol: térbeli hotspot', 'severity' => 'medium'];
                }
            }
        } catch (Throwable $e) {
        }

        // Always emit a freshness / status insight from latest indicators if none
        $latest = $indEng->latest($authorityId, 12);
        if ($latest && $created === 0) {
            $bits = [];
            foreach (array_slice($latest, 0, 5) as $row) {
                $lk = (string)$row['indicator_key'];
                $bits[] = (self::LABELS[$lk] ?? $lk) . '=' . round((float)$row['value_num'], 2);
            }
            $fact = 'MEASURED: Aktuális városi indikátorok: ' . implode('; ', $bits) . '.';
            $id = $this->upsertInsight([
                'authority_id' => $authorityId,
                'insight_key' => 'status_snapshot',
                'title' => 'Városi állapot pillanatkép',
                'fact_text' => $fact,
                'interpretation_text' => 'AI INTERPRETATION: A mért indikátorok alapján nincs a küszöböt meghaladó anomália; a helyzet stabilnak tekinthető a rendelkezésre álló adatok szerint.',
                'severity' => 'info',
                'confidence' => 0.75,
                'trend' => 'stable',
                'indicator_key' => null,
                'affected_area' => 'city',
                'cross_signals_json' => json_encode($cross, JSON_UNESCAPED_UNICODE),
                'evidence_json' => json_encode(['measured_fact' => true, 'indicators' => $latest, 'cross_signals' => $cross], JSON_UNESCAPED_UNICODE),
            ]);
            if ($id) {
                $created++;
                $insights[] = ['id' => $id, 'insight_key' => 'status_snapshot', 'title' => 'Városi állapot pillanatkép', 'severity' => 'info'];
            }
        }

        return ['created' => $created, 'insights' => $insights];
    }

    /** @param array<string,mixed> $data */
    private function upsertInsight(array $data): ?int
    {
        try {
            // deactivate previous same key
            $aid = $data['authority_id'] ?? null;
            $key = (string)$data['insight_key'];
            if ($aid) {
                db()->prepare('UPDATE city_insights SET status = \'superseded\' WHERE authority_id = ? AND insight_key = ? AND status = \'active\'')
                    ->execute([(int)$aid, $key]);
            } else {
                db()->prepare('UPDATE city_insights SET status = \'superseded\' WHERE authority_id IS NULL AND insight_key = ? AND status = \'active\'')
                    ->execute([$key]);
            }
            $st = db()->prepare('
                INSERT INTO city_insights
                  (authority_id, insight_key, title, fact_text, interpretation_text, severity, confidence,
                   trend, indicator_key, spatial_key, affected_area, period_start, period_end,
                   cross_signals_json, evidence_json, status)
                VALUES
                  (:authority_id, :insight_key, :title, :fact_text, :interpretation_text, :severity, :confidence,
                   :trend, :indicator_key, \'city\', :affected_area, NULL, NOW(),
                   :cross_signals_json, :evidence_json, \'active\')
            ');
            $st->execute([
                ':authority_id' => $aid,
                ':insight_key' => mb_substr($key, 0, 96),
                ':title' => mb_substr((string)$data['title'], 0, 255),
                ':fact_text' => (string)$data['fact_text'],
                ':interpretation_text' => $data['interpretation_text'] ?? null,
                ':severity' => $data['severity'] ?? 'info',
                ':confidence' => $data['confidence'] ?? null,
                ':trend' => $data['trend'] ?? null,
                ':indicator_key' => $data['indicator_key'] ?? null,
                ':affected_area' => $data['affected_area'] ?? 'city',
                ':cross_signals_json' => $data['cross_signals_json'] ?? null,
                ':evidence_json' => $data['evidence_json'] ?? null,
            ]);
            return (int)db()->lastInsertId() ?: null;
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('CityInsightEngine::upsertInsight: ' . $e->getMessage());
            }
            return null;
        }
    }

    /** @param array<string,mixed> $related */
    private function templateInterpretation(string $label, ?float $pct, string $trend, array $related): string
    {
        $dir = ($pct !== null && $pct < 0) ? 'elmarad a baseline-tól' : 'meghaladja a baseline-t';
        $msg = "AI INTERPRETATION: A mért {$label} {$dir}";
        if ($pct !== null) {
            $msg .= sprintf(' (%.1f%%).', $pct);
        } else {
            $msg .= '.';
        }
        $msg .= ' Trend: ' . (CityIntelLabels::trendLabel($trend) ?? $trend) . '.';
        $parts = [];
        if (!empty($related['citizen_count'])) {
            $parts[] = (int)$related['citizen_count'] . ' lakossági jel';
        }
        if (!empty($related['vision_count'])) {
            $parts[] = (int)$related['vision_count'] . ' vision jel';
        }
        if (!empty($related['open_data_count'])) {
            $parts[] = (int)$related['open_data_count'] . ' open-data jel';
        }
        if ($parts) {
            $msg .= ' Cross-signal: ' . implode(' + ', $parts) . ' erősíti a figyelmeztetést.';
        }
        if (!empty($related['hot_zone'])) {
            $msg .= ' Érintett zóna: ' . $related['hot_zone'] . '.';
        }
        return $msg;
    }

    /** @return array<string,mixed> */
    private function collectCrossSignals(?int $authorityId): array
    {
        $out = [
            'green_reports' => ['count' => 0, 'ids' => []],
            'vision' => ['count' => 0],
            'drought_obs' => ['count' => 0],
            'hot_zone' => null,
            'sources_active' => 0,
        ];
        try {
            $where = "created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND category IN ('green','trash')";
            $params = [];
            if ($authorityId !== null && $authorityId > 0) {
                $where .= ' AND authority_id = ?';
                $params[] = $authorityId;
            }
            $st = db()->prepare("SELECT id, suburb, city FROM reports WHERE $where ORDER BY id DESC LIMIT 30");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ids = [];
            $zoneCounts = [];
            foreach ($rows as $r) {
                $ids[] = (int)$r['id'];
                $z = trim((string)($r['suburb'] ?? '')) ?: trim((string)($r['city'] ?? ''));
                if ($z !== '') {
                    $zoneCounts[$z] = ($zoneCounts[$z] ?? 0) + 1;
                }
            }
            $out['green_reports'] = ['count' => count($ids), 'ids' => $ids];
            if ($zoneCounts) {
                arsort($zoneCounts);
                $out['hot_zone'] = (string)array_key_first($zoneCounts);
            }
        } catch (Throwable $e) {
        }
        try {
            $where = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $params = [];
            if ($authorityId !== null && $authorityId > 0) {
                $where .= ' AND authority_id = ?';
                $params[] = $authorityId;
            }
            $st = db()->prepare("SELECT COUNT(*) FROM urban_observations WHERE $where");
            $st->execute($params);
            $out['vision'] = ['count' => (int)$st->fetchColumn()];
        } catch (Throwable $e) {
        }
        try {
            $where = "observed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND indicator_type IN ('green.drought_risk','green.ndvi','climate.drought_index','vision.vegetation_stress_signals')";
            $params = [];
            if ($authorityId !== null && $authorityId > 0) {
                $where .= ' AND authority_id = ?';
                $params[] = $authorityId;
            }
            $st = db()->prepare("SELECT COUNT(*) FROM city_observations WHERE $where");
            $st->execute($params);
            $out['drought_obs'] = ['count' => (int)$st->fetchColumn()];
        } catch (Throwable $e) {
        }
        $active = 0;
        if ((int)$out['green_reports']['count'] > 0) {
            $active++;
        }
        if ((int)$out['vision']['count'] > 0) {
            $active++;
        }
        if ((int)$out['drought_obs']['count'] > 0) {
            $active++;
        }
        $out['sources_active'] = $active;
        return $out;
    }

    /** @param array<string,mixed> $cross */
    private function crossSignalBoost(array $cross): float
    {
        $n = (int)($cross['sources_active'] ?? 0);
        if ($n >= 3) {
            return 0.18;
        }
        if ($n === 2) {
            return 0.10;
        }
        if ($n === 1) {
            return 0.04;
        }
        return 0.0;
    }

    /**
     * @param array<string,mixed> $cross
     * @return array<string,mixed>
     */
    private function relatedSignalsForIndicator(string $key, array $cross): array
    {
        $greenish = str_starts_with($key, 'green.') || str_contains($key, 'drought') || str_contains($key, 'ndvi')
            || str_contains($key, 'trees') || str_starts_with($key, 'vision.');
        $citizen = (int)($cross['green_reports']['count'] ?? 0);
        $vision = (int)($cross['vision']['count'] ?? 0);
        $od = (int)($cross['drought_obs']['count'] ?? 0);
        $boost = 0.0;
        if ($greenish) {
            if ($citizen > 0) {
                $boost += 0.08;
            }
            if ($vision > 0) {
                $boost += 0.06;
            }
            if ($od > 0) {
                $boost += 0.05;
            }
        }
        return [
            'citizen_count' => $greenish ? $citizen : 0,
            'vision_count' => $greenish ? $vision : 0,
            'open_data_count' => $greenish ? $od : 0,
            'hot_zone' => $cross['hot_zone'] ?? null,
            'boost' => $boost,
            'ids' => $greenish ? ($cross['green_reports']['ids'] ?? []) : [],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listActive(?int $authorityId, int $limit = 20): array
    {
        if (!CityIntelSchema::ensure()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            if ($authorityId !== null && $authorityId > 0) {
                $st = db()->prepare("SELECT * FROM city_insights WHERE status = 'active' AND authority_id = ? ORDER BY FIELD(severity,'high','medium','low','info'), created_at DESC LIMIT $limit");
                $st->execute([$authorityId]);
            } else {
                $st = db()->query("SELECT * FROM city_insights WHERE status = 'active' ORDER BY FIELD(severity,'high','medium','low','info'), created_at DESC LIMIT $limit");
            }
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getById(int $id): ?array
    {
        if (!CityIntelSchema::ensure() || $id <= 0) {
            return null;
        }
        try {
            $st = db()->prepare('SELECT * FROM city_insights WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
