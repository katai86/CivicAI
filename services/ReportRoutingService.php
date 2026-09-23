<?php
/**
 * Automatikus bejelentés-útvonal: önkormányzat / közút / MVM Lumen + delivery réteg.
 */
declare(strict_types=1);

require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/routing/RoutingDeliveryService.php';
require_once __DIR__ . '/routing/RoutingRecipientRegistry.php';

final class ReportRoutingService
{
    public static function routeAfterCreate(int $reportId): array
    {
        return self::routeReport($reportId, ['on_create' => true]);
    }

    /**
     * @param array<string, mixed> $options force_target, authority_id, on_create, skip_status_change
     */
    public static function routeReport(int $reportId, array $options = []): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ? LIMIT 1');
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            return ['ok' => false, 'error' => 'report_not_found'];
        }

        if (!empty($options['authority_id'])) {
            $aid = (int)$options['authority_id'];
            if ($aid > 0 && (int)($report['authority_id'] ?? 0) !== $aid) {
                $pdo->prepare('UPDATE reports SET authority_id = ? WHERE id = ?')->execute([$aid, $reportId]);
                $report['authority_id'] = $aid;
            }
        }

        $forceTarget = isset($options['force_target']) ? trim((string)$options['force_target']) : null;
        if ($forceTarget === '') {
            $forceTarget = null;
        }
        if ($forceTarget === null && !empty($report['routing_override_target'])) {
            $forceTarget = trim((string)$report['routing_override_target']);
        }

        $decision = self::decide($report, $forceTarget);
        $delivery = RoutingDeliveryService::deliver($decision['target'], $report, $decision);

        try {
            $pdo->prepare('UPDATE reports SET routing_target = ?, routed_at = NOW() WHERE id = ?')
                ->execute([$decision['target'], $reportId]);
            if (!empty($delivery['external_ticket_id'])) {
                $pdo->prepare('UPDATE reports SET external_ticket_id = ? WHERE id = ?')
                    ->execute([(string)$delivery['external_ticket_id'], $reportId]);
            }
        } catch (Throwable $e) {
            log_error('ReportRoutingService update report: ' . $e->getMessage());
        }

        try {
            $pdo->prepare('
                INSERT INTO report_routing_log
                  (report_id, routing_target, recipient_email, decision_reason, mail_subject,
                   mail_sent, mail_error, delivery_channel, external_response, payload_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $reportId,
                $decision['target'],
                $delivery['recipient'] ?? null,
                $decision['reason'],
                $delivery['subject'] ?? null,
                !empty($delivery['sent']) ? 1 : 0,
                $delivery['error'] ?? null,
                $delivery['channel'] ?? null,
                $delivery['external_response'] ?? null,
                json_encode(['decision' => $decision, 'delivery' => $delivery], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            log_error('ReportRoutingService log: ' . $e->getMessage());
        }

        $onCreate = !empty($options['on_create']);
        if (!$onCreate && empty($options['skip_status_change'])) {
            if ($decision['target'] !== 'unrouted' && in_array($report['status'] ?? '', ['new', 'approved'], true)) {
                try {
                    $pdo->prepare("UPDATE reports SET status = 'forwarded' WHERE id = ? AND status IN ('new','approved')")
                        ->execute([$reportId]);
                } catch (Throwable $e) {
                }
            }
        } elseif ($onCreate && $decision['target'] !== 'unrouted' && ($report['status'] ?? '') === 'new') {
            try {
                $pdo->prepare("UPDATE reports SET status = 'forwarded' WHERE id = ? AND status = 'new'")
                    ->execute([$reportId]);
            } catch (Throwable $e) {
            }
        }

        return [
            'ok' => true,
            'target' => $decision['target'],
            'target_label' => RoutingRecipientRegistry::labelForTarget($decision['target']),
            'recipient' => $delivery['recipient'] ?? null,
            'reason' => $decision['reason'],
            'mail_sent' => !empty($delivery['sent']),
            'mail_error' => $delivery['error'] ?? null,
            'delivery_channel' => $delivery['channel'] ?? null,
            'external_ticket_id' => $delivery['external_ticket_id'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return array{target: string, reason: string}
     */
    public static function decide(array $report, ?string $forceTarget = null): array
    {
        if ($forceTarget !== null && $forceTarget !== '' && in_array($forceTarget, RoutingRecipientRegistry::TARGETS, true)) {
            return [
                'target' => $forceTarget,
                'reason' => 'manual_override',
            ];
        }

        $category = strtolower(trim((string)($report['category'] ?? '')));
        $road = trim((string)($report['road'] ?? ''));
        $city = trim((string)($report['city'] ?? ''));

        if (in_array($category, ['lighting'], true)) {
            return ['target' => 'mvm_lumen', 'reason' => 'category_lighting'];
        }

        if (in_array($category, ['road', 'sidewalk', 'traffic'], true)) {
            if (self::isStateRoad($road)) {
                return [
                    'target' => 'state_road',
                    'reason' => 'state_road_detected',
                    'road' => $road,
                ];
            }
            return [
                'target' => 'municipal_clerk',
                'reason' => 'local_road_municipal',
                'road' => $road,
                'city' => $city,
            ];
        }

        $authorityId = (int)($report['authority_id'] ?? 0);
        if ($authorityId > 0) {
            return [
                'target' => 'authority_inbox',
                'reason' => 'default_authority',
                'authority_id' => $authorityId,
            ];
        }

        return ['target' => 'unrouted', 'reason' => 'no_authority_no_rule'];
    }

    public static function isStateRoad(?string $road): bool
    {
        if ($road === null || trim($road) === '') {
            return false;
        }
        $r = trim($road);
        if (preg_match('/\bM\d+\b/u', $r)) {
            return true;
        }
        if (preg_match('/\b\d{1,3}(-es|-as|-es)\s*(fő)?út\b/ui', $r)) {
            return true;
        }
        if (preg_match('/\b(főút|országút|autópálya|gyorsforgalmi)\b/ui', $r)) {
            return true;
        }
        return false;
    }

    public static function setAuthority(int $reportId, ?int $authorityId): bool
    {
        try {
            db()->prepare('UPDATE reports SET authority_id = ? WHERE id = ?')
                ->execute([$authorityId > 0 ? $authorityId : null, $reportId]);
            return true;
        } catch (Throwable $e) {
            log_error('ReportRoutingService setAuthority: ' . $e->getMessage());
            return false;
        }
    }

    public static function setOverrideTarget(int $reportId, ?string $target): bool
    {
        $t = $target !== null ? trim($target) : '';
        if ($t !== '' && !in_array($t, RoutingRecipientRegistry::TARGETS, true)) {
            return false;
        }
        try {
            db()->prepare('UPDATE reports SET routing_override_target = ? WHERE id = ?')
                ->execute([$t !== '' ? $t : null, $reportId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function logForReport(int $reportId): array
    {
        try {
            $stmt = db()->prepare('SELECT * FROM report_routing_log WHERE report_id = ? ORDER BY id DESC LIMIT 30');
            $stmt->execute([$reportId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
