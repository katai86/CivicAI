<?php
/**
 * M4 – AI Sentiment Analysis.
 * Összegyűjti reports.description + report_status_log.note szövegeket, AI-val sentiment + top_concerns + emerging_issues.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/AiRouter.php';
require_once __DIR__ . '/AiPromptBuilder.php';

class SentimentAnalyzer
{
    /**
     * @param string $reportWhere SQL WHERE for reports (e.g. "r.authority_id IN (1,2)" or "1=1")
     * @param array $reportParams bound params for reportWhere
     * @param string $dateFrom Y-m-d
     * @param string $dateTo Y-m-d
     * @param string $scopeTitle for AI prompt (e.g. authority name)
     * @param string $outputLang hu, en, ...
     */
    public function analyze(string $reportWhere, array $reportParams, string $dateFrom, string $dateTo, string $scopeTitle = 'Municipality', string $outputLang = 'hu'): array
    {
        $out = [
            'positive_percent' => 34,
            'neutral_percent' => 33,
            'negative_percent' => 33,
            'top_concerns' => [],
            'emerging_issues' => [],
        ];

        $pdo = db();
        $texts = [];

        try {
            $stmt = $pdo->prepare("
              SELECT r.id, r.title, r.description, r.category
              FROM reports r
              WHERE $reportWhere AND r.created_at >= ? AND r.created_at <= ?
              ORDER BY r.created_at DESC
              LIMIT 100
            ");
            $stmt->execute(array_merge($reportParams, [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $desc = trim((string)($r['description'] ?? ''));
                if ($desc !== '') {
                    $texts[] = ['text' => ($r['title'] ?? '') . "\n" . $desc, 'category' => $r['category'] ?? ''];
                }
            }
            $reportIds = array_column($rows, 'id');
            if (!empty($reportIds)) {
                $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
                $logStmt = $pdo->prepare("SELECT note FROM report_status_log WHERE report_id IN ($placeholders) AND note IS NOT NULL AND TRIM(note) != '' ORDER BY changed_at DESC LIMIT 150");
                $logStmt->execute($reportIds);
                while ($row = $logStmt->fetch(PDO::FETCH_ASSOC)) {
                    $texts[] = ['text' => trim((string)$row['note'])];
                }
            }
        } catch (Throwable $e) {
            return $out;
        }

        if (count($texts) === 0) {
            return $out;
        }

        $flatTexts = array_map(function ($t) {
            return is_array($t) ? ($t['text'] ?? '') : (string)$t;
        }, $texts);

        $router = new \AiRouter();
        if (!$router->isEnabled()) {
            return $out;
        }

        $prompt = \AiPromptBuilder::sentimentAnalysis($scopeTitle, $flatTexts, $outputLang);
        $resp = $router->callJson('gov_sentiment', $prompt, ['max_tokens' => 600]);

        if (empty($resp['ok']) || !is_array($resp['data'])) {
            return $out;
        }

        $d = $resp['data'];
        $pos = isset($d['positive_percent']) ? (int)round((float)$d['positive_percent']) : 34;
        $neu = isset($d['neutral_percent']) ? (int)round((float)$d['neutral_percent']) : 33;
        $neg = isset($d['negative_percent']) ? (int)round((float)$d['negative_percent']) : 33;
        $sum = $pos + $neu + $neg;
        if ($sum !== 100 && $sum > 0) {
            $pos = (int)round(100 * $pos / $sum);
            $neu = (int)round(100 * $neu / $sum);
            $neg = 100 - $pos - $neu;
        }
        $out['positive_percent'] = max(0, min(100, $pos));
        $out['neutral_percent'] = max(0, min(100, $neu));
        $out['negative_percent'] = max(0, min(100, $neg));

        if (!empty($d['top_concerns']) && is_array($d['top_concerns'])) {
            $out['top_concerns'] = array_slice(array_map('strval', $d['top_concerns']), 0, 8);
        }
        if (!empty($d['emerging_issues']) && is_array($d['emerging_issues'])) {
            $out['emerging_issues'] = array_slice(array_map('strval', $d['emerging_issues']), 0, 5);
        }

        return $out;
    }
}
