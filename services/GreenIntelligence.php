<?php
/**
 * M6 – Green Intelligence Module.
 * canopy_coverage, carbon_absorption, biodiversity_index, drought_risk.
 * M2 EU: opcionális Copernicus/STAC + helyi rács (ndvi_score, green_deficit_score, …) ha be van kapcsolva az EU modul.
 * M3 EU: opcionális CLMS Urban Atlas 2018 (terület-súlyozott megoszlás a bbox-ban) ha `clms_enabled`.
 */
require_once __DIR__ . '/../db.php';

class GreenIntelligence
{
    /** Default canopy radius in metres when canopy_diameter is missing */
    private const DEFAULT_CANOPY_RADIUS_M = 3.0;
    /** kg CO2 absorbed per m² canopy per year (rough estimate) */
    private const CO2_KG_PER_M2_YEAR = 0.5;

    /**
     * @param int|null $authorityId null = all trees, no bbox; >0 = trees in authority bbox if available
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

        $bbox = null;
        if ($authorityId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1");
                $stmt->execute([$authorityId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['min_lat'] !== null && $row['max_lat'] !== null && $row['min_lng'] !== null && $row['max_lng'] !== null) {
                    $bbox = [
                        'min_lat' => (float)$row['min_lat'],
                        'max_lat' => (float)$row['max_lat'],
                        'min_lng' => (float)$row['min_lng'],
                        'max_lng' => (float)$row['max_lng'],
                    ];
                }
            } catch (Throwable $e) {}
        }

        $treeWhere = 'public_visible = 1 AND lat IS NOT NULL AND lng IS NOT NULL';
        $treeParams = [];
        if ($bbox) {
            $treeWhere .= ' AND lat >= ? AND lat <= ? AND lng >= ? AND lng <= ?';
            $treeParams = [$bbox['min_lat'], $bbox['max_lat'], $bbox['min_lng'], $bbox['max_lng']];
        }

        try {
            $stmt = $pdo->prepare("SELECT id, species, canopy_diameter, trunk_diameter, last_watered FROM trees WHERE $treeWhere");
            $stmt->execute($treeParams);
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
