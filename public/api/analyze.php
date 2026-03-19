<?php

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use DetV1\BarPayloadDecoder;
use DetV1\DetV1Runner;
use DetV1\EnvLoader;

EnvLoader::load(dirname(__DIR__, 2) . '/.env');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['ok' => false, 'error' => '只接受 POST']);
}

$runner = new DetV1Runner();
$bootstrap = $runner->buildWebBootstrap();
$availableModes = is_array($bootstrap['available_modes'] ?? null) ? $bootstrap['available_modes'] : ['demo'];
$mode = strtolower(trim((string) ($_POST['mode'] ?? ($bootstrap['default_mode'] ?? 'demo'))));

if (!in_array($mode, $availableModes, true)) {
    respond(400, ['ok' => false, 'error' => '当前模式不可用']);
}

$withAi = toBool($_POST['with_ai'] ?? '0', false);
if ($withAi && empty($bootstrap['ai_available'])) {
    respond(400, ['ok' => false, 'error' => 'AI 解释当前不可用']);
}

$symbol = trim((string) ($_POST['symbol'] ?? ($bootstrap['default_symbol'] ?? 'DEMO001')));
if ($symbol === '') {
    $symbol = (string) ($bootstrap['default_symbol'] ?? 'DEMO001');
}

$modelOptions = [
    'primary_horizon' => toInt($_POST['primary_horizon'] ?? null),
    'horizons' => trim((string) ($_POST['horizons'] ?? '')),
    'min_match_n' => toInt($_POST['min_match_n'] ?? null),
    'hit_pct' => toFloat($_POST['hit_pct'] ?? null),
    'buy_p_up' => toFloat($_POST['buy_p_up'] ?? null),
    'buy_er' => toFloat($_POST['buy_er'] ?? null),
    'avoid_p_up' => toFloat($_POST['avoid_p_up'] ?? null),
    'avoid_er' => toFloat($_POST['avoid_er'] ?? null),
];

if ($mode === 'upload') {
    [$bars, $uploadErr, $uploadMeta] = readUploadedBars((int) ($bootstrap['max_upload_bytes'] ?? 2097152));
    if ($bars === null) {
        respond(400, ['ok' => false, 'error' => $uploadErr ?? '上传内容无效']);
    }

    $analysis = $runner->analyzeBars($symbol, $bars, [
        'data_mode' => 'upload',
        'with_ai' => $withAi,
        'load_meta' => $uploadMeta,
        'model_options' => $modelOptions,
    ]);
} else {
    $sourceOptions = [];
    if ($mode === 'demo') {
        $sourceOptions['count'] = toInt($_POST['count'] ?? null);
    }

    $analysis = $runner->analyzeSymbol($symbol, [
        'data_mode' => $mode,
        'with_ai' => $withAi,
        'source_options' => $sourceOptions,
        'model_options' => $modelOptions,
    ]);
}

$analysis['request'] = [
    'mode' => $mode,
    'with_ai' => $withAi,
];

respond(!empty($analysis['ok']) ? 200 : 422, $analysis);

/**
 * @return array{0: array<int, mixed>|null, 1: string|null, 2: array<string, mixed>}
 */
function readUploadedBars(int $maxBytes): array
{
    if (isset($_FILES['bars_file']) && is_array($_FILES['bars_file'])) {
        $file = $_FILES['bars_file'];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_NO_FILE) {
            if ($error !== UPLOAD_ERR_OK) {
                return [null, uploadErrorMessage($error), ['source' => 'upload']];
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0) {
                return [null, '上传文件为空', ['source' => 'upload']];
            }
            if ($maxBytes > 0 && $size > $maxBytes) {
                return [null, '上传文件超过限制', ['source' => 'upload', 'size' => $size]];
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            $raw = $tmpName !== '' ? @file_get_contents($tmpName) : false;
            if (!is_string($raw) || trim($raw) === '') {
                return [null, '无法读取上传文件', ['source' => 'upload']];
            }

            [$bars, $decodeErr] = BarPayloadDecoder::decodeJson($raw);
            if ($bars === null) {
                return [null, $decodeErr ?? '上传文件不是合法 JSON', ['source' => 'upload', 'size' => $size]];
            }

            return [$bars, null, ['source' => 'upload', 'channel' => 'file', 'size' => $size]];
        }
    }

    $raw = trim((string) ($_POST['bars_json'] ?? ''));
    if ($raw === '') {
        return [null, '请上传 JSON 文件或粘贴 JSON 文本', ['source' => 'upload']];
    }

    $size = strlen($raw);
    if ($maxBytes > 0 && $size > $maxBytes) {
        return [null, '粘贴内容超过限制', ['source' => 'upload', 'size' => $size]];
    }

    [$bars, $decodeErr] = BarPayloadDecoder::decodeJson($raw);
    if ($bars === null) {
        return [null, $decodeErr ?? 'JSON 内容无效', ['source' => 'upload', 'size' => $size]];
    }

    return [$bars, null, ['source' => 'upload', 'channel' => 'textarea', 'size' => $size]];
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '上传文件超过限制',
        UPLOAD_ERR_PARTIAL => '上传文件不完整',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '服务器无法写入上传临时文件',
        UPLOAD_ERR_EXTENSION => '上传被 PHP 扩展中断',
        default => '上传失败',
    };
}

function toBool(mixed $value, bool $default): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $text = strtolower(trim((string) $value));
    if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($text, ['0', 'false', 'no', 'off', ''], true)) {
        return false;
    }

    return $default;
}

function toInt(mixed $value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    return (int) round((float) $value);
}

function toFloat(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    $num = (float) $value;
    return is_finite($num) ? $num : null;
}
