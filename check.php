<?php
/**
 * Temporary Hostinger health check for Badminton.
 * Open: https://YOUR-SITE/check.php
 * DELETE this file after the site works.
 */
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "Badminton Hostinger check\n";
echo "=========================\n\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Document root: " . (__DIR__) . "\n\n";

echo "PDO: " . (extension_loaded('pdo') ? 'YES' : 'NO') . "\n";
echo "PDO SQLite: " . (extension_loaded('pdo_sqlite') ? 'YES' : 'NO') . "\n";
echo "SQLite3: " . (extension_loaded('sqlite3') ? 'YES' : 'NO') . "\n";
echo "ZipArchive: " . (class_exists('ZipArchive') ? 'YES' : 'NO') . "\n\n";

$data = __DIR__ . '/data';
$uploads = $data . '/uploads';
$backups = __DIR__ . '/backups';

echo "data exists: " . (is_dir($data) ? 'YES' : 'NO') . "\n";
echo "data writable: " . (is_dir($data) && is_writable($data) ? 'YES' : 'NO') . "\n";
echo "uploads exists: " . (is_dir($uploads) ? 'YES' : 'NO') . "\n";
echo "uploads writable: " . (is_dir($uploads) && is_writable($uploads) ? 'YES' : 'NO') . "\n";
echo "backups exists: " . (is_dir($backups) ? 'YES' : 'NO') . "\n";
echo "backups writable: " . (is_dir($backups) && is_writable($backups) ? 'YES' : 'NO') . "\n\n";

if (!extension_loaded('pdo_sqlite')) {
    echo "FAIL: Enable PDO SQLite in Hostinger PHP settings.\n";
    echo "hPanel → Advanced → PHP Configuration → Extensions → pdo_sqlite ON\n";
    exit;
}

try {
    if (!is_dir($data)) {
        mkdir($data, 0755, true);
    }
    if (!is_dir($uploads)) {
        mkdir($uploads, 0755, true);
    }
    if (!is_dir($backups)) {
        mkdir($backups, 0755, true);
    }
    $dbPath = $data . '/badminton.db';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS _healthcheck (id INTEGER PRIMARY KEY)');
    echo "SQLite create/write: YES\n";
    echo "DB path: $dbPath\n";
    echo "\nOK — database works. Now open login.php\n";
} catch (Throwable $e) {
    echo "FAIL SQLite: " . $e->getMessage() . "\n";
    echo "Fix: set data/ and data/uploads and backups to permission 755 or 775\n";
}
