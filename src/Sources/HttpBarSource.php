<?php

namespace DetV1\Sources;

use DetV1\BarPayloadDecoder;
use DetV1\Contracts\DailyBarSourceInterface;

final class HttpBarSource implements DailyBarSourceInterface
{
    public function loadBars(string $symbol, array $options = []): array
    {
        $template = trim((string) ($options['url_template'] ?? ''));
        if ($template === '') {
            return [null, '未配置 DET_V1_DATA_URL_TEMPLATE', ['source' => 'http']];
        }

        $timeout = isset($options['timeout']) && is_numeric($options['timeout']) ? max(5, (int) $options['timeout']) : 20;
        $url = str_replace('{symbol}', rawurlencode($symbol), $template);
        $headers = [];

        if (!empty($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $key => $value) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $headers[] = $key . ': ' . trim((string) $value);
            }
        }

        if (!function_exists('curl_init')) {
            return [null, '当前 PHP 未启用 curl 扩展', ['source' => 'http', 'url' => $url]];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [null, 'HTTP 请求失败' . ($curlErr !== '' ? ': ' . $curlErr : ''), ['source' => 'http', 'url' => $url, 'http_code' => $httpCode]];
        }

        if ($httpCode !== 200) {
            return [null, 'HTTP 状态异常: ' . $httpCode, ['source' => 'http', 'url' => $url, 'http_code' => $httpCode]];
        }

        [$decoded, $decodeErr] = BarPayloadDecoder::decodeJson((string) $response);
        if ($decoded === null) {
            $error = $decodeErr === '数据不是合法 JSON'
                ? '上游未返回合法 JSON'
                : '上游 JSON 顶层必须是数组或包含 bars/data/rows';
            return [null, $error, ['source' => 'http', 'url' => $url, 'http_code' => $httpCode]];
        }

        return [$decoded, null, ['source' => 'http', 'url' => $url, 'http_code' => $httpCode, 'symbol' => $symbol]];
    }
}
