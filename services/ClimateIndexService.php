<?php
/**
 * CivicAI Klímaindex (Milestone 7) – számítható 0–100 összpontszám aktív modulokból.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/CityHealthScore.php';
require_once __DIR__ . '/GreenIntelligence.php';
require_once __DIR__ . '/IntelligenceModuleRegistry.php';

class ClimateIndexService
{
    /** @return array{score:int,category:string,label:string,components:array,recommendations:array,active_modules:int,error_modules:int} */
    public function compute(?int $authorityId): array
    {
        $components = [];
        $weights = [];

        try {
            $health = (new CityHealthScore())->compute($authorityId);
            $env = (int)($health['environment_score'] ?? 50);
            $components['city_environment'] = ['score' => $env, 'label' => 'city_environment', 'weight' => 0.15];
            $weights[] = ['score' => $env, 'weight' => 0.15];
        } catch (Throwable $e) {
        }

        try {
            $green = (new GreenIntelligence())->compute($authorityId);
            $canopy = (float)($green['canopy_coverage'] ?? 0);
            $greenScore = (int)round(min(100, max(0, $canopy * 100)));
            $components['green_cover'] = ['score' => $greenScore, 'label' => 'green_cover', 'weight' => 0.15];
            $weights[] = ['score' => $greenScore, 'weight' => 0.15];

            $bio = (float)($green['biodiversity_index'] ?? 0);
            $bioScore = (int)round(min(100, max(0, $bio * 100)));
            $components['biodiversity'] = ['score' => $bioScore, 'label' => 'biodiversity', 'weight' => 0.1];
            $weights[] = ['score' => $bioScore, 'weight' => 0.1];

            $drought = (float)($green['drought_risk'] ?? 0);
            $heatScore = (int)round(min(100, max(0, (1.0 - $drought) * 100)));
            $components['drought_heat'] = ['score' => $heatScore, 'label' => 'drought_heat', 'weight' => 0.1];
            $weights[] = ['score' => $heatScore, 'weight' => 0.1];

            if (isset($green['green_deficit_score'])) {
                $gd = (float)$green['green_deficit_score'];
                $defScore = (int)round(min(100, max(0, (1.0 - $gd) * 100)));
                $components['green_deficit'] = ['score' => $defScore, 'label' => 'green_deficit', 'weight' => 0.1];
                $weights[] = ['score' => $defScore, 'weight' => 0.1];
            }
            if (isset($green['sealed_surface_pressure'])) {
                $sealed = (float)$green['sealed_surface_pressure'];
                $sealedScore = (int)round(min(100, max(0, (1.0 - $sealed) * 100)));
                $components['sealed_surface'] = ['score' => $sealedScore, 'label' => 'sealed_surface', 'weight' => 0.1];
                $weights[] = ['score' => $sealedScore, 'weight' => 0.1];
            }
        } catch (Throwable $e) {
        }

        $modules = IntelligenceModuleRegistry::listWithStatus();
        $active = 0;
        $errors = 0;
        foreach ($modules as $m) {
            if (!empty($m['enabled'])) {
                $active++;
            }
            if (!empty($m['errorMessage'])) {
                $errors++;
            }
        }
        $moduleScore = $active > 0 ? min(100, 40 + $active * 8) : 30;
        $components['data_modules'] = ['score' => $moduleScore, 'label' => 'data_modules', 'weight' => 0.1];
        $weights[] = ['score' => $moduleScore, 'weight' => 0.1];

        if (function_exists('climate_gfw_module_enabled') && climate_gfw_module_enabled()) {
            $components['forest_watch'] = ['score' => 65, 'label' => 'forest_watch', 'weight' => 0.05, 'preview' => true];
            $weights[] = ['score' => 65, 'weight' => 0.05];
        }

        $engagement = 50;
        if ($authorityId > 0) {
            try {
                $scope = gov_resolve_report_scope(db(), 'r', $authorityId);
                $st = db()->prepare('SELECT COUNT(*) FROM reports r WHERE ' . $scope['where'] . " AND r.status IN ('new','approved','in_progress','pending')");
                $st->execute($scope['params']);
                $open = (int)$st->fetchColumn();
                $engagement = (int)round(min(100, max(20, 100 - min(80, $open * 2))));
            } catch (Throwable $e) {
            }
        }
        $components['civic_engagement'] = ['score' => $engagement, 'label' => 'civic_engagement', 'weight' => 0.15];
        $weights[] = ['score' => $engagement, 'weight' => 0.15];

        $totalW = 0.0;
        $sum = 0.0;
        foreach ($weights as $w) {
            $totalW += $w['weight'];
            $sum += $w['score'] * $w['weight'];
        }
        $score = $totalW > 0 ? (int)round($sum / $totalW) : 50;
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'category' => self::categoryForScore($score),
            'label' => self::labelForCategory(self::categoryForScore($score)),
            'components' => $components,
            'recommendations' => self::buildRecommendations($components, $modules),
            'active_modules' => $active,
            'error_modules' => $errors,
            'total_modules' => count($modules),
        ];
    }

    public static function categoryForScore(int $score): string
    {
        if ($score <= 30) {
            return 'critical';
        }
        if ($score <= 50) {
            return 'weak';
        }
        if ($score <= 70) {
            return 'moderate';
        }
        if ($score <= 85) {
            return 'good';
        }
        return 'excellent';
    }

    public static function labelForCategory(string $cat): string
    {
        $key = 'intel.cat_' . $cat;
        return function_exists('t') ? t($key) : $cat;
    }

    /**
     * @param array<string,array<string,mixed>> $components
     * @param list<array<string,mixed>> $modules
     * @return list<array{priority:string,text:string,source:string}>
     */
    private static function buildRecommendations(array $components, array $modules): array
    {
        $recs = [];
        foreach ($components as $key => $c) {
            $s = (int)($c['score'] ?? 50);
            if ($s >= 55) {
                continue;
            }
            $prio = $s < 35 ? 'high' : 'medium';
            $textKey = 'intel.rec_' . $key;
            $recs[] = [
                'priority' => $prio,
                'text' => function_exists('t') ? t($textKey) : $key,
                'source' => $key,
            ];
        }
        $inactive = array_filter($modules, static fn ($m) => empty($m['enabled']) && ($m['status'] ?? '') !== 'planned');
        if (count($inactive) > 0 && count($recs) < 5) {
            $recs[] = [
                'priority' => 'low',
                'text' => function_exists('t') ? t('intel.rec_enable_modules') : 'Enable climate modules',
                'source' => 'modules',
            ];
        }
        return array_slice($recs, 0, 6);
    }
}
