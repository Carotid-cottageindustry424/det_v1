<?php

namespace DetV1\Sources;

use DetV1\BarPayloadDecoder;
use DetV1\Contracts\DailyBarSourceInterface;

final class FileBarSource implements DailyBarSourceInterface
{
    public function loadBars(string $symbol, array $options = []): array
    {
        $file = trim((string) ($options['file'] ?? ''));
        if ($file === '') {
            return [null, '未配置 DET_V1_DATA_FILE', ['source' => 'file']];
        }

        if (!is_file($file)) {
            return [null, '数据文件不存在: ' . $file, ['source' => 'file', 'file' => $file]];
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [null, '数据文件为空', ['source' => 'file', 'file' => $file]];
        }

        [$decoded, $decodeErr] = BarPayloadDecoder::decodeJson($raw);
        if ($decoded === null) {
            $error = $decodeErr === '数据不是合法 JSON' ? '数据文件不是合法 JSON' : ($decodeErr ?? '数据文件解码失败');
            return [null, $error, ['source' => 'file', 'file' => $file]];
        }

        return [$decoded, null, ['source' => 'file', 'file' => $file, 'symbol' => $symbol]];
    }
}
