<?php

require_once dirname(__DIR__) . '/bootstrap.php';

use DetV1\DetV1Runner;
use DetV1\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');

$runner = new DetV1Runner();
$bootstrap = $runner->buildWebBootstrap();

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'app' => 'det_v1',
    'php_version' => PHP_VERSION,
    'curl_enabled' => function_exists('curl_init'),
    'available_modes' => $bootstrap['available_modes'] ?? ['demo'],
    'ai_available' => $bootstrap['ai_available'] ?? false,
    'checked_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
