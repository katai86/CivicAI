<?php
/**
 * M1 – Evidence graph links (parent → child) for Evidence Explorer (M6).
 */
require_once __DIR__ . '/../cityintel/CityIntelSchema.php';
require_once __DIR__ . '/../../db.php';

final class CivicEvidenceLinkStore
{
    /**
     * @param array<string,mixed> $link
     * @return array{ok:bool,id:?int,error:?string}
     */
    public function link(array $link): array
    {
        if (!CityIntelSchema::ensure()) {
            return ['ok' => false, 'id' => null, 'error' => 'schema_missing'];
        }
        $parentType = trim((string)($link['parent_type'] ?? ''));
        $childType = trim((string)($link['child_type'] ?? ''));
        if ($parentType === '' || $childType === '') {
            return ['ok' => false, 'id' => null, 'error' => 'missing_type'];
        }
        try {
            $pdo = db();
            if (!db_table_has_column($pdo, 'civic_evidence_links', 'parent_type')) {
                return ['ok' => false, 'id' => null, 'error' => 'table_missing'];
            }
            $st = $pdo->prepare('
                INSERT INTO civic_evidence_links
                  (authority_id, parent_type, parent_id, child_type, child_id, relation, layer, confidence, meta_json)
                VALUES
                  (:authority_id, :parent_type, :parent_id, :child_type, :child_id, :relation, :layer, :confidence, :meta_json)
            ');
            $st->execute([
                ':authority_id' => isset($link['authority_id']) ? (int)$link['authority_id'] : null,
                ':parent_type' => mb_substr($parentType, 0, 48),
                ':parent_id' => (int)($link['parent_id'] ?? 0),
                ':child_type' => mb_substr($childType, 0, 48),
                ':child_id' => (int)($link['child_id'] ?? 0),
                ':relation' => mb_substr((string)($link['relation'] ?? 'derived_from'), 0, 48),
                ':layer' => mb_substr((string)($link['layer'] ?? 'evidence'), 0, 32),
                ':confidence' => isset($link['confidence']) ? (float)$link['confidence'] : null,
                ':meta_json' => !empty($link['meta']) ? json_encode($link['meta'], JSON_UNESCAPED_UNICODE) : null,
            ]);
            return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function chain(string $rootType, int $rootId, int $depth = 8): array
    {
        if (!CityIntelSchema::ensure()) {
            return [];
        }
        try {
            $pdo = db();
            if (!db_table_has_column($pdo, 'civic_evidence_links', 'parent_type')) {
                return [];
            }
            $out = [];
            $frontier = [['type' => $rootType, 'id' => $rootId]];
            $seen = [];
            for ($d = 0; $d < $depth && $frontier; $d++) {
                $next = [];
                foreach ($frontier as $node) {
                    $key = $node['type'] . '#' . $node['id'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $st = $pdo->prepare('
                        SELECT * FROM civic_evidence_links
                        WHERE parent_type = ? AND parent_id = ?
                        ORDER BY id ASC LIMIT 50
                    ');
                    $st->execute([$node['type'], $node['id']]);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                        $out[] = $row;
                        $next[] = ['type' => (string)$row['child_type'], 'id' => (int)$row['child_id']];
                    }
                }
                $frontier = $next;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}
