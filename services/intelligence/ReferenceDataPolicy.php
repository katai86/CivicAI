<?php
/**
 * Referencia / mock adatok kiszűrése – a UI csak élő adatot vagy egyértelmű „nincs adat” állapotot kap.
 */
final class ReferenceDataPolicy
{
    /** @param array<string,mixed> $payload */
    public static function isReferencePayload(array $payload): bool
    {
        $notes = $payload['notes'] ?? [];
        if (!is_array($notes)) {
            $notes = [];
        }
        $refMarkers = [
            'using_reference',
            'preview_reference',
            'lite_fetch',
            'gfw_using_reference',
            'ksh_using_reference_snapshot',
            'reference_not_available',
        ];
        foreach ($refMarkers as $m) {
            if (in_array($m, $notes, true)) {
                return true;
            }
        }
        $source = (string)($payload['source'] ?? '');
        if ($source !== '' && (str_contains($source, 'reference') || str_contains($source, '_reference'))) {
            return true;
        }
        if (!empty($payload['reference']) || !empty($payload['reference_snapshot'])) {
            return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    public static function noData(string $reason = 'no_live_data', ?string $error = null, array $extra = []): array
    {
        $notes = array_values(array_filter([$reason, $error]));
        $out = array_merge([
            'ok' => false,
            'status' => 'no_data',
            'notes' => $notes,
            'cached' => false,
            'message' => function_exists('t') ? t('intel.status_no_data') : 'No live data available',
        ], $extra);
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $nullKeys numeric fields to null when stripping reference
     * @return array<string,mixed>
     */
    public static function normalize(array $payload, array $nullKeys = []): array
    {
        if (!self::isReferencePayload($payload)) {
            return $payload;
        }
        $reason = 'reference_not_available';
        foreach ((array)($payload['notes'] ?? []) as $n) {
            if (is_string($n) && $n !== '') {
                $reason = $n;
                break;
            }
        }
        $out = self::noData($reason, null, [
            'source' => $payload['source'] ?? 'unknown',
        ]);
        foreach ($nullKeys as $k) {
            $out[$k] = null;
        }
        return $out;
    }
}
