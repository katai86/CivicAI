<?php
/**
 * M6 – Green Intelligence Module.
 * canopy_coverage, carbon_absorption, biodiversity_index, drought_risk.
 * M2 EU: opcionális Copernicus/STAC + helyi rács (ndvi_score, green_deficit_score, …) ha be van kapcsolva az EU modul.
 * M3 EU: opcionális CLMS Urban Atlas 2018 (terület-súlyozott megoszlás a bbox-ban) ha `clms_enabled`.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

class GreenIntelligence
{
    /** Default canopy radius in metres when canopy_diameter is missing */
    private const DEFAULT_CANOPY_RADIUS_M = 3.0;
    /** kg CO2 absorbed per m² canopy per year (rough estimate) */
    private const CO2_KG_PER_M2_YEAR = 0.5;

    /**
     * @param int|null $authorityId null = admin: összes hatóság fa-scope; >0 = adott hatóság (gov_trees_scope)
     */
    public function compute(?int $authorityId = null): array
    {
        $out = [
            'canopy_coverage' => 0.0,
            'carbon_absorption' => 0.0,
            'biodiversity_index' => 0.0,
            'drought_risk' => 0.0,
        ];

        $pdo = db();

        $scopeAuthIds = [];
        if ($authorityId !== null && $authorityId > 0) {
            $scopeAuthIds = [(int) $authorityId];
        } else {
            try {
                $raw = $pdo->query('SELECT id FROM authorities ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
                $scopeAuthIds = array_values(array_filter(array_map('intval', $raw ?: []), static fn ($x) => $x > 0));
            } catch (Throwable $e) {
                $scopeAuthIds = [];
            }
        }
        if (empty($scopeAuthIds)) {
            return $out;
        }

        [$treeScopeWhere, $treeScopeParams] = gov_trees_scope_where_sql($pdo, $scopeAuthIds, 't');
        $bbox = $this->mergedAuthorityBbox($pdo, $scopeAuthIds);

        try {
            $stmt = $pdo->prepare("SELECT t.id, t.species, t.canopy_diameter, t.trunk_diameter, t.last_watered FROM trees t WHERE t.public_visible = 1 AND t.lat IS NOT NULL AND t.lng IS NOT NULL AND ($treeScopeWhere)");
            $stmt->execute($treeScopeParams);
            $trees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out;
        }

        $totalTrees = count($trees);
        if ($totalTrees === 0) {
            return $out;
        }

        $totalCanopyM2 = 0;
        $speciesSet = [];
        $droughtCount = 0;
        $speciesIntervals = $this->loadSpeciesWateringIntervals($pdo);

        foreach ($trees as $t) {
            $radiusM = self::DEFAULT_CANOPY_RADIUS_M;
            if (!empty($t['canopy_diameter']) && (float)$t['canopy_diameter'] > 0) {
                $radiusM = (float)$t['canopy_diameter'] / 2.0;
            }
            $totalCanopyM2 += M_PI * $radiusM * $radiusM;

            $species = trim((string)($t['species'] ?? ''));
            if ($species !== '') {
                $speciesSet[$species] = true;
            }

            $lastWatered = $t['last_watered'] ?? null;
            $intervalDays = isset($speciesIntervals[$species]) ? $speciesIntervals[$species] : 14;
            if ($lastWatered === null || $lastWatered === '') {
                $droughtCount++;
            } else {
                $daysSince = (time() - strtotime($lastWatered)) / 86400;
                if ($daysSince > $intervalDays) {
                    $droughtCount++;
                }
            }
        }

        $out['carbon_absorption'] = round(($totalCanopyM2 * self::CO2_KG_PER_M2_YEAR) / 1000, 1);

        $referenceAreaM2 = 1e6;
        if ($bbox) {
            $avgLat = ($bbox['min_lat'] + $bbox['max_lat']) / 2;
            $latM = 111320 * 1000 * cos($avgLat * M_PI / 180);
            $lngM = 111320 * 1000 * cos($avgLat * M_PI / 180);
            $referenceAreaM2 = max(1000, ($bbox['max_lat'] - $bbox['min_lat']) * $latM * ($bbox['max_lng'] - $bbox['min_lng']) * $lngM);
        }
        $out['canopy_coverage'] = round(min(1.0, $totalCanopyM2 / $referenceAreaM2), 2);

        $speciesCount = count($speciesSet);
        $out['biodiversity_index'] = round(min(1.0, $speciesCount / 15.0), 2);

        $out['drought_risk'] = round($droughtCount / $totalTrees, 2);

        if (function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()) {
            require_once __DIR__ . '/CopernicusDataService.php';
            $cop = new CopernicusDataService();
            if ($cop->isActive()) {
                $extra = $cop->augmentGreenMetrics($out, $authorityId, $bbox, $pdo);
                if (!empty($extra)) {
                    $out = array_merge($out, $extra);
                }
            }
            require_once __DIR__ . '/ClmsUrbanAtlasService.php';
            $clms = new ClmsUrbanAtlasService();
            if ($clms->isActive() && $bbox) {
                $uaExtra = $clms->augmentMetrics($out, $bbox);
                if (!empty($uaExtra)) {
                    $out = array_merge($out, $uaExtra);
                }
            }
        }

        return $out;
    }

    /** @param int[] $authorityIds */
    private function mergedAuthorityBbox(PDO $pdo, array $authorityIds): ?array
    {
        if (empty($authorityIds)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ?');
            $minLa = null;
            $maxLa = null;
            $minL = null;
            $maxL = null;
            foreach ($authorityIds as $id) {
                $stmt->execute([(int) $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row || $row['min_lat'] === null || $row['max_lat'] === null || $row['min_lng'] === null || $row['max_lng'] === null) {
                    continue;
                }
                $a = (float) $row['min_lat'];
                $b = (float) $row['max_lat'];
                $c = (float) $row['min_lng'];
                $d = (float) $row['max_lng'];
                $minLa = $minLa === null ? $a : min($minLa, $a);
                $maxLa = $maxLa === null ? $b : max($maxLa, $b);
                $minL = $minL === null ? $c : min($minL, $c);
                $maxL = $maxL === null ? $d : max($maxL, $d);
            }
            if ($minLa === null) {
                return null;
            }

            return [
                'min_lat' => $minLa,
                'max_lat' => $maxLa,
                'min_lng' => $minL,
                'max_lng' => $maxL,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function loadSpeciesWateringIntervals(PDO $pdo): array
    {
        $out = [];
        try {
            $stmt = $pdo->query("SELECT species_name, watering_interval_days FROM tree_species_care");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name = trim((string)($row['species_name'] ?? ''));
                if ($name !== '') {
                    $out[$name] = max(7, (int)($row['watering_interval_days'] ?? 14));
                }
            }
        } catch (Throwable $e) {}
        return $out;
    }
}
