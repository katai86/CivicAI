<?php
/**
 * EEA (RSS kiemelések) + INSPIRE / térbeli kontextus linkek.
 * Milestone 7.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/ExternalHttpClient.php';
require_once __DIR__ . '/ExternalDataCache.php';

class EeaInspireContextService
{
    private const EEA_FEATURED_RSS = 'https://www.eea.europa.eu/en/newsroom/rss-feeds/featured-articles-rss/rss.xml';
    private const MAX_ITEMS = 6;

    public function eeaActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('eea_enabled');
    }

    public function inspireActive(): bool
    {
        return function_exists('eu_open_data_module_enabled') && eu_open_data_module_enabled()
            && function_exists('eu_open_data_feature_enabled') && eu_open_data_feature_enabled('inspire_enabled');
    }

    /**
     * @param array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}|null $bbox
     * @return array{
     *   ok:bool,
     *   eea_highlights: list<array{title:string,link:string,pub_date:?string}>,
     *   inspire: array{geoportal_url:string,registry_url:string,center_lat:?float,center_lng:?float}|null,
     *   cached:bool,
     *   error:?string,
     *   notes:array<int,string>
     * }
     */
    public function fetch(?array $bbox): array
    {
        $out = [
            'ok' => false,
            'eea_highlights' => [],
            'inspire' => null,
            'cached' => false,
            'error' => null,
            'notes' => [],
        ];
        if (!$this->eeaActive() && !$this->inspireActive()) {
            $out['error'] = 'eea_inspire_disabled';
            return $out;
        }

        $cacheKey = 'eea_inspire_' . md5(json_encode([
            'eea' => $this->eeaActive(),
            'inspire' => $this->inspireActive(),
            'bbox' => $bbox ? [
                'min_lat' => round((float)$bbox['min_lat'], 3),
                'max_lat' => round((float)$bbox['max_lat'], 3),
                'min_lng' => round((float)$bbox['min_lng'], 3),
                'max_lng' => round((float)$bbox['max_lng'], 3),
            ] : null,
        ]));
        $hit = ExternalDataCache::getValid('eea', $cacheKey);
        if ($hit && !empty($hit['payload']['ok'])) {
            $p = $hit['payload'];
            $p['cached'] = true;
            return $p;
        }

        if ($this->eeaActive()) {
            $rss = $this->parseFeaturedRss();
            if ($rss['ok']) {
                $out['eea_highlights'] = $rss['items'];
                $out['notes'][] = 'eea_featured_articles_rss';
            } else {
                $out['notes'][] = 'eea_rss_failed:' . ($rss['error'] ?? 'unknown');
            }
        }

        if ($this->inspireActive()) {
            $centerLat = null;
            $centerLng = null;
            if ($bbox && (float)$bbox['max_lat'] > (float)$bbox['min_lat'] && (float)$bbox['max_lng'] > (float)$bbox['min_lng']) {
                $centerLat = round(((float)$bbox['min_lat'] + (float)$bbox['max_lat']) / 2, 5);
                $centerLng = round(((float)$bbox['min_lng'] + (float)$bbox['max_lng']) / 2, 5);
            }
            $out['inspire'] = [
                'geoportal_url' => 'https://inspire-geoportal.ec.europa.eu/',
                'registry_url' => 'https://inspire.ec.europa.eu/',
                'center_lat' => $centerLat,
                'center_lng' => $centerLng,
            ];
            $out['notes'][] = 'inspire_static_portal_links';
        }

        $hasEea = $this->eeaActive() && !empty($out['eea_highlights']);
        $hasInspire = $this->inspireActive() && $out['inspire'] !== null;
        $out['ok'] = $hasEea || $hasInspire;
        if (!$out['ok']) {
            $out['error'] = 'no_eea_inspire_content';
        }

        if ($out['ok']) {
            ExternalDataCache::set('eea', $cacheKey, $out, 180, 'ok', null);
            ExternalDataCache::logProvider('eea', 'eea_inspire_context', 'ok', 'eea=' . ($hasEea ? '1' : '0') . ';inspire=' . ($hasInspire ? '1' : '0'));
        } else {
            ExternalDataCache::logProvider('eea', 'eea_inspire_context', 'error', $out['error'] ?? '');
        }

        return $out;
    }

    /**
     * @return array{ok:bool,items:list<array{title:string,link:string,pub_date:?string}>,error:?string}
     */
    private function parseFeaturedRss(): array
    {
        $empty = ['ok' => false, 'items' => [], 'error' => null];
        $resp = ExternalHttpClient::get(self::EEA_FEATURED_RSS);
        if (!$resp['ok']) {
            $empty['error'] = $resp['error'] ?? ('http_' . ($resp['status'] ?? 0));
            return $empty;
        }
        $body = trim((string)$resp['body']);
        if ($body === '') {
            $empty['error'] = 'empty_body';
            return $empty;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            $empty['error'] = 'xml_parse_failed';
            return $empty;
        }
        $items = [];
        if (!isset($xml->channel->item)) {
            $empty['error'] = 'no_items';
            return $empty;
        }
        $n = 0;
        foreach ($xml->channel->item as $it) {
            if ($n >= self::MAX_ITEMS) {
                break;
            }
            $title = trim(html_entity_decode((string)$it->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $link = trim((string)$it->link);
            $pub = isset($it->pubDate) ? trim((string)$it->pubDate) : null;
            if ($title === '' || $link === '') {
                continue;
            }
            $items[] = [
                'title' => $title,
                'link' => $link,
                'pub_date' => $pub !== '' ? $pub : null,
            ];
            $n++;
        }
        if (count($items) === 0) {
            $empty['error'] = 'no_valid_items';
            return $empty;
        }
        return ['ok' => true, 'items' => $items, 'error' => null];
    }
}
