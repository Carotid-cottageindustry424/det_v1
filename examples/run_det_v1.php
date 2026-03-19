<?php

require_once dirname(__DIR__) . '/bootstrap.php';

use DetV1\DetV1Runner;
use DetV1\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');

$symbol = $argv[1] ?? EnvLoader::get('DET_V1_SYMBOL', 'DEMO001');
$withAi = in_array('--with-ai', $argv, true) || EnvLoader::getBool('DET_V1_AI_ENABLED', false);
$runner = new DetV1Runner();
$analysis = $runner->analyzeSymbol((string) $symbol, ['with_ai' => $withAi]);

if (empty($analysis['ok'])) {
    fwrite(STDERR, "加载或分析失败: " . (string) ($analysis['error'] ?? '未知错误') . "\n");
    exit(1);
}

echo json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
