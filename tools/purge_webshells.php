<?php
/**
 * CivicAI – webshell / dropper DETEKTÁLÁS + opcionális tisztítás
 *
 * CSAK éles szerveren, egyszeri használatra. Utána TÖRÖLD.
 *
 * Detektálás (CLI):
 *   php tools/purge_webshells.php /path/to/CivicAI
 *
 * Tisztítás (írás!):
 *   php tools/purge_webshells.php /path/to/CivicAI --fix
 *
 * Dry-run lista:
 *   php tools/purge_webshells.php /path/to/CivicAI --fix --dry-run
 *
 * Ajánlás: ha a fájlok >30%-a fertőzött → NE ezzel takaríts,
 * hanem tiszta git deploy (lásd docs/SECURITY_INCIDENT_WEBSHELL.md).
 */
declare(strict_types=1);

$args = array_slice($argv ?? [], 1);
$doFix = in_array('--fix', $args, true);
$dryRun = in_array('--dry-run', $args, true);
$rootArg = null;
foreach ($args as $a) {
    if ($a !== '' && $a[0] !== '-') {
        $rootArg = $a;
        break;
    }
}

$root = $rootArg ? realpath($rootArg) : realpath(dirname(__DIR__));
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Invalid root\n");
    exit(1);
}

/**
 * Ismert dropper minták (2024–2026 shared-host kampányok).
 * Hex: data_chunk, dat, itm, fac, ent + .element/.component/.token/.rec
 */
$linePatterns = [
    // POST/REQUEST kulcsok hex-elve
    '/filter_has_var\s*\(\s*INPUT_POST\s*,\s*["\']\\\\x64ata\\\\x5F\\\\x63h\\\\x75n\\\\x6B["\']/i',
    '/array_key_exists\s*\(\s*["\']\\\\x64a\\\\x74["\']/i',
    '/array_key_exists\s*\(\s*["\']\\\\x69\\\\x74m["\']/i',
    '/\\\\x64ata\\\\x5F\\\\x63h\\\\x75n\\\\x6B/',
    '/\\\\x69\\\\x74m/',
    // dropper fájlnevek
    '/["\']\.component["\']|["\']\.element["\']|["\']\.token["\']|["\']\.rec["\']/',
    // tipikus temp+írható könyvtár lánc + include
    '/\/dev\/shm.*sys_get_temp_dir|sys_get_temp_dir.*\/dev\/shm/',
    '/hex2bin\s*\(\s*\$_(POST|REQUEST|GET)/i',
    '/chr\s*\(\s*ord\s*\(\s*\$char\s*\)\s*\^\s*\d+\s*\)/',
    '/abcdefghijklmnopqrstuvwxyz0123456789[\'"].*\^\s*\d+/s',
    // klasszikus
    '/eval\s*\(\s*(base64_decode|gzinflate|str_rot13)\s*\(/i',
    '/FilesMan|c99shell|r57shell|WSO\s*shell/i',
];

/** Egész „if (... dropper ...) { ... }” blokk egy sorban / tömören */
$blockPatterns = [
    '/if\s*\(\s*filter_has_var\s*\(\s*INPUT_POST\s*,\s*["\']\\\\x64ata\\\\x5F\\\\x63h\\\\x75n\\\\x6B["\']\s*\)\s*\)\s*\{.*?\}/s',
    '/if\s*\(\s*array_key_exists\s*\(\s*["\']\\\\x64a\\\\x74["\']\s*,\s*\$_POST\s*\)[^\{]*\{\s*.*?\}/s',
    '/if\s*\(\s*array_key_exists\s*\(\s*["\']\\\\x69\\\\x74m["\']\s*,\s*\$_POST\s*\)[^\{]*\{\s*.*?\}/s',
    '/if\s*\(\s*!?\s*empty\s*\(\s*\$_REQUEST\s*\[\s*["\']fac["\']\s*\]\s*\)\s*\)\s*\{.*?\}/s',
    '/if\s*\(\s*!?\s*is_null\s*\(\s*\$_POST\s*\[\s*["\']ent["\']\s*\][^\{]*\{\s*.*?\}/s',
    // általános: POST hex kulcs + include temp fájl
    '/if\s*\([^\{]{0,200}\$_(POST|REQUEST)\[[^\{]{0,120}\{[^\{\}]{0,40}(include|require)[^\{\}]{0,80}(unlink|@unlink)[^\{\}]{0,40}\}/is',
];

$skipDirSubstrings = [
    '/.git/', '/node_modules/', '/vendor/', '/dashboard/node_modules/',
];

