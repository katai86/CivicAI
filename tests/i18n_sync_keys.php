<?php
/**
 * Sync missing translation keys from en.php (+ hu.php union) into de, fr, it, es, sl.
 * Missing keys fall back to English, then Hungarian if absent in en.
 * Usage: php tests/i18n_sync_keys.php
 */
$root = dirname(__DIR__);
$baseLangs = ['en', 'hu'];
$targets = ['de', 'fr', 'it', 'es', 'sl'];

function loadLangKeys(string $path): array
{
    $keys = [];
    $data = include $path;
    if (!is_array($data)) {
        return $keys;
    }
    foreach ($data as $k => $v) {
        if (is_string($k)) {
            $keys[$k] = $v;
        }
    }
    return $keys;
}

function writeLangFile(string $path, array $keys): void
{
    ksort($keys, SORT_STRING);
    $lines = ["<?php", "return ["];
    foreach ($keys as $k => $v) {
        $lines[] = '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ',';
    }
    $lines[] = '];';
    $lines[] = '';
    file_put_contents($path, implode("\n", $lines));
}

$base = [];
foreach ($baseLangs as $loc) {
    $path = $root . '/lang/' . $loc . '.php';
    if (!is_file($path)) {
        continue;
    }
    foreach (loadLangKeys($path) as $k => $v) {
        if (!isset($base[$k])) {
            $base[$k] = $v;
        }
    }
}
foreach ($targets as $loc) {
    $path = $root . '/lang/' . $loc . '.php';
    if (!is_file($path)) {
        echo "Skip missing $loc\n";
        continue;
    }
    $cur = loadLangKeys($path);
    $added = 0;
    foreach ($base as $k => $v) {
        if (!array_key_exists($k, $cur)) {
            $cur[$k] = $v;
            $added++;
        }
    }
    if ($added > 0) {
        writeLangFile($path, $cur);
        echo "$loc: +$added keys\n";
    } else {
        echo "$loc: up to date\n";
    }
}
