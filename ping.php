<?php
header('Content-Type: text/plain; charset=utf-8');
echo "OK\nPHP " . PHP_VERSION . "\n";

// Ha ez lefut, a szerveren a PHP működik. index.php parse error-ját így lehet kizárni:
$code = file_get_contents(__DIR__ . '/index.php');
if ($code === false) {
    echo "index.php nem olvasható.\n";
    exit;
}
// Csak azt nézzük, hogy a PHP blokk nyitva/zárva rendben van-e (nagyon egyszerű)
$open = substr_count($code, '<?php') + substr_count($code, '<?=');
$close = substr_count($code, '?>');
echo "index.php: nyitó PHP tagok ~$open, záró ~$close\n";
