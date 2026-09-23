<?php
/**
 * M19 – Multi-image tree inspection sessions.
 */
require_once __DIR__ . '/../../db.php';

final class TreeInspectionSession
{
    /** @return array{ok:bool,session_key:?string,error:?string} */
    public static function open(?int $treeId, ?int $authorityId, array $requiredShots = []): array
    {
        try {
            if (!db_table_has_column(db(), 'tree_inspection_sessions', 'session_key')) {
                return ['ok' => false, 'session_key' => null, 'error' => 'schema_missing'];
            }
            $key = 'tis_' . bin2hex(random_bytes(8));
            db()->prepare('
                INSERT INTO tree_inspection_sessions (tree_id, authority_id, session_key, required_shots_json, status)
                VALUES (?, ?, ?, ?, \'open\')
            ')->execute([
                $treeId,
                $authorityId,
                $key,
                $requiredShots ? json_encode($requiredShots, JSON_UNESCAPED_UNICODE) : null,
            ]);
            return ['ok' => true, 'session_key' => $key, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'session_key' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return array{ok:bool,inspections:list<array>,summary:?array} */
    public static function close(string $sessionKey): array
    {
        try {
            $pdo = db();
            $st = $pdo->prepare('SELECT id, tree_id, authority_id FROM tree_inspection_sessions WHERE session_key = ? AND status = \'open\' LIMIT 1');
            $st->execute([$sessionKey]);
            $sess = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sess) {
                return ['ok' => false, 'inspections' => [], 'summary' => null];
            }
            $pdo->prepare('UPDATE tree_inspection_sessions SET status = \'closed\', closed_at = NOW() WHERE id = ?')->execute([(int)$sess['id']]);
            $ins = [];
            if (db_table_has_column($pdo, 'tree_inspections', 'session_id')) {
                $st2 = $pdo->prepare('SELECT * FROM tree_inspections WHERE session_id = ? ORDER BY created_at ASC');
                $st2->execute([$sessionKey]);
                $ins = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $summary = self::aggregateInspections($ins);
            return ['ok' => true, 'inspections' => $ins, 'summary' => $summary];
        } catch (Throwable $e) {
            return ['ok' => false, 'inspections' => [], 'summary' => null];
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private static function aggregateInspections(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        $healthScores = [];
        $riskLevels = [];
        foreach ($rows as $r) {
            if (isset($r['health_score'])) {
                $healthScores[] = (float)$r['health_score'];
            }
            if (!empty($r['risk_level'])) {
                $riskLevels[] = (string)$r['risk_level'];
            }
        }
        return [
            'shot_count' => count($rows),
            'avg_health_score' => $healthScores ? round(array_sum($healthScores) / count($healthScores), 1) : null,
            'max_risk' => $riskLevels ? max($riskLevels) : null,
        ];
    }
}
