<?php

namespace DetV1\Sources;

use DetV1\Contracts\DailyBarSourceInterface;

final class DemoBarSource implements DailyBarSourceInterface
{
    public function loadBars(string $symbol, array $options = []): array
    {
        $symbol = trim($symbol) !== '' ? trim($symbol) : 'DEMO001';
        $count = isset($options['count']) && is_numeric($options['count']) ? max(80, (int) $options['count']) : 160;
        $seed = abs(crc32($symbol));
        mt_srand($seed);

        $bars = [];
        $price = 12.0 + (($seed % 500) / 100.0);
        $startTs = strtotime('-' . ($count + 40) . ' day');
        $i = 0;

        while (count($bars) < $count) {
            $ts = strtotime('+' . $i . ' day', $startTs);
            $i++;
            $weekday = (int) date('N', $ts);
            if ($weekday >= 6) {
                continue;
            }

            $drift = sin((float) $i / 11.0) * 0.004 + cos((float) $i / 23.0) * 0.002;
            $noise = (mt_rand(-90, 90) / 10000.0);
            $ret = $drift + $noise;

            $open = $price;
            $close = max(0.5, $open * (1.0 + $ret));
            $high = max($open, $close) * (1.0 + mt_rand(5, 70) / 1000.0);
            $low = min($open, $close) * (1.0 - mt_rand(5, 60) / 1000.0);
            $volume = 800000 + mt_rand(0, 2500000);

            $bars[] = [
                'date' => date('Y-m-d', $ts),
                'open' => round($open, 4),
                'high' => round($high, 4),
                'low' => round($low, 4),
                'close' => round($close, 4),
                'volume' => $volume,
            ];

            $price = $close;
        }

        return [$bars, null, ['source' => 'demo', 'count' => count($bars), 'seed' => $seed]];
    }
}
