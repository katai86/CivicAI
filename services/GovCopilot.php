<?php
/**
 * M10 – AI Government Copilot.
 * Összegyűjti a hatósághoz tartozó kontextust (statisztika, city health, green) és a felhasználó kérdésére AI választ ad.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiRouter.php';
require_once __DIR__ . '/AiPromptBuilder.php';
require_once __DIR__ . '/CityHealthScore.php';
require_once __DIR__ . '/GreenIntelligence.php';

class GovCopilot
{
    /** @var int|null */
    private $authorityId;
    /** @var string */
    private $scopeTitle;

    public function __construct(?int $authorityId, string $scopeTitle = '')
    {
        $this->authorityId = $authorityId > 0 ? $authorityId : null;
        $this->scopeTitle = trim($scopeTitle) ?: 'Terület';
    }

    /**
     * Kontextus szöveg összeállítása: reports, city health, green.
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

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
            $stmt->execute($params);
            $lines[] = "Reports last 7 days: " . (int)$stmt->fetchColumn() . ".";
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
                $lines[] = "By category: $catStr.";
            }
        } catch (Throwable $e) {}

        try {
            $health = (new CityHealthScore())->compute($this->authorityId);
            $overall = (int)($health['city_health_score'] ?? 50);
            $lines[] = "City health score (0-100): $overall (infrastructure, environment, engagement, maintenance sub-scores available).";
        } catch (Throwable $e) {}

        try {
            $green = (new GreenIntelligence())->compute($this->authorityId);
            $canopy = round((float)($green['canopy_coverage'] ?? 0) * 100, 1);
            $carbon = round((float)($green['carbon_absorption'] ?? 0), 1);
            $drought = round((float)($green['drought_risk'] ?? 0) * 100, 0);
            $lines[] = "Green: canopy coverage {$canopy}%, carbon absorption ~{$carbon} t CO2/year, drought risk index {$drought}%.";
        } catch (Throwable $e) {}

        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1");
            $lines[] = "Total trees (registry): " . (int)$stmt->fetchColumn() . ".";
        } catch (Throwable $e) {}

        return implode("\n", $lines);
    }

    /**
     * Kérdés megválaszolása AI-val. Visszatérés: ['ok' => true, 'answer' => string] vagy ['ok' => false, 'error' => string].
     */
    public function ask(string $question, string $outputLang = 'hu'): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'error' => 'Question is empty'];
        }

        $router = new \AiRouter();
        if (!$router->isEnabled()) {
            return ['ok' => false, 'error' => 'AI disabled or not configured'];
        }

        $context = $this->buildContext();
        $langName = \AiPromptBuilder::languageNameForCode($outputLang);

        $prompt = "You are a municipal government dashboard AI assistant. Use ONLY the context below to answer. "
            . "If the question cannot be answered from the data, say so briefly. "
            . "Answer in {$langName}, concisely and in a practical way for decision makers.\n\n"
            . "Context (data for the area):\n" . $context . "\n\n"
            . "User question: " . $question . "\n\n"
            . "Return ONLY a valid JSON object with exactly one key \"answer\" (string). No markdown, no code blocks. Example: {\"answer\": \"Your response here\"}";

        $resp = $router->callJson('gov_copilot', $prompt, [
            'max_tokens' => 800,
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

        $answer = $answer ?: 'No answer generated.';
        $modelName = (string)($resp['model'] ?? '');
        $inputHash = hash('sha256', 'gov_copilot|' . $this->scopeTitle . '|' . $question);
        if (function_exists('ai_store_result')) {
            ai_store_result('gov', $this->authorityId, 'gov_copilot', $modelName, $inputHash, ['answer' => $answer], null);
        }
        return ['ok' => true, 'answer' => $answer];
    }
}
