<?php
/**
 * Betölti a projekt gyökerében lévő .env fájlt (ha van).
 * A szerveren már beállított környezeti változók elsőbbséget élveznek.
 */
function civic_strip_env_quotes(string $value): string
{
    $len = strlen($value);
    if ($len >= 2) {
        $q = $value[0];
        if (($q === '"' || $q === "'") && $value[$len - 1] === $q) {
            return substr($value, 1, -1);
        }
    }
    return $value;
}

function civic_parse_dotenv_file(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }

    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        if ($key === '') {
            continue;
        }
        $out[$key] = civic_strip_env_quotes(trim(substr($line, $pos + 1)));
    }
    return $out;
}

function civic_bootstrap_dotenv(string $path): void
{
    static $parsed = null;
    if ($parsed === null) {
        $parsed = civic_parse_dotenv_file($path);
    }
    foreach ($parsed as $key => $val) {
        if (getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
}

function civic_env(string $key, ?string $default = null): ?string
{
    static $fileVars = null;
    if ($fileVars === null) {
        $fileVars = civic_parse_dotenv_file(dirname(__DIR__) . '/.env');
    }
    $v = getenv($key);
    if ($v !== false) {
        return $v;
    }
    if (array_key_exists($key, $fileVars)) {
        return $fileVars[$key];
    }
    return $default;
}
