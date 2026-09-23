<?php
/**
 * M0 repository audit – scan for mock/stub/reference patterns.
 * Run: php tests/audit_m0_repository.php
 */
$root = dirname(__DIR__);
$patterns = ['TODO', 'FIXME', 'MOCK', 'DUMMY', 'FAKE', 'PLACEHOLDER', 'NOT_IMPLEMENTED', 'STUB', 'using_reference', 'preview_reference', 'reference_only'];
$hits = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (str_contains($path, 'dashboard' . DIRECTORY_SEPARATOR . 'node_modules')) {
        continue;
    }
    if (str_contains($path, '.git' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    if (!preg_match('/\.(php|js|md|sql)$/', $path)) {
        continue;
    }
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $content = @file_get_contents($path);
    if ($content === false) {
        continue;
    }
    foreach ($patterns as $pat) {
        if (stripos($content, $pat) !== false) {
            $hits[$rel][] = $pat;
        }
    }
}

echo "M0 Audit – pattern hits in " . count($hits) . " files\n\n";
foreach ($hits as $file => $pats) {
    echo $file . ': ' . implode(', ', array_unique($pats)) . "\n";
}

$critical = [
    'services/intelligence/ViirsDataService.php',
    'services/intelligence/HungaroMetDataService.php',
    'services/intelligence/GbifDataService.php',
    'services/intelligence/OpenChargeMapDataService.php',
];
echo "\nCritical reference services (must not feed City Intelligence as MEASURED):\n";
foreach ($critical as $c) {
    echo (isset($hits[$c]) ? 'HIT ' : 'ok  ') . $c . "\n";
}

exit(0);
