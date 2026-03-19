<?php

namespace DetV1;

use DetV1\Clients\OpenAiCompatibleClient;
use DetV1\Contracts\DailyBarSourceInterface;
use DetV1\Sources\DemoBarSource;
use DetV1\Sources\FileBarSource;
use DetV1\Sources\HttpBarSource;

final class DetV1Runner
{
    public function __construct(
        private readonly ?DetV1Model $model = null,
        private readonly ?DetV1Explainer $explainer = null
    ) {
    }

    public function analyzeSymbol(string $symbol, array $options = []): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        $dataMode = $this->normalizeMode((string) ($options['data_mode'] ?? EnvLoader::get('DET_V1_DATA_MODE', 'demo')));
        $source = $this->makeSource($dataMode);

        [$bars, $loadErr, $loadMeta] = $source->loadBars($symbol, $this->buildSourceOptions($options));
        if ($bars === null) {
            return [
                'ok' => false,
                'error' => $loadErr ?? '加载数据失败',
                'symbol' => $symbol,
                'data_mode' => $dataMode,
                'load_meta' => is_array($loadMeta) ? $loadMeta : [],
                'local_explanation' => 'det_v1 分析失败：' . ($loadErr ?? '加载数据失败'),
            ];
        }

        return $this->analyzeBars($symbol, $bars, [
            'data_mode' => $dataMode,
            'load_meta' => $loadMeta,
            'with_ai' => $this->shouldUseAi($options),
            'model_options' => $options['model_options'] ?? $options,
        ]);
    }

    public function analyzeBars(string $symbol, array $bars, array $options = []): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        $analysis = $this->modelInstance()->analyze($bars, $this->buildModelOptions($options));
        $analysis['symbol'] = $symbol;
        $analysis['data_mode'] = (string) ($options['data_mode'] ?? 'upload');
        $analysis['load_meta'] = is_array($options['load_meta'] ?? null) ? $options['load_meta'] : [];
        $analysis['local_explanation'] = $this->explainerInstance()->buildLocalExplanation($symbol, $analysis);

        if ($this->shouldUseAi($options)) {
            $client = new OpenAiCompatibleClient(
                (string) EnvLoader::get('DET_V1_AI_CHAT_URL', ''),
                (string) EnvLoader::get('DET_V1_AI_API_KEY', ''),
                (string) EnvLoader::get('DET_V1_AI_MODEL', ''),
                EnvLoader::getInt('DET_V1_AI_TIMEOUT', 60)
            );
            [$aiText, $aiErr, $aiMeta] = $this->explainerInstance()->explainWithAi($client, $symbol, $analysis);
            $analysis['ai_explanation'] = $aiText;
            $analysis['ai_error'] = $aiErr;
            $analysis['ai_meta'] = $aiMeta;
        }

        return $analysis;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildWebBootstrap(): array
    {
        $availableModes = ['demo'];
        if (EnvLoader::getBool('DET_V1_WEB_ALLOW_UPLOAD', true)) {
            $availableModes[] = 'upload';
        }
        if (trim((string) EnvLoader::get('DET_V1_DATA_FILE', '')) !== '') {
            $availableModes[] = 'file';
        }
        if (trim((string) EnvLoader::get('DET_V1_DATA_URL_TEMPLATE', '')) !== '') {
            $availableModes[] = 'http';
        }

        $defaults = $this->buildModelOptions([]);
        $defaultMode = $this->normalizeMode((string) EnvLoader::get('DET_V1_DATA_MODE', 'demo'));
        if (!in_array($defaultMode, $availableModes, true)) {
            $defaultMode = $availableModes[0];
        }

        return [
            'app_name' => 'det_v1 工作台',
            'default_symbol' => $this->normalizeSymbol((string) EnvLoader::get('DET_V1_SYMBOL', 'DEMO001')),
            'default_mode' => $defaultMode,
            'available_modes' => $availableModes,
            'ai_available' => $this->isAiAvailable(),
            'ai_enabled_default' => EnvLoader::getBool('DET_V1_AI_ENABLED', false) && $this->isAiAvailable(),
            'model_defaults' => $defaults,
            'max_upload_bytes' => $this->maxUploadBytes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSourceOptions(array $options): array
    {
        $modelOptions = is_array($options['source_options'] ?? null) ? $options['source_options'] : $options;
        $headers = $modelOptions['headers'] ?? $this->decodeHeadersJson((string) EnvLoader::get('DET_V1_DATA_HEADERS_JSON', '{}'));

        return [
            'file' => (string) ($modelOptions['file'] ?? EnvLoader::get('DET_V1_DATA_FILE', '')),
            'url_template' => (string) ($modelOptions['url_template'] ?? EnvLoader::get('DET_V1_DATA_URL_TEMPLATE', '')),
            'headers' => is_array($headers) ? $headers : [],
            'timeout' => $this->toInt($modelOptions['timeout'] ?? null, EnvLoader::getInt('DET_V1_DATA_TIMEOUT', 20)),
            'count' => $this->toInt($modelOptions['count'] ?? null, EnvLoader::getInt('DET_V1_DEMO_COUNT', 160)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildModelOptions(array $options): array
    {
        $modelOptions = is_array($options['model_options'] ?? null) ? $options['model_options'] : $options;
        $horizons = $this->parseHorizons($modelOptions['hs'] ?? ($modelOptions['horizons'] ?? EnvLoader::get('DET_V1_HS', '1,3,5,10,20')));

        return [
            'primary_horizon' => $this->toInt($modelOptions['primary_horizon'] ?? null, EnvLoader::getInt('DET_V1_PRIMARY_HORIZON', 5)),
            'buy_p_up' => $this->toFloat($modelOptions['buy_p_up'] ?? null, EnvLoader::getFloat('DET_V1_BUY_P_UP', 0.60)),
            'buy_er' => $this->toFloat($modelOptions['buy_er'] ?? null, EnvLoader::getFloat('DET_V1_BUY_ER', 0.015)),
            'avoid_p_up' => $this->toFloat($modelOptions['avoid_p_up'] ?? null, EnvLoader::getFloat('DET_V1_AVOID_P_UP', 0.45)),
            'avoid_er' => $this->toFloat($modelOptions['avoid_er'] ?? null, EnvLoader::getFloat('DET_V1_AVOID_ER', -0.010)),
            'min_match_n' => $this->toInt($modelOptions['min_match_n'] ?? null, EnvLoader::getInt('DET_V1_MIN_MATCH_N', 25)),
            'hit_pct' => $this->toFloat($modelOptions['hit_pct'] ?? null, EnvLoader::getFloat('DET_V1_HIT_PCT', 0.03)),
            'hs' => empty($horizons) ? [1, 3, 5, 10, 20] : $horizons,
        ];
    }

    private function modelInstance(): DetV1Model
    {
        return $this->model ?? new DetV1Model();
    }

    private function explainerInstance(): DetV1Explainer
    {
        return $this->explainer ?? new DetV1Explainer();
    }

    private function makeSource(string $mode): DailyBarSourceInterface
    {
        return match ($mode) {
            'file' => new FileBarSource(),
            'http' => new HttpBarSource(),
            default => new DemoBarSource(),
        };
    }

    private function shouldUseAi(array $options): bool
    {
        if (array_key_exists('with_ai', $options)) {
            return $this->toBool($options['with_ai'], false);
        }

        return EnvLoader::getBool('DET_V1_AI_ENABLED', false);
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = trim($symbol);
        return $symbol === '' ? 'DEMO001' : $symbol;
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['demo', 'file', 'http', 'upload'], true) ? $mode : 'demo';
    }

    /**
     * @return array<int, int>
     */
    private function parseHorizons(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[^\d]+/', (string) $value) ?: [];
        }

        $set = [];
        foreach ($items as $item) {
            if (!is_numeric($item)) {
                continue;
            }
            $h = (int) $item;
            if ($h > 0 && $h <= 120) {
                $set[$h] = true;
            }
        }

        $horizons = array_keys($set);
        sort($horizons);
        return $horizons;
    }

    /**
     * @return array<string, string>
     */
    private function decodeHeadersJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $headers = [];
        foreach ($decoded as $key => $value) {
            $name = trim((string) $key);
            if ($name === '') {
                continue;
            }
            $headers[$name] = trim((string) $value);
        }

        return $headers;
    }

    private function isAiAvailable(): bool
    {
        return function_exists('curl_init')
            && trim((string) EnvLoader::get('DET_V1_AI_CHAT_URL', '')) !== ''
            && trim((string) EnvLoader::get('DET_V1_AI_API_KEY', '')) !== ''
            && trim((string) EnvLoader::get('DET_V1_AI_MODEL', '')) !== '';
    }

    private function maxUploadBytes(): int
    {
        $limit = EnvLoader::getInt('DET_V1_WEB_MAX_INPUT_BYTES', 2 * 1024 * 1024);
        $postMax = $this->iniBytes((string) ini_get('post_max_size'));
        $uploadMax = $this->iniBytes((string) ini_get('upload_max_filesize'));

        if ($postMax > 0) {
            $limit = min($limit, $postMax);
        }
        if ($uploadMax > 0) {
            $limit = min($limit, $uploadMax);
        }

        return $limit > 0 ? $limit : (2 * 1024 * 1024);
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)([KMG]?)$/i', $value, $matches)) {
            return 0;
        }

        $num = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'G' => $num * 1024 * 1024 * 1024,
            'M' => $num * 1024 * 1024,
            'K' => $num * 1024,
            default => $num,
        };
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = strtolower(trim((string) $value));
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private function toInt(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return (int) round((float) $value);
    }

    private function toFloat(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $num = (float) $value;
        return is_finite($num) ? $num : $default;
    }
}
