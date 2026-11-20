<?php
// Nur fuer die CI-Diagnose auf dem Branch ci/php-cgi; wird nicht gemergt.
require __DIR__ . '/../vendor/autoload.php';
$app = \TeacherOS\Tests\Support\HttpTestApp::boot();
foreach ([['GET', '/api/me'], ['GET', '/api/health']] as [$m, $u]) {
    $r = $app->request($m, $u);
    echo "=== $m $u status={$r['status']} json=" . ($r['json'] === null ? 'NULL' : 'ok') . "\n";
    echo substr($r['body'], 0, 1500), "\n";
}
echo "=== php-cgi: ", PHP_BINARY, "\n";
