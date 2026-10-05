<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
bm_logout();
bm_redirect('login.php');
