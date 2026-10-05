<?php
/**
 * Badminton Tournament Entry — website config (Hostinger-ready)
 * Separate from School ERP. Do not mix databases or folders.
 */

declare(strict_types=1);

// Show errors only while debugging on Hostinger (set to 0 after go-live)
ini_set('display_errors', '0');
error_reporting(E_ALL);

define('BM_APP_NAME', 'Badminton Tournament Entry');
define('BM_ORG_LINE', 'District Badminton Association · Hoshiarpur');
define('BM_ROOT', __DIR__);
define('BM_DATA_DIR', BM_ROOT . '/data');
define('BM_UPLOAD_DIR', BM_DATA_DIR . '/uploads');
define('BM_BACKUP_DIR', BM_ROOT . '/backups');
define('BM_DB_PATH', BM_DATA_DIR . '/badminton.db');

// Session cookie name unique to Badminton (never shares School ERP session)
define('BM_SESSION_NAME', 'badminton_sess');

// Default admin (created only if no admin user exists)
define('BM_DEFAULT_USER', 'admin');
define('BM_DEFAULT_PASS', 'admin123');

date_default_timezone_set('Asia/Kolkata');
