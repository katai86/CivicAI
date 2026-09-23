<?php
declare(strict_types=1);

require_once __DIR__ . '/../ExternalHttpClient.php';
require_once __DIR__ . '/RoutingRecipientRegistry.php';

/**
 * Bejelentés továbbítás: mock e-mail, valós e-mail, webhook API.
 */
final class RoutingDeliveryService
{
    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $decision
     * @return array{ok: bool, channel: string, recipient: ?string, subject: ?string, sent: bool, error: ?string, external_response: ?string}
     */
    public static function deliver(string $target, array $report, array $decision): array
    {
        if ($target === 'unrouted') {
            return [
                'ok' => true,
                'channel' => 'none',
                'recipient' => null,
                'subject' => null,
                'sent' => false,
                'error' => null,
                'external_response' => null,
            ];
        }

        $cfg = RoutingRecipientRegistry::forTarget($target);
        [$subject, $bodyText, $payload] = self::buildPayload($report, $decision, $cfg);

        if ($cfg['mode'] === 'webhook' && !empty($cfg['webhook_url'])) {
            return self::deliverWebhook($cfg, $payload, $subject);
        }

        $email = self::resolveEmailRecipient($target, $report, $decision, $cfg);
        if ($email === null || $email === '') {
            return [
                'ok' => false,
                'channel' => $cfg['mode'],
                'recipient' => null,
                'subject' => $subject,
                'sent' => false,
                'error' => 'no_recipient',
                'external_response' => null,
            ];
        }

        $sent = false;
        $error = null;
        try {
            $sent = send_mail($email, $subject, $bodyText);
            if (!$sent) {
                $error = 'send_mail_failed';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'ok' => $sent,
            'channel' => $cfg['mode'] === 'mock' ? 'mock_email' : 'email',
            'recipient' => $email,
            'subject' => $subject,
            'sent' => $sent,
            'error' => $error,
            'external_response' => null,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $cfg
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private static function buildPayload(array $report, array $decision, array $cfg): array
    {
        $id = (int)($report['id'] ?? 0);
        $case = case_number($id, (string)($report['created_at'] ?? null), $report['case_no'] ?? null);
        $target = (string)($decision['target'] ?? '');
        $label = $cfg['label'] ?? $target;
        $subject = '[CivicAI] ' . $case . ' → ' . $label;

        $payload = [
            'event' => 'report_routed',
            'case_no' => $case,
            'report_id' => $id,
            'routing_target' => $target,
            'routing_reason' => $decision['reason'] ?? '',
            'category' => $report['category'] ?? '',
            'title' => $report['title'] ?? '',
            'description' => $report['description'] ?? '',
            'status' => $report['status'] ?? '',
            'lat' => $report['lat'] ?? null,
            'lng' => $report['lng'] ?? null,
            'road' => $report['road'] ?? '',
            'city' => $report['city'] ?? '',
            'address' => $report['address_approx'] ?? '',
            'authority_id' => $report['authority_id'] ?? null,
            'created_at' => $report['created_at'] ?? null,
            'track_url' => !empty($report['notify_token'])
                ? app_url('/case.php?token=' . rawurlencode((string)$report['notify_token']))
                : null,
        ];

        $modeNote = ($cfg['mode'] ?? '') === 'mock' ? ' (mock / teszt)' : '';
        $bodyLines = [
            'CivicAI automatikus továbbítás' . $modeNote,
            '',
            'Ügyiratszám: ' . $case,
            'Cél: ' . $label,
            'Indok: ' . ($decision['reason'] ?? ''),
            'Kategória: ' . ($report['category'] ?? ''),
            'Cím: ' . trim(($report['address_approx'] ?? '') ?: (($report['road'] ?? '') . ', ' . ($report['city'] ?? ''))),
            'Leírás: ' . mb_strimwidth((string)($report['description'] ?? ''), 0, 500, '…'),
            '',
            'Report ID: #' . $id,
        ];

        return [$subject, implode("\n", $bodyLines), $payload];
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $cfg
     */
    private static function resolveEmailRecipient(string $target, array $report, array $decision, array $cfg): ?string
    {
        if ($target === 'authority_inbox' || $target === 'municipal_clerk') {
            $aid = (int)($report['authority_id'] ?? ($decision['authority_id'] ?? 0));
            if ($aid > 0) {
                try {
                    $stmt = db()->prepare('SELECT contact_email FROM authorities WHERE id = ? AND is_active = 1 LIMIT 1');
                    $stmt->execute([$aid]);
                    $email = trim((string)$stmt->fetchColumn());
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return $email;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        $fallback = $cfg['email'] ?? null;
        if ($fallback !== null && $fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
            return $fallback;
        }

        return null;
    }

    /** @param array<string, mixed> $cfg */
    private static function deliverWebhook(array $cfg, array $payload, string $subject): array
    {
        $url = (string)($cfg['webhook_url'] ?? '');
        $headers = [];
        $token = $cfg['webhook_token'] ?? null;
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $headers[] = 'X-CivicAI-Event: report_routed';

        $body = array_merge($payload, ['subject' => $subject]);
        $res = ExternalHttpClient::postJson($url, $body, 30, $headers);

        $ok = !empty($res['ok']) && ($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300;
        $extId = null;
        if ($ok && ($res['body'] ?? '') !== '') {
            $decoded = json_decode((string)$res['body'], true);
            if (is_array($decoded)) {
                $extId = $decoded['ticket_id'] ?? $decoded['id'] ?? $decoded['external_id'] ?? null;
            }
        }

        return [
            'ok' => $ok,
            'channel' => 'webhook',
            'recipient' => $url,
            'subject' => $subject,
            'sent' => $ok,
            'error' => $ok ? null : ((string)($res['error'] ?? '') ?: 'http_' . ($res['status'] ?? 0)),
            'external_response' => substr((string)($res['body'] ?? ''), 0, 2000),
            'external_ticket_id' => $extId !== null ? (string)$extId : null,
        ];
    }
}
