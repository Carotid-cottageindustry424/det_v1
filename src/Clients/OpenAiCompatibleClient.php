<?php

namespace DetV1\Clients;

use DetV1\Contracts\AiClientInterface;

final class OpenAiCompatibleClient implements AiClientInterface
{
    public function __construct(
        private readonly string $chatUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 60
    ) {
    }

    public function chat(string $prompt, array $options = []): array
    {
        if ($this->chatUrl === '' || $this->apiKey === '' || $this->model === '') {
            return [null, 'AI 配置不完整，请检查 .env', ['stage' => 'config']];
        }

        if (!function_exists('curl_init')) {
            return [null, '当前 PHP 未启用 curl 扩展', ['stage' => 'runtime']];
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $ch = curl_init($this->chatUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => max(10, $this->timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [null, 'AI 请求失败' . ($curlErr !== '' ? ': ' . $curlErr : ''), ['http_code' => $httpCode]];
        }

        if ($httpCode !== 200) {
            return [null, 'AI 返回异常 HTTP ' . $httpCode, ['http_code' => $httpCode]];
        }

        $decoded = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [null, 'AI 返回了无效 JSON', ['http_code' => $httpCode]];
        }

        $text = '';
        if (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
            $text = trim($decoded['choices'][0]['message']['content']);
        } elseif (isset($decoded['choices'][0]['text']) && is_string($decoded['choices'][0]['text'])) {
            $text = trim($decoded['choices'][0]['text']);
        }

        if ($text === '') {
            return [null, 'AI 返回空内容', ['http_code' => $httpCode]];
        }

        return [$text, null, ['http_code' => $httpCode, 'model' => $this->model]];
    }
}
