<?php
declare(strict_types=1);
/** Old URL — recovery is now on the login form (password box). */
require_once __DIR__ . '/helpers.php';
bm_boot_session();
bm_redirect('login.php');
