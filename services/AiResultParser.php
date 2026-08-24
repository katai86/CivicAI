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

    /** @return array{description:?string,short_title:?string,suggested_category:?string,suggested_subcategory:?string,urgency_level:?string,hazard_level:?string,confidence_score:?float,objects:array,segments:array,depth_notes:?string} */
    public static function normalizeCivicImageAnalysis(?array $data): array
    {
        $cats = ['road', 'sidewalk', 'lighting', 'trash', 'green', 'traffic', 'idea', 'civil_event'];
        $urgency = ['low', 'medium', 'high'];
        $hazard = ['none', 'low', 'medium', 'high'];

        $out = [
            'description' => null,
            'short_title' => null,
            'suggested_category' => null,
            'suggested_subcategory' => null,
            'urgency_level' => null,
            'hazard_level' => null,
            'confidence_score' => null,
            'objects' => [],
            'segments' => [],
            'depth_notes' => null,
        ];
        if (!$data) {
            return $out;
        }

        if (!empty($data['description']) && is_string($data['description'])) {
            $out['description'] = mb_substr(trim($data['description']), 0, 800);
        }
        if (!empty($data['short_title']) && is_string($data['short_title'])) {
            $out['short_title'] = mb_substr(trim($data['short_title']), 0, 120);
        }
        if (!empty($data['suggested_subcategory']) && is_string($data['suggested_subcategory'])) {
            $out['suggested_subcategory'] = mb_substr(trim($data['suggested_subcategory']), 0, 120);
        }
        if (!empty($data['depth_notes']) && is_string($data['depth_notes'])) {
            $out['depth_notes'] = mb_substr(trim($data['depth_notes']), 0, 400);
        }

        $cat = isset($data['suggested_category']) ? strtolower(trim((string)$data['suggested_category'])) : '';
        if (in_array($cat, $cats, true)) {
            $out['suggested_category'] = $cat;
        }
        $u = isset($data['urgency_level']) ? strtolower(trim((string)$data['urgency_level'])) : '';
        if (in_array($u, $urgency, true)) {
            $out['urgency_level'] = $u;
        }
        $h = isset($data['hazard_level']) ? strtolower(trim((string)$data['hazard_level'])) : '';
        if (in_array($h, $hazard, true)) {
            $out['hazard_level'] = $h;
        }

        if (isset($data['confidence_score']) && is_numeric($data['confidence_score'])) {
            $c = (float)$data['confidence_score'];
            if ($c > 1.0 && $c <= 100.0) {
                $c = $c / 100.0;
            }
            if ($c >= 0 && $c <= 1.5) {
                $out['confidence_score'] = max(0.0, min(1.0, $c));
            }
        }

        if (!empty($data['objects']) && is_array($data['objects'])) {
            foreach (array_slice($data['objects'], 0, 8) as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $class = isset($o['class']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string)$o['class'])) : '';
                if ($class === '') {
                    continue;
                }
                $conf = isset($o['confidence']) && is_numeric($o['confidence']) ? (float)$o['confidence'] : null;
                if ($conf !== null && $conf > 1.0 && $conf <= 100.0) {
                    $conf = $conf / 100.0;
                }
                $item = ['class' => mb_substr($class, 0, 40)];
                if ($conf !== null && $conf >= 0 && $conf <= 1.5) {
                    $item['confidence'] = max(0.0, min(1.0, $conf));
                }
                $out['objects'][] = $item;
            }
        }

        if (!empty($data['segments']) && is_array($data['segments'])) {
            foreach (array_slice($data['segments'], 0, 6) as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $kind = isset($s['kind']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string)$s['kind'])) : '';
                if ($kind === '') {
                    continue;
                }
                $item = ['kind' => mb_substr($kind, 0, 40)];
                if (isset($s['coverage_pct']) && is_numeric($s['coverage_pct'])) {
                    $item['coverage_pct'] = max(0, min(100, (int)round((float)$s['coverage_pct'])));
                }
                if (isset($s['value']) && is_numeric($s['value'])) {
                    $item['value'] = (float)$s['value'];
                }
                $out['segments'][] = $item;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> City Brain WOW vision normalizált válasz */
    public static function normalizeCitybrainVision(?array $data): array
    {
        $civic = self::normalizeCivicImageAnalysis($data);
        $streetCond = ['excellent', 'good', 'fair', 'poor'];
        $health = ['healthy', 'dry', 'disease_suspected', 'unknown'];

        $out = array_merge($civic, [
            'scene_summary' => null,
            'street_condition' => null,
            'street_issues' => [],
            'green_surfaces' => [],
            'trees' => [],
            'wow_highlights' => [],
            'recommended_action' => null,
        ]);

        if (!empty($data['scene_summary']) && is_string($data['scene_summary'])) {
            $out['scene_summary'] = mb_substr(trim($data['scene_summary']), 0, 1200);
        }
        if (!empty($data['recommended_action']) && is_string($data['recommended_action'])) {
            $out['recommended_action'] = mb_substr(trim($data['recommended_action']), 0, 400);
        }
        $sc = isset($data['street_condition']) ? strtolower(trim((string)$data['street_condition'])) : '';
        if (in_array($sc, $streetCond, true)) {
            $out['street_condition'] = $sc;
        }
        if (!empty($data['street_issues']) && is_array($data['street_issues'])) {
            foreach (array_slice($data['street_issues'], 0, 6) as $issue) {
                if (is_string($issue) && trim($issue) !== '') {
                    $out['street_issues'][] = mb_substr(trim($issue), 0, 200);
                }
            }
        }
        if (!empty($data['wow_highlights']) && is_array($data['wow_highlights'])) {
            foreach (array_slice($data['wow_highlights'], 0, 5) as $h) {
                if (is_string($h) && trim($h) !== '') {
                    $out['wow_highlights'][] = mb_substr(trim($h), 0, 200);
                }
            }
        }
        if (!empty($data['green_surfaces']) && is_array($data['green_surfaces'])) {
            $gs = $data['green_surfaces'];
            foreach (['vegetation_pct', 'pavement_pct', 'building_pct', 'sky_pct', 'water_pct'] as $k) {
                if (isset($gs[$k]) && is_numeric($gs[$k])) {
                    $out['green_surfaces'][$k] = max(0, min(100, (int)round((float)$gs[$k])));
                }
            }
        }
        if (!empty($data['trees']) && is_array($data['trees'])) {
            foreach (array_slice($data['trees'], 0, 5) as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $h = isset($t['health']) ? strtolower(trim((string)$t['health'])) : 'unknown';
                if (!in_array($h, $health, true)) {
                    $h = 'unknown';
                }
                $item = [
                    'species' => isset($t['species']) ? mb_substr(trim((string)$t['species']), 0, 80) : null,
                    'health' => $h,
                    'health_note' => isset($t['health_note']) ? mb_substr(trim((string)$t['health_note']), 0, 200) : null,
                    'trunk_diameter_cm' => null,
                    'canopy_diameter_m' => null,
                    'confidence' => null,
                ];
                if (isset($t['trunk_diameter_cm']) && is_numeric($t['trunk_diameter_cm'])) {
                    $v = (float)$t['trunk_diameter_cm'];
                    if ($v >= 0 && $v <= 500) {
                        $item['trunk_diameter_cm'] = $v;
                    }
                }
                if (isset($t['canopy_diameter_m']) && is_numeric($t['canopy_diameter_m'])) {
                    $v = (float)$t['canopy_diameter_m'];
                    if ($v >= 0 && $v <= 50) {
                        $item['canopy_diameter_m'] = $v;
                    }
                }
                if (isset($t['confidence']) && is_numeric($t['confidence'])) {
                    $c = (float)$t['confidence'];
                    if ($c > 1 && $c <= 100) {
                        $c /= 100;
                    }
                    if ($c >= 0 && $c <= 1.5) {
                        $item['confidence'] = max(0, min(1, $c));
                    }
                }
                $out['trees'][] = $item;
            }
        }

        return $out;
    }
}
