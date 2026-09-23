<?php
declare(strict_types=1);

require_once __DIR__ . '/../../util.php';

/**
 * Routing célok konfigurációja: mock / email / webhook.
 */
final class RoutingRecipientRegistry
{
    /** @var list<string> */
    public const TARGETS = [
        'municipal_clerk',
        'state_road',
        'mvm_lumen',
        'authority_inbox',
        'unrouted',
    ];

    /** @return array{mode: string, email: ?string, webhook_url: ?string, webhook_token: ?string, label: string} */
    public static function forTarget(string $target): array
    {
        $key = self::envKeyForTarget($target);
        $mode = strtolower(trim((string)(civic_cfg('ROUTING_MODE_' . $key, '') ?? '')));
        if ($mode === '') {
            $mode = (defined('ROUTING_MOCK_ENABLED') && ROUTING_MOCK_ENABLED) ? 'mock' : 'email';
        }
        if (!in_array($mode, ['mock', 'email', 'webhook'], true)) {
            $mode = 'mock';
        }

        return [
            'mode' => $mode,
            'email' => self::emailForTarget($target),
            'webhook_url' => self::webhookUrlForTarget($target),
            'webhook_token' => civic_cfg('ROUTING_WEBHOOK_TOKEN', '') ?: null,
            'label' => self::labelForTarget($target),
        ];
    }

    public static function labelForTarget(string $target): string
    {
        $k = 'routing.' . $target;
        $t = function_exists('t') ? t($k) : $target;
        return ($t !== $k) ? $t : $target;
    }

    private static function envKeyForTarget(string $target): string
    {
        return strtoupper(str_replace('.', '_', $target));
    }

    private static function emailForTarget(string $target): ?string
    {
        $map = [
            'municipal_clerk' => defined('ROUTING_EMAIL_MUNICIPAL') ? (string)ROUTING_EMAIL_MUNICIPAL : null,
            'state_road' => defined('ROUTING_EMAIL_STATE_ROAD') ? (string)ROUTING_EMAIL_STATE_ROAD : null,
            'mvm_lumen' => defined('ROUTING_EMAIL_MVM_LUMEN') ? (string)ROUTING_EMAIL_MVM_LUMEN : null,
        ];
        $email = $map[$target] ?? null;
        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        return null;
    }

    private static function webhookUrlForTarget(string $target): ?string
    {
        $key = self::envKeyForTarget($target);
        $url = trim((string)(civic_cfg('ROUTING_WEBHOOK_' . $key . '_URL', '') ?? ''));
        return $url !== '' ? $url : null;
    }

    /** @return list<array{target: string, label: string, mode: string}> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::TARGETS as $target) {
            if ($target === 'unrouted') {
                continue;
            }
            $cfg = self::forTarget($target);
            $out[] = [
                'target' => $target,
                'label' => $cfg['label'],
                'mode' => $cfg['mode'],
            ];
        }
        return $out;
    }
}
