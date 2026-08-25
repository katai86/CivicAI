<?php
/**
 * M10 – AI Government Copilot.
 * Összegyűjti a hatósághoz tartozó kontextust (statisztika, prioritás, predikció, city health, green)
 * és a felhasználó kérdésére AI választ ad. Multi-tenant: authority scope.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiRouter.php';
require_once __DIR__ . '/AiPromptBuilder.php';
require_once __DIR__ . '/CityHealthScore.php';
require_once __DIR__ . '/GreenIntelligence.php';
require_once __DIR__ . '/PrioritizationEngine.php';
require_once __DIR__ . '/UrbanPredictionEngine.php';

class GovCopilot
{
    /** @var int|null */
    private $authorityId;
    /** @var string */
    private $scopeTitle;

    public function __construct(?int $authorityId, string $scopeTitle = '')
    {
        $this->authorityId = $authorityId > 0 ? $authorityId : null;
        $this->scopeTitle = trim($scopeTitle) ?: t('gov.scope_area');
    }

    /**
     * Kontextus szöveg: reports, delta, priorities, predictions, city health, green.
     */
    public function buildContext(): string
    {
        $pdo = db();
        $where = '1=1';
        $params = [];
        if ($this->authorityId) {
            $where = 'r.authority_id = ?';
            $params = [$this->authorityId];
        }

        $lines = ["Scope: " . $this->scopeTitle];

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where");
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where AND r.status NOT IN ('solved','closed','rejected')");
            $stmt->execute($params);
            $open = (int)$stmt->fetchColumn();
            $lines[] = "Reports: total $total, open (unresolved) $open.";
        } catch (Throwable $e) {}

        $last7 = null;
        $prev7 = null;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
            $stmt->execute($params);
            $last7 = (int)$stmt->fetchColumn();
            $lines[] = "Reports last 7 days: $last7.";
        } catch (Throwable $e) {}
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where AND r.created_at >= (NOW() - INTERVAL 14 DAY) AND r.created_at < (NOW() - INTERVAL 7 DAY)");
            $stmt->execute($params);
            $prev7 = (int)$stmt->fetchColumn();
            $lines[] = "Reports previous 7 days (8–14 days ago): $prev7.";
            if ($last7 !== null && $prev7 !== null) {
                $delta = $last7 - $prev7;
                $pct = $prev7 > 0 ? round(($delta / $prev7) * 100, 1) : ($last7 > 0 ? 100.0 : 0.0);
                $dir = $delta > 0 ? 'increased' : ($delta < 0 ? 'decreased' : 'unchanged');
                $lines[] = "Week-over-week change: $dir by " . abs($delta) . " reports ($pct% vs previous week).";
            }
        } catch (Throwable $e) {}
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where AND r.created_at >= (NOW() - INTERVAL 30 DAY)");
            $stmt->execute($params);
            $lines[] = "Reports last 30 days: " . (int)$stmt->fetchColumn() . ".";
        } catch (Throwable $e) {}

        try {
            $stmt = $pdo->prepare("SELECT r.category, COUNT(*) AS cnt FROM reports r WHERE $where GROUP BY r.category ORDER BY cnt DESC LIMIT 8");
            $stmt->execute($params);
            $byCat = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($byCat)) {
                $catStr = implode(', ', array_map(function ($r) {
                    return $r['category'] . ': ' . $r['cnt'];
                }, $byCat));
                $lines[] = "By category (all time): $catStr.";
            }
        } catch (Throwable $e) {}

        try {
            $prio = (new PrioritizationEngine())->compute($pdo, $where, $params, $this->authorityId);
            $top = array_slice($prio['by_category'] ?? [], 0, 5);
            if (!empty($top)) {
                $parts = [];
                foreach ($top as $it) {
                    $parts[] = ($it['category'] ?? '') . ' open=' . ($it['open_count'] ?? 0)
                        . ' avg_age_days=' . ($it['avg_age_days'] ?? 0)
                        . ' priority_score=' . ($it['priority_score'] ?? 0);
                }
                $lines[] = "Urgent backlog (heuristic priority by open volume × age): " . implode('; ', $parts) . ".";
            }
            $zones = array_slice($prio['by_zone'] ?? [], 0, 5);
            if (!empty($zones)) {
                $zparts = [];
                foreach ($zones as $z) {
                    $zparts[] = ($z['zone'] ?? '') . ' open=' . ($z['open_count'] ?? 0);
                }
                $lines[] = "Top problem zones: " . implode('; ', $zparts) . ".";
            }
            $lines[] = "Open reports total (priority engine): " . (int)($prio['totals']['open_reports'] ?? 0) . ".";
        } catch (Throwable $e) {}

        try {
            $pred = (new UrbanPredictionEngine())->predict($where, $params, []);
            $issues = $pred['predicted_issues'] ?? [];
            $high = 0;
            $med = 0;
            foreach ($issues as $iss) {
                $rl = (string)($iss['risk_level'] ?? '');
                if ($rl === 'high') {
                    $high++;
                } elseif ($rl === 'medium') {
                    $med++;
                }
            }
            $treeN = count($pred['predicted_tree_failures'] ?? []);
            $lines[] = "Spatial clusters (heuristic, last 90 days): " . count($issues)
                . " predicted issue clusters (high=$high, medium=$med); tree risk candidates=$treeN.";
            $sample = array_slice($issues, 0, 5);
            if (!empty($sample)) {
                $sp = [];
                foreach ($sample as $iss) {
                    $sp[] = ($iss['category'] ?? '') . '@' . ($iss['lat'] ?? '') . ',' . ($iss['lng'] ?? '')
                        . ' (' . ($iss['risk_level'] ?? '') . ')';
                }
                $lines[] = "Sample clusters: " . implode('; ', $sp) . ".";
            }
        } catch (Throwable $e) {}

        try {
            $stmt = $pdo->prepare("
                SELECT r.id, r.category, r.status, r.title, r.created_at
                FROM reports r
                WHERE $where AND r.status NOT IN ('solved','closed','rejected')
                ORDER BY r.created_at DESC
                LIMIT 8
            ");
            $stmt->execute($params);
            $samples = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($samples)) {
                $sp = [];
                foreach ($samples as $s) {
                    $title = mb_substr(trim((string)($s['title'] ?? '')), 0, 80);
                    $sp[] = '#' . (int)$s['id'] . ' ' . ($s['category'] ?? '') . '/' . ($s['status'] ?? '')
                        . ($title !== '' ? (' "' . $title . '"') : '');
                }
                $lines[] = "Recent open reports (sample): " . implode('; ', $sp) . ".";
            }
        } catch (Throwable $e) {}

        try {
            $health = (new CityHealthScore())->compute($this->authorityId);
            $overall = (int)($health['city_health_score'] ?? 50);
            $lines[] = "City health score (0-100): $overall"
                . " (infra=" . (int)($health['infrastructure_score'] ?? 0)
                . ", env=" . (int)($health['environment_score'] ?? 0)
                . ", engagement=" . (int)($health['engagement_score'] ?? 0)
                . ", maintenance=" . (int)($health['maintenance_score'] ?? 0) . ").";
        } catch (Throwable $e) {}

        try {
            $green = (new GreenIntelligence())->compute($this->authorityId);
            $canopy = round((float)($green['canopy_coverage'] ?? 0) * 100, 1);
            $carbon = round((float)($green['carbon_absorption'] ?? 0), 1);
            $drought = round((float)($green['drought_risk'] ?? 0) * 100, 0);
            $bio = round((float)($green['biodiversity_index'] ?? 0) * 100, 0);
            $lines[] = "Green: canopy {$canopy}%, carbon ~{$carbon} t CO2/year, drought risk {$drought}%, biodiversity index {$bio}%.";
        } catch (Throwable $e) {}

        try {
            if ($this->authorityId && function_exists('gov_trees_scope_where_sql')) {
                [$tw, $tp] = gov_trees_scope_where_sql($pdo, [$this->authorityId], 't');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE t.public_visible = 1 AND ($tw)");
                $stmt->execute($tp);
                $lines[] = "Trees in registry (scoped): " . (int)$stmt->fetchColumn() . ".";
            } else {
                $stmt = $pdo->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1");
                $lines[] = "Trees in registry (all): " . (int)$stmt->fetchColumn() . ".";
            }
        } catch (Throwable $e) {}

        try {
            if (function_exists('virtual_sensors_scope_for_authority') && $this->authorityId) {
                $st = $pdo->prepare('SELECT city, min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
                $st->execute([$this->authorityId]);
                $ar = $st->fetch(PDO::FETCH_ASSOC);
                if ($ar) {
                    $cities = !empty(trim($ar['city'] ?? '')) ? [trim($ar['city'])] : [];
                    $bounds = [];
                    if ($ar['min_lat'] !== null && $ar['max_lat'] !== null) {
                        $bounds[] = [(float)$ar['min_lat'], (float)$ar['max_lat'], (float)$ar['min_lng'], (float)$ar['max_lng']];
                    }
                    list($sw, $sp) = virtual_sensors_scope_for_authority($cities, $bounds);
                    $sql = "SELECT AVG(m.metric_value) FROM virtual_sensor_metrics_latest m
                        INNER JOIN virtual_sensors vs ON vs.id = m.virtual_sensor_id
                        WHERE ($sw) AND m.metric_key = 'aqi'";
                    $aq = $pdo->prepare($sql);
                    $aq->execute($sp);
                    $avgAqi = $aq->fetchColumn();
                    if ($avgAqi !== false && $avgAqi !== null) {
                        $lines[] = "Average sensor AQI (scoped): " . round((float)$avgAqi, 1) . ".";
                    }
                }
            }
        } catch (Throwable $e) {}

        try {
            require_once __DIR__ . '/UrbanObservationService.php';
            $aids = $this->authorityId ? [$this->authorityId] : [];
            if (empty($aids)) {
                $aids = array_map('intval', $pdo->query('SELECT id FROM authorities ORDER BY name LIMIT 50')->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
            $obs = (new UrbanObservationService())->listRecent($aids, 6);
            if (!empty($obs)) {
                $oparts = [];
                foreach ($obs as $o) {
                    $oparts[] = '#' . (int)$o['id']
                        . ' ' . ($o['street_condition'] ?? '')
                        . '/' . ($o['severity'] ?? '')
                        . (isset($o['vegetation_pct']) ? (' veg=' . $o['vegetation_pct'] . '%') : '')
                        . (!empty($o['scene_summary']) ? (' "' . mb_substr((string)$o['scene_summary'], 0, 60) . '"') : '');
                }
                $lines[] = "Recent street vision observations (stored): " . implode('; ', $oparts) . ".";
            } else {
                $lines[] = "Recent street vision observations: none stored yet.";
            }
        } catch (Throwable $e) {}

        $lines[] = "Note: priority and spatial clusters are heuristic (rule-based), not machine learning. Prefer numbers from this context.";

        return implode("\n", $lines);
    }

    /**
     * Kérdés megválaszolása AI-val. Visszatérés: ['ok' => true, 'answer' => string] vagy ['ok' => false, 'error' => string].
     */
    public function ask(string $question, string $outputLang = 'hu'): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'error' => t('gov.copilot_question_required')];
        }

        $router = new \AiRouter();
        if (!$router->isEnabled()) {
            return ['ok' => false, 'error' => t('api.ai_disabled')];
        }

        $context = $this->buildContext();
        $langName = \AiPromptBuilder::languageNameForCode($outputLang);

        $prompt = "You are a municipal government dashboard AI assistant. Use ONLY the context below to answer. "
            . "If the question cannot be answered from the data, say so briefly. "
            . "When discussing urgency or hotspots, use the priority scores and cluster data provided. "
            . "Do not invent statistics. Answer in {$langName}, concisely and practically for decision makers.\n\n"
            . "Context (data for the area):\n" . $context . "\n\n"
            . "User question: " . $question . "\n\n"
            . "Return ONLY a valid JSON object with exactly one key \"answer\" (string). No markdown, no code blocks. Example: {\"answer\": \"Your response here\"}";

        $resp = $router->callJson('gov_copilot', $prompt, [
            'max_tokens' => 900,
            'temperature' => 0.3,
            'timeout' => 45,
        ]);

        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'AI request failed'];
        }

        $data = is_array($resp['data']) ? $resp['data'] : null;
        $answer = $data && isset($data['answer']) ? trim((string)$data['answer']) : '';

        if ($answer === '' && !empty($resp['raw'])) {
            $raw = $resp['raw'];
            $content = '';
            if (isset($raw['choices'][0]['message']['content'])) {
                $content = trim((string)$raw['choices'][0]['message']['content']);
            }
            if ($content !== '') {
                $dec = json_decode($content, true);
                $answer = is_array($dec) && isset($dec['answer']) ? trim((string)$dec['answer']) : $content;
            }
        }

        $answer = $answer ?: t('gov.copilot_no_answer');
        $modelName = (string)($resp['model'] ?? '');
        $inputHash = hash('sha256', 'gov_copilot|' . $this->scopeTitle . '|' . $question);
        if (function_exists('ai_store_result')) {
            ai_store_result('gov', $this->authorityId, 'gov_copilot', $modelName, $inputHash, ['answer' => $answer], null);
        }
        return ['ok' => true, 'answer' => $answer];
    }
}
