<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_require_login();

if (isset($_POST['create_backup'])) {
    bm_db(); // ensure folders exist
    $stamp = date('Ymd_His');
    $zipName = 'Badminton_backup_' . $stamp . '.zip';
    $zipPath = BM_BACKUP_DIR . '/' . $zipName;

    if (!class_exists('ZipArchive')) {
        bm_flash('error', 'ZipArchive PHP extension is required on the server.');
        bm_redirect('backup.php');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        bm_flash('error', 'Could not create backup ZIP.');
        bm_redirect('backup.php');
    }

    $skipDirs = ['.git', 'backups', 'vendor', 'node_modules'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BM_ROOT, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $full = $file->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($full, strlen(BM_ROOT))), '/');
        $parts = explode('/', $rel);
        if ($parts && in_array($parts[0], $skipDirs, true)) {
            continue;
        }
        if (str_contains($rel, '/.git/') || str_starts_with($rel, '.git/')) {
            continue;
        }
        $zip->addFile($full, $rel);
    }
    $zip->close();
    bm_flash('success', 'Backup created: ' . $zipName);
    bm_redirect('backup.php');
}

if (isset($_GET['download'])) {
    $safe = basename((string)$_GET['download']);
    $path = BM_BACKUP_DIR . '/' . $safe;
    if (!is_file($path) || !str_ends_with(strtolower($safe), '.zip')) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

if (isset($_GET['db'])) {
    bm_db();
    if (!is_file(BM_DB_PATH)) {
        http_response_code(404);
        exit('Not found');
    }
    $name = 'badminton_db_' . date('Ymd_His') . '.db';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string)filesize(BM_DB_PATH));
    readfile(BM_DB_PATH);
    exit;
}

$backups = [];
foreach (glob(BM_BACKUP_DIR . '/*.zip') ?: [] as $path) {
    $backups[] = [
        'name' => basename($path),
        'size_kb' => round(filesize($path) / 1024, 1),
        'mtime' => date('d-m-Y H:i', filemtime($path)),
        'mtime_raw' => filemtime($path),
    ];
}
usort($backups, static fn($a, $b) => $b['mtime_raw'] <=> $a['mtime_raw']);
$backups = array_slice($backups, 0, 20);

$pageTitle = 'Backup · Badminton';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div>
    <p class="eyebrow">Safety</p>
    <h1>Backup</h1>
    <p class="lede">Download database and source code to your laptop. Keep a copy on USB or another folder.</p>
  </div>
</section>
<section class="panel">
  <div class="backup-actions">
    <form method="post"><button type="submit" name="create_backup" value="1" class="btn btn-primary">Create full backup ZIP</button></form>
    <a class="btn" href="backup.php?db=1">Download database only (.db)</a>
  </div>
  <p class="hint">Full ZIP includes database, uploaded files, and website source code.</p>
</section>
<section class="panel">
  <div class="panel-head"><h2>Recent backups</h2></div>
  <?php if ($backups): ?>
  <table class="table">
    <thead><tr><th>File</th><th>Size</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
      <tr>
        <td><?= bm_h($b['name']) ?></td>
        <td><?= bm_h((string)$b['size_kb']) ?> KB</td>
        <td><?= bm_h($b['mtime']) ?></td>
        <td class="right"><a class="btn btn-sm" href="backup.php?download=<?= urlencode($b['name']) ?>">Download</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="empty">No backup created yet.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
