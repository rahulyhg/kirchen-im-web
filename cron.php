<?php
use KirchenImWeb\Helpers\Exporter;
use KirchenImWeb\Updaters\LinkCheckUpdater;
use KirchenImWeb\Updaters\SocialMediaUpdater;

require __DIR__ . '/src/autoload.php';

// Export into CSV and JSON.
Exporter::getInstance()->export();

// Check for broken links.
$l = new LinkCheckUpdater();
$l->check();

// Get follower data.
header('Content-Type: application/json;charset=utf-8');
echo json_encode(SocialMediaUpdater::getInstance()->cron());
