<?php

namespace DetV1;

final class BarPayloadDecoder
{
    /**
     * @return array{0: array<int, mixed>|null, 1: string|null}
     */
    public static function decodeJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null, '数据不是合法 JSON'];
        }

        return self::unwrap($decoded);
    }

    /**
     * @return array{0: array<int, mixed>|null, 1: string|null}
     */
    public static function unwrap(mixed $decoded): array
    {
        if (is_array($decoded)) {
            if (isset($decoded['bars']) && is_array($decoded['bars'])) {
                return [$decoded['bars'], null];
            }
            if (isset($decoded['rows']) && is_array($decoded['rows'])) {
                return [$decoded['rows'], null];
            }
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                return [$decoded['data'], null];
            }
            if (array_is_list($decoded)) {
                return [$decoded, null];
            }
        }

        return [null, 'JSON 顶层必须是数组或包含 bars/data/rows'];
    }
}
