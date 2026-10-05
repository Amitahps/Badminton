<?php
declare(strict_types=1);
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? BM_APP_NAME;
$flashes = bm_get_flashes();
$loggedIn = bm_is_logged_in();
$script = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= bm_h($pageTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Libre+Baskerville:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php if ($loggedIn): ?>
<header class="topbar">
  <a class="brand" href="index.php">
    <span class="brand-mark" aria-hidden="true"></span>
    <span class="brand-text">
      <strong>Badminton</strong>
      <small>Tournament Entry · Hoshiarpur</small>
    </span>
  </a>
  <nav class="nav">
    <a href="index.php" class="<?= $script === 'index.php' ? 'active' : '' ?>">Home</a>
    <a href="categories.php" class="<?= str_starts_with($script, 'categor') ? 'active' : '' ?>">Age Categories</a>
    <a href="players.php" class="<?= str_starts_with($script, 'player') ? 'active' : '' ?>">Players</a>
    <a href="tournaments.php" class="<?= str_starts_with($script, 'tournament') || $script === 'letter.php' ? 'active' : '' ?>">Tournaments</a>
    <a href="backup.php" class="<?= $script === 'backup.php' ? 'active' : '' ?>">Backup</a>
    <a href="change-password.php">Password</a>
    <a class="logout" href="logout.php">Logout</a>
  </nav>
</header>
<?php endif; ?>
<main class="wrap <?= !empty($wrapClass) ? bm_h($wrapClass) : '' ?>">
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= bm_h($f['type']) ?>"><?= bm_h($f['message']) ?></div>
  <?php endforeach; ?>
