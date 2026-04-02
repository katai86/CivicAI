<?php
/**
 * HTTP kliens külső EU / Copernicus API-khoz: időkorlát, User-Agent, egységes hibakezelés.
 * Beállítás: admin → EU nyílt adatok → request_timeout_seconds, vagy EU_OPEN_DATA_HTTP_TIMEOUT env.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

class ExternalHttpClient
{
    /** @return int másodperc, 5–120 */
    public static function defaultTimeoutSeconds(): int
    {
        if (function_exists('eu_open_data_request_timeout_seconds')) {
            return eu_open_data_request_timeout_seconds();
        }
        $v = getenv('EU_OPEN_DATA_HTTP_TIMEOUT');
        if ($v !== false && $v !== '' && is_numeric($v)) {
            return max(5, min(120, (int)$v));
        }
        return 30;
    }

    public static function userAgent(): string
    {
        $base = defined('NOMINATIM_USER_AGENT') ? (string)NOMINATIM_USER_AGENT : 'CivicAI/1.0';
        return $base . ' EU-OpenData';
    }

    /**
     * GET kérés.
     *
     * @return array{ok:bool,status:int,body:string,error:?string,url:string}
     */
    public static function get(string $url, ?int $timeoutSeconds = null): array
    {
        $timeout = $timeoutSeconds ?? self::defaultTimeoutSeconds();
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'empty_url', 'url' => ''];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init_failed', 'url' => $url];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => self::userAgent(),
                CURLOPT_HTTPHEADER => ['Accept: application/json, */*'],
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'curl_exec_failed', 'url' => $url];
            }
            $ok = $status >= 200 && $status < 300;
            return ['ok' => $ok, 'status' => $status, 'body' => (string)$body, 'error' => $ok ? null : ('http_' . $status), 'url' => $url];
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: " . self::userAgent() . "\r\nAccept: application/json, */*\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (!empty($http_response_header) && is_array($http_response_header) && isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'file_get_contents_failed', 'url' => $url];
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'body' => (string)$body, 'error' => $ok ? null : ('http_' . $status), 'url' => $url];
    }

    /**
     * POST JSON (pl. STAC / OData).
     *
     * @return array{ok:bool,status:int,body:string,error:?string,url:string}
     */
    public static function postJson(string $url, array $body, ?int $timeoutSeconds = null): array
    {
        $timeout = $timeoutSeconds ?? self::defaultTimeoutSeconds();
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $payload = '{}';
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_required', 'url' => $url];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init_failed', 'url' => $url];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/geo+json, application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'curl_exec_failed', 'url' => $url];
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'body' => (string)$resp, 'error' => $ok ? null : ('http_' . $status), 'url' => $url];
    }

    /**
     * POST application/x-www-form-urlencoded (OAuth token).
     *
     * @return array{ok:bool,status:int,body:string,error:?string,url:string}
     */
    public static function postForm(string $url, array $fields, ?int $timeoutSeconds = null): array
    {
        $timeout = $timeoutSeconds ?? self::defaultTimeoutSeconds();
        $payload = http_build_query($fields, '', '&', PHP_QUERY_RFC1738);
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_required', 'url' => $url];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init_failed', 'url' => $url];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'curl_exec_failed', 'url' => $url];
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'body' => (string)$resp, 'error' => $ok ? null : ('http_' . $status), 'url' => $url];
    }
}
