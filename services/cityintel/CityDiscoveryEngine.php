<?php
/**
 * M5 – CivicAI discoveries from insights + anomalies + cross-signals.
 */
require_once __DIR__ . '/CityInsightEngine.php';
require_once __DIR__ . '/../../db.php';

final class CityDiscoveryEngine
{
    /** @return array<int,array<string,mixed>> */
    public function generate(int $authorityId): array
    {
        $discoveries = [];
        try {
            $insights = (new CityInsightEngine())->generate($authorityId);
            foreach ($insights['insights'] ?? [] as $ins) {
                $key = 'insight_' . ($ins['id'] ?? md5(json_encode($ins)));
                $discoveries[] = [
                    'discovery_key' => $key,
                    'title' => (string)($ins['title'] ?? $ins['headline'] ?? 'Discovery'),
                    'summary' => (string)($ins['interpretation_text'] ?? $ins['summary'] ?? ''),
                    'category' => (string)($ins['category'] ?? 'general'),
                    'priority_score' => min(100.0, (float)($ins['confidence'] ?? 0.5) * 100),
                    'evidence' => $ins,
                ];
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) log_error('CityDiscoveryEngine: ' . $e->getMessage());
        }
        $this->persist($authorityId, $discoveries);
        return $discoveries;
    }

    /** @param array<int,array<string,mixed>> $discoveries */
    private function persist(int $authorityId, array $discoveries): void
    {
        try {
            if (!function_exists('db_table_has_column') || !db_table_has_column(db(), 'civic_discoveries', 'discovery_key')) {
                return;
            }
            $pdo = db();
            foreach ($discoveries as $d) {
                $pdo->prepare('
                    INSERT INTO civic_discoveries (authority_id, discovery_key, title, summary, category, priority_score, evidence_json, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')
                    ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary),
                      priority_score = VALUES(priority_score), evidence_json = VALUES(evidence_json)
                ')->execute([
                    $authorityId,
                    $d['discovery_key'],
                    mb_substr($d['title'], 0, 255),
                    $d['summary'],
                    $d['category'],
                    $d['priority_score'],
                    json_encode($d['evidence'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) log_error('CityDiscoveryEngine persist: ' . $e->getMessage());
        }
    }
}
