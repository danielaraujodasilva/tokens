<?php
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));

$localConfig = __DIR__ . '/local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

defined('DEPLOY_WEBHOOK_SECRET') || define('DEPLOY_WEBHOOK_SECRET', 'troque-este-segredo-no-servidor');
defined('DEPLOY_BRANCH') || define('DEPLOY_BRANCH', 'main');
defined('DEPLOY_REPO_PATH') || define('DEPLOY_REPO_PATH', ROOT_PATH);
defined('GIT_PATH') || define('GIT_PATH', 'git');
defined('BASE_URL') || define('BASE_URL', '/tokens');
