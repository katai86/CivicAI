<?php

class AiResultParser
{
    public static function normalizeReportUnderstanding(?array $data): array
    {
        $out = [
            'suggested_category' => null,
            'suggested_subcategory' => null,
            'urgency_level' => null,
            'short_admin_summary' => null,
            'citizen_friendly_rewrite' => null,
            'green_related_flag' => null,
            'confidence_score' => null,
        ];
        if (!$data) return $out;

        foreach ($out as $k => $_) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        return $out;
    }
}

