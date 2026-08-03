<?php
/**
 * Egyszeri webshell / backdoor szkenner (CivicAI).
 *
 * HASZNÁLAT (SSH / CLI – ajánlott):
 *   php tools/scan_webshell.php /path/to/CivicAI
 *
 * VAGY böngészőben (majd AZONNAL töröld a fájlt a szerverről):
 *   /CivicAI/tools/scan_webshell.php?k=IDEIGLENES_TITKOS_KULCS
 *   és állítsd be: define('SCAN_SECRET', '...'); a fájl tetején VAGY
 *   export CIVICAI_SCAN_SECRET=...
 *
 * A script NEM módosít fájlokat – csak listázza a gyanús találatokat.
 */
declare(strict_types=1);

$cliRoot = $argv[1] ?? null;
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $secret = getenv('CIVICAI_SCAN_SECRET') ?: (defined('SCAN_SECRET') ? (string)SCAN_SECRET : '');
    $key = (string)($_GET['k'] ?? '');
    if ($secret === '' || $key === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

$root = $isCli
    ? ($cliRoot ? realpath($cliRoot) : realpath(dirname(__DIR__)))
    : realpath(dirname(__DIR__));

if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Invalid root\n");
    exit(1);
}

$patterns = [
    'hex_dat_post' => '/array_key_exists\s*\(\s*["\']\\\\x64a\\\\x74["\']/',
    'dat_obfuscated' => '/\\\\x64a\\\\x74/',
    'element_dropper' => '/\.element["\']\s*\)|implode\s*\(\s*["\']\/["\'].*\.element/',
    'upload_tmp_chain' => '/upload_tmp_dir.*getcwd.*\/dev\/shm|\/dev\/shm.*sys_get_temp_dir/',
    'salt_decode_loop' => '/\$salt\s*=\s*[\'"]abcdefghijklmnopqrstuvwxyz0123456789[\'"]/',
    'eval_base64' => '/eval\s*\(\s*(base64_decode|gzinflate|str_rot13)\s*\(/i',
    'assert_base64' => '/assert\s*\(\s*(base64_decode|gzuncompress)/i',
    'preg_replace_e' => '/preg_replace\s*\(\s*[\'"].*\/e[\'"]/i',
    'filesman' => '/FilesMan|WSO\s*shell|c99shell|r57shell/i',
    'chr_join_obfuscation' => '/chr\s*\(\s*\(\s*\(int\)\$v1\s*-\s*\$chS/',
];

$skipDirs = [
    '/.git/', '/node_modules/', '/dashboard/node_modules/', '/vendor/',
    '/uploads/', // binary images; still scan for .php below separately
];

$hits = [];
$phpInUploads = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root)));
    $norm = '/' . ltrim($rel, '/');

    $skip = false;
    foreach ($skipDirs as $sd) {
        if (strpos($norm, $sd) !== false && strpos($norm, '/uploads/') === false) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    $base = $file->getBasename();

    // PHP (or disguised) inside uploads = critical
    if (strpos($norm, '/uploads/') !== false) {
        if (in_array($ext, ['php', 'phtml', 'phar', 'php5', 'php7', 'php8', 'cgi', 'pl'], true)
            || preg_match('/\.(php|phtml)\./i', $base)) {
            $phpInUploads[] = $rel;
        }
        continue;
    }

    if (!in_array($ext, ['php', 'phtml', 'inc', 'php5', 'php7', 'php8'], true)) {
        continue;
    }

    // Skip this scanner itself
    if (strpos($norm, '/tools/scan_webshell.php') !== false) {
        continue;
    }

    $size = $file->getSize();
    if ($size <= 0 || $size > 2_000_000) {
        continue;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        continue;
    }

    foreach ($patterns as $name => $re) {
        if (preg_match($re, $raw)) {
            $hits[] = ['file' => $rel, 'pattern' => $name];
        }
    }

    // Very long single-line PHP often = obfuscated malware
    $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
    foreach ($lines as $i => $line) {
        if (strlen($line) > 2500 && (stripos($line, 'eval') !== false || stripos($line, '$_POST') !== false || stripos($line, 'base64') !== false)) {
            $hits[] = ['file' => $rel, 'pattern' => 'long_obfuscated_line:' . ($i + 1)];
            break;
        }
    }
}

echo "CivicAI webshell scan\n";
echo "Root: {$root}\n";
echo "Time: " . gmdate('c') . "\n\n";

if (!$hits && !$phpInUploads) {
    echo "OK – no known malware patterns found in scanned PHP files.\n";
    echo "NOTE: Clean local/git copy does not mean the LIVE server is clean.\n";
    echo "Re-deploy from git and scan ON THE SERVER.\n";
    exit(0);
}

if ($phpInUploads) {
    echo "CRITICAL – PHP (or script) files inside uploads/:\n";
    foreach ($phpInUploads as $f) {
        echo "  - {$f}\n";
    }
    echo "\n";
}

if ($hits) {
    echo "SUSPICIOUS matches:\n";
    foreach ($hits as $h) {
        echo "  - [{$h['pattern']}] {$h['file']}\n";
    }
    echo "\n";
}

echo "Next steps: see docs/SECURITY_INCIDENT_WEBSHELL.md\n";
exit(2);
