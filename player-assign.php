<?php
declare(strict_types=1);
/** Old global assign page — category/event is now chosen per tournament. */
require_once __DIR__ . '/helpers.php';
bm_require_login();
bm_flash('success', 'Age category and events are selected inside each tournament, not on the players list.');
bm_redirect('players.php');
