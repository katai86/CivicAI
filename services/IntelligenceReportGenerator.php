<?php
/**
 * Intelligence jelentésgenerátor – HTML (M8).
 */
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/IntelligenceHub.php';
require_once __DIR__ . '/ClimateIndexService.php';

class IntelligenceReportGenerator
{
    /** @param array<string,mixed> $opts type, audience (citizen|official), authority_id */
    public function generate(array $opts): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }

        $type = (string)($opts['type'] ?? 'full');
        $audience = (string)($opts['audience'] ?? 'official');
        $aid = isset($opts['authority_id']) ? (int)$opts['authority_id'] : null;

        $hub = new IntelligenceHub();
        $ctx = $hub->fetchFullContext($aid, true);

        try {
            $ci = (new ClimateIndexService())->compute($aid);
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error('IntelligenceReportGenerator climate_index: ' . $e->getMessage());
            }
            $ci = ['score' => 0, 'category' => 'moderate', 'label' => '—', 'recommendations' => []];
        }

        $title = $this->reportTitle($type);
        $html = $this->wrapHtml($title, $this->bodyHtml($type, $audience, $ctx, $ci));
        return [
            'ok' => true,
            'type' => $type,
            'audience' => $audience,
            'title' => $title,
            'html' => $html,
            'generated_at' => gmdate('c'),
        ];
    }

    private function reportTitle(string $type): string
    {
        $map = [
            'climate' => 'intel.report_climate',
            'biodiversity' => 'intel.report_biodiversity',
            'ev' => 'intel.report_ev',
            'solar' => 'intel.report_solar',
            'lights' => 'intel.report_lights',
            'full' => 'intel.report_full',
        ];
        $key = $map[$type] ?? 'intel.report_full';
        return function_exists('t') ? t($key) : $type;
    }

    /** @param array<string,mixed> $ctx */
    private function bodyHtml(string $type, string $audience, array $ctx, array $ci): string
    {
        $simple = $audience === 'citizen';
        $h = '';
        $h .= '<section><h2>' . htmlspecialchars($simple ? (function_exists('t') ? t('intel.climate_index') : 'Climate index') : 'CivicAI Klímaindex') . '</h2>';
        $h .= '<p><strong>' . (int)($ci['score'] ?? 0) . '/100</strong> – ' . htmlspecialchars((string)($ci['label'] ?? '')) . '</p></section>';

        if ($type === 'climate' || $type === 'full') {
            $w = $ctx['weather'] ?? [];
            $h .= '<section><h2>' . htmlspecialchars(function_exists('t') ? t('gov.tab_climate') : 'Climate') . '</h2>';
            $h .= '<ul><li>' . htmlspecialchars('Hőmérséklet: ' . ($w['temp_c'] ?? '—') . ' °C') . '</li>';
            $h .= '<li>' . htmlspecialchars('Csapadék (7 nap): ' . ($w['precip_mm'] ?? '—') . ' mm') . '</li>';
            $h .= '<li>' . htmlspecialchars('Aszályindex: ' . ($w['drought_index'] ?? '—')) . '</li></ul></section>';
        }
        if ($type === 'biodiversity' || $type === 'full') {
            $g = $ctx['gbif'] ?? [];
            $h .= '<section><h2>GBIF</h2><p>' . htmlspecialchars('Megfigyelések: ' . ($g['occurrence_count'] ?? 0)) . '</p></section>';
        }
        if ($type === 'solar' || $type === 'full') {
            $p = $ctx['pvgis'] ?? [];
            $h .= '<section><h2>PVGIS</h2><p>' . htmlspecialchars('Éves termelés (1 kWp): ' . ($p['annual_kwh'] ?? '—') . ' kWh') . '</p></section>';
        }
        if ($type === 'ev' || $type === 'full') {
            $o = $ctx['ocm'] ?? [];
            $h .= '<section><h2>EV</h2><p>' . htmlspecialchars('Töltőpontok (25 km): ' . ($o['charger_count'] ?? 0)) . '</p></section>';
        }
        if ($type === 'lights' || $type === 'full') {
            $v = $ctx['viirs'] ?? [];
            $h .= '<section><h2>VIIRS</h2><p>' . htmlspecialchars('Fényszennyezés index: ' . ($v['light_pollution_index'] ?? '—')) . '</p></section>';
        }

        $recs = $ci['recommendations'] ?? [];
        if (!empty($recs)) {
            $h .= '<section><h2>' . htmlspecialchars(function_exists('t') ? t('intel.recommendations') : 'Recommendations') . '</h2><ul>';
            foreach ($recs as $r) {
                $h .= '<li>' . htmlspecialchars((string)($r['text'] ?? '')) . '</li>';
            }
            $h .= '</ul></section>';
        }
        $h .= '<footer><p class="small text-muted">CivicAI Intelligence Platform · ' . htmlspecialchars(gmdate('Y-m-d H:i')) . ' UTC</p></footer>';
        return $h;
    }

    private function wrapHtml(string $title, string $body): string
    {
        return '<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8"><title>'
            . htmlspecialchars($title) . '</title><style>body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#1e293b}h1{font-size:1.4rem;color:#0f766e}h2{font-size:1.1rem;margin-top:1.5rem;color:#334155}section{margin-bottom:1rem;padding:0.75rem 1rem;background:#f8fafc;border-radius:8px;border-left:4px solid #0d9488}ul{padding-left:1.2rem}</style></head><body><h1>'
            . htmlspecialchars($title) . '</h1>' . $body . '</body></html>';
    }
}