$infected = [];
$cleaned = [];
$phpInUploads = [];
$totalPhp = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root)));
    $norm = '/' . ltrim($rel, '/');

    foreach ($skipDirSubstrings as $sd) {
        if (strpos($norm, $sd) !== false) {
            continue 2;
        }
    }

    $ext = strtolower($file->getExtension());
    $base = $file->getBasename();

    if (strpos($norm, '/uploads/') !== false) {
        if (in_array($ext, ['php', 'phtml', 'phar', 'php5', 'php7', 'php8'], true)
            || preg_match('/\.(php|phtml)\./i', $base)) {
            $phpInUploads[] = $rel;
        }
        continue;
    }

    if (!in_array($ext, ['php', 'phtml', 'inc'], true)) {
        continue;
    }
    if (strpos($norm, '/tools/purge_webshells.php') !== false
        || strpos($norm, '/tools/scan_webshell.php') !== false) {
        continue;
    }

    $totalPhp++;
    $size = $file->getSize();
    if ($size <= 0 || $size > 5_000_000) {
        continue;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        continue;
    }

    $matched = [];
    foreach ($linePatterns as $i => $re) {
        if (preg_match($re, $raw)) {
            $matched[] = 'sig:' . $i;
        }
    }

    // hosszú egyetlen sor + POST/eval
    foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $ln => $line) {
        if (strlen($line) < 400) {
            continue;
        }
        if (preg_match('/\$_(POST|REQUEST|GET).*(include|require|file_put_contents|fwrite)/i', $line)
            || preg_match('/\\\\x6[0-9a-f]{2}/i', $line) && stripos($line, 'include') !== false) {
            $matched[] = 'longline:' . ($ln + 1);
            break;
        }
    }

    if (!$matched) {
        continue;
    }

    $infected[] = ['file' => $rel, 'sigs' => $matched];

    if (!$doFix) {
        continue;
    }

    $new = $raw;
    foreach ($blockPatterns as $bre) {
        $new = preg_replace($bre, '', $new) ?? $new;
    }

    // Maradék egyedi sorok: ha a sor maga a dropper
    $outLines = [];
    foreach (preg_split("/\r\n|\n|\r/", $new) ?: [] as $line) {
        $drop = false;
        foreach ($linePatterns as $re) {
            if (preg_match($re, $line) && (
                stripos($line, 'include') !== false
                || stripos($line, 'require') !== false
                || stripos($line, 'file_put_contents') !== false
                || stripos($line, 'fwrite') !== false
                || stripos($line, 'hex2bin') !== false
            )) {
                $drop = true;
                break;
            }
        }
        if (!$drop) {
            $outLines[] = $line;
        }
    }
    $new = implode("\n", $outLines);
    // üres PHP nyitó után maradt szemét takarítás
    $new = preg_replace('/<\?php\s*<\?php/', '<?php', $new) ?? $new;

    if ($new === $raw) {
        continue;
    }

    if ($dryRun) {
        $cleaned[] = $rel . ' (dry-run)';
        continue;
    }

    $bak = $path . '.malwarebak.' . date('YmdHis');
    @copy($path, $bak);
    if (@file_put_contents($path, $new) !== false) {
        $cleaned[] = $rel;
    }
}

$pct = $totalPhp > 0 ? round(100 * count($infected) / $totalPhp, 1) : 0;

echo "CivicAI purge_webshells\n";
echo "Root: {$root}\n";
echo "PHP files scanned: {$totalPhp}\n";
echo "Infected: " . count($infected) . " ({$pct}%)\n";
echo "Mode: " . ($doFix ? ($dryRun ? 'FIX dry-run' : 'FIX write') : 'DETECT only') . "\n\n";

if ($phpInUploads) {
    echo "CRITICAL – PHP in uploads/ (delete these):\n";
    foreach ($phpInUploads as $f) {
        echo "  DEL {$f}\n";
    }
    echo "\n";
}

if ($infected) {
    echo "Infected files:\n";
    foreach ($infected as $h) {
        echo '  - ' . $h['file'] . ' [' . implode(',', $h['sigs']) . "]\n";
    }
    echo "\n";
}

if ($cleaned) {
    echo "Cleaned / would clean:\n";
    foreach ($cleaned as $c) {
        echo "  * {$c}\n";
    }
    echo "\n";
}

if ($pct >= 30) {
    echo "WARNING: Infection rate {$pct}% >= 30%.\n";
    echo "DO NOT rely on surgical cleanup.\n";
    echo "Redeploy CLEAN code from GitHub main, then rotate ALL secrets.\n";
    echo "See docs/SECURITY_INCIDENT_WEBSHELL.md\n";
}

if (!$doFix && $infected) {
    echo "\nTo attempt cleanup: php tools/purge_webshells.php {$root} --fix --dry-run\n";
    echo "Then:            php tools/purge_webshells.php {$root} --fix\n";
}

exit($infected || $phpInUploads ? 2 : 0);
