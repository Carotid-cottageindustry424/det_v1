<?php

namespace DetV1;

final class DetV1Model
{
    public function analyze(array $bars, array $options = []): array
    {
        $normalized = $this->normalizeBars($bars);
        if (count($normalized) < 60) {
            return [
                'ok' => false,
                'error' => '日线数据不足，det_v1 至少需要 60 根 K 线',
                'bar_count' => count($normalized),
            ];
        }

        $last = $normalized[count($normalized) - 1];
        $asofYmd = (string) $last['date'];
        $targetsPack = $this->deterministicTargetsFromDailyBars($normalized, $asofYmd, $options);
        $targets = $targetsPack['targets'] ?? [];
        if (empty($targets)) {
            return [
                'ok' => false,
                'error' => '未能生成 det_v1 targets',
                'bar_count' => count($normalized),
                'meta' => $targetsPack['meta'] ?? [],
            ];
        }

        $state = $this->buildCurrentState($normalized);
        $decision = $this->buildLearningDecision($targets, $options);

        return [
            'ok' => true,
            'asof' => $asofYmd,
            'bar_count' => count($normalized),
            'state' => $state,
            'targets' => $targets,
            'decision' => $decision,
            'meta' => $targetsPack['meta'] ?? [],
            'summary' => $this->buildSummary($state, $targets, $decision),
        ];
    }

    /**
     * @param array<int, mixed> $bars
     * @return array<int, array<string, float|string|null>>
     */
    public function normalizeBars(array $bars): array
    {
        $rows = [];
        foreach ($bars as $row) {
            $normalized = $this->normalizeBarRow($row);
            if ($normalized === null) {
                continue;
            }
            $rows[] = $normalized;
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp((string) $a['date'], (string) $b['date']);
        });

        return $rows;
    }

    /**
     * @param mixed $row
     * @return array<string, float|string|null>|null
     */
    private function normalizeBarRow(mixed $row): ?array
    {
        if (is_array($row) && array_is_list($row)) {
            $date = $this->normalizeDate($row[0] ?? null);
            $open = $this->safeFloat($row[1] ?? null);
            $high = $this->safeFloat($row[2] ?? null);
            $low = $this->safeFloat($row[3] ?? null);
            $close = $this->safeFloat($row[4] ?? null);
            $volume = $this->safeFloat($row[5] ?? null);
        } elseif (is_array($row)) {
            $date = $this->normalizeDate($row['date'] ?? ($row['日期'] ?? null));
            $open = $this->safeFloat($row['open'] ?? ($row['开盘'] ?? null));
            $high = $this->safeFloat($row['high'] ?? ($row['最高'] ?? null));
            $low = $this->safeFloat($row['low'] ?? ($row['最低'] ?? null));
            $close = $this->safeFloat($row['close'] ?? ($row['收盘'] ?? null));
            $volume = $this->safeFloat($row['volume'] ?? ($row['成交量'] ?? null));
        } else {
            return null;
        }

        if ($date === null || $high === null || $low === null || $close === null) {
            return null;
        }

        return [
            'date' => $date,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
        ];
    }

    private function buildCurrentState(array $bars): array
    {
        $closes = array_map(static fn(array $row): ?float => isset($row['close']) && is_numeric($row['close']) ? (float) $row['close'] : null, $bars);
        $ma20 = $this->maSeries($closes, 20);
        $rsi14 = $this->rsiSeries($closes, 14);
        $macdHist = $this->macdHistSeries($closes, 12, 26, 9);

        $idx = count($bars) - 1;
        $close = $closes[$idx] ?? null;
        $ma = $ma20[$idx] ?? null;
        $macd = $macdHist[$idx] ?? null;
        $rsi = $rsi14[$idx] ?? null;

        $aboveMa20 = null;
        if (is_numeric($close) && is_numeric($ma) && (float) $ma > 0.0) {
            $aboveMa20 = (float) $close >= (float) $ma;
        }

        return [
            'close' => $close,
            'ma20' => $ma,
            'macd_hist' => $macd,
            'rsi14' => $rsi,
            'above_ma20' => $aboveMa20,
            'feature_key' => $this->featureKey($aboveMa20, $macd, $rsi),
        ];
    }

    private function buildLearningDecision(array $targets, array $options = []): array
    {
        $horizon = isset($options['primary_horizon']) && is_numeric($options['primary_horizon'])
            ? max(1, (int) $options['primary_horizon'])
            : 5;
        $buyPUp = isset($options['buy_p_up']) && is_numeric($options['buy_p_up']) ? (float) $options['buy_p_up'] : 0.60;
        $buyEr = isset($options['buy_er']) && is_numeric($options['buy_er']) ? (float) $options['buy_er'] : 0.015;
        $avoidPUp = isset($options['avoid_p_up']) && is_numeric($options['avoid_p_up']) ? (float) $options['avoid_p_up'] : 0.45;
        $avoidEr = isset($options['avoid_er']) && is_numeric($options['avoid_er']) ? (float) $options['avoid_er'] : -0.010;

        $target = null;
        foreach ($targets as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['h'] ?? 0) === $horizon) {
                $target = $row;
                break;
            }
        }

        if (!is_array($target)) {
            $target = is_array($targets[0] ?? null) ? $targets[0] : null;
        }

        if (!is_array($target)) {
            return [
                'primary_horizon' => $horizon,
                'action' => 'watch',
                'score' => null,
                'reason' => '缺少主决策 horizon 结果',
            ];
        }

        $pUp = $this->safeFloat($target['p_up'] ?? null);
        $er = $this->safeFloat($target['er'] ?? null);
        $score = null;
        if ($pUp !== null && $er !== null) {
            $score = (int) round(50.0 + (($pUp - 0.5) * 110.0) + ($er * 700.0));
            $score = max(1, min(100, $score));
        }

        $action = 'watch';
        $reason = '教学门禁判定为继续观察';
        if ($pUp !== null && $er !== null) {
            $labelHorizon = (int) ($target['h'] ?? $horizon);
            if ($pUp >= $buyPUp && $er >= $buyEr) {
                $action = 'buy_candidate';
                $reason = $labelHorizon . ' 日胜率和期望收益都过了教学门槛';
            } elseif ($pUp <= $avoidPUp || $er <= $avoidEr) {
                $action = 'avoid';
                $reason = $labelHorizon . ' 日胜率或期望收益过弱，先回避';
            }
        }

        return [
            'primary_horizon' => (int) ($target['h'] ?? $horizon),
            'action' => $action,
            'score' => $score,
            'reason' => $reason,
            'p_up' => $pUp,
            'expected_return' => $er,
            'sample_count' => isset($target['n']) ? (int) $target['n'] : null,
        ];
    }

    private function buildSummary(array $state, array $targets, array $decision): string
    {
        $lines = [];
        $lines[] = 'det_v1 是一个日线统计匹配模型，不是黑盒神经网络。';

        if (isset($state['feature_key'])) {
            $lines[] = '当前状态键：' . (string) $state['feature_key'];
        }

        if (isset($decision['primary_horizon'], $decision['action'])) {
            $lines[] = '主判定窗口：' . (int) $decision['primary_horizon'] . ' 日，动作：' . (string) $decision['action'];
        }

        foreach ($targets as $row) {
            if (!is_array($row)) {
                continue;
            }
            $h = (int) ($row['h'] ?? 0);
            $pUp = $this->safeFloat($row['p_up'] ?? null);
            $er = $this->safeFloat($row['er'] ?? null);
            $n = isset($row['n']) ? (int) $row['n'] : 0;
            if ($h <= 0) {
                continue;
            }
            $lines[] = sprintf(
                '%d 日: p_up=%s, er=%s, n=%d',
                $h,
                $pUp === null ? '-' : number_format($pUp, 4, '.', ''),
                $er === null ? '-' : number_format($er, 4, '.', ''),
                $n
            );
        }

        return implode("\n", $lines);
    }

    private function deterministicTargetsFromDailyBars(array $bars, string $asofYmd, array $options = []): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asofYmd)) {
            return ['targets' => [], 'meta' => ['error' => 'asof 日期无效']];
        }

        $hs = isset($options['hs']) && is_array($options['hs']) ? $options['hs'] : [1, 3, 5, 10, 20];
        $hsSet = [];
        foreach ($hs as $h) {
            $h = (int) $h;
            if ($h > 0 && $h <= 120) {
                $hsSet[$h] = true;
            }
        }
        $hs = array_keys($hsSet);
        sort($hs);
        if (empty($hs)) {
            $hs = [1, 3, 5, 10, 20];
        }

        $minMatchN = isset($options['min_match_n']) && is_numeric($options['min_match_n']) ? (int) $options['min_match_n'] : 25;
        $minMatchN = max(10, min(300, $minMatchN));
        $hitPct = isset($options['hit_pct']) && is_numeric($options['hit_pct']) ? (float) $options['hit_pct'] : 0.03;
        if (!is_finite($hitPct) || $hitPct <= 0.0 || $hitPct > 0.20) {
            $hitPct = 0.03;
        }

        $dates = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        foreach ($bars as $row) {
            $dates[] = (string) $row['date'];
            $opens[] = $this->safeFloat($row['open'] ?? null);
            $highs[] = $this->safeFloat($row['high'] ?? null);
            $lows[] = $this->safeFloat($row['low'] ?? null);
            $closes[] = $this->safeFloat($row['close'] ?? null);
        }

        $n = count($dates);
        if ($n < 60) {
            return ['targets' => [], 'meta' => ['error' => 'bars 不足(需要 >= 60)']];
        }

        $asofIdx = null;
        for ($i = 0; $i < $n; $i++) {
            if ($dates[$i] <= $asofYmd) {
                $asofIdx = $i;
                continue;
            }
            break;
        }

        if ($asofIdx === null || $asofIdx < 30) {
            return ['targets' => [], 'meta' => ['error' => 'asof 位置过早']];
        }

        $ma20 = $this->maSeries($closes, 20);
        $rsi14 = $this->rsiSeries($closes, 14);
        $macdHist = $this->macdHistSeries($closes, 12, 26, 9);

        $closeA = $closes[$asofIdx] ?? null;
        $maA = $ma20[$asofIdx] ?? null;
        $aboveMaA = null;
        if (is_numeric($closeA) && is_numeric($maA) && (float) $maA > 0.0) {
            $aboveMaA = (float) $closeA >= (float) $maA;
        }
        $macdA = $macdHist[$asofIdx] ?? null;
        $rsiA = $rsi14[$asofIdx] ?? null;
        $featureKey = $this->featureKey($aboveMaA, $macdA, $rsiA);

        $matchModes = ['amr', 'am', 'a', 'all'];
        $targets = [];
        $modeMap = [];

        foreach ($hs as $horizon) {
            $best = null;
            $bestMode = null;

            foreach ($matchModes as $mode) {
                $stats = $this->collectWindowStats(
                    $asofIdx,
                    $horizon,
                    $opens,
                    $highs,
                    $lows,
                    $closes,
                    $ma20,
                    $macdHist,
                    $rsi14,
                    $aboveMaA,
                    $macdA,
                    $featureKey,
                    $mode,
                    $hitPct
                );

                if ($stats['n'] < $minMatchN) {
                    continue;
                }

                $best = $this->buildTargetRow($horizon, $stats);
                $bestMode = $mode;
                break;
            }

            if ($best === null) {
                $stats = $this->collectWindowStats(
                    $asofIdx,
                    $horizon,
                    $opens,
                    $highs,
                    $lows,
                    $closes,
                    $ma20,
                    $macdHist,
                    $rsi14,
                    $aboveMaA,
                    $macdA,
                    $featureKey,
                    'all',
                    $hitPct
                );
                $best = $this->buildTargetRow($horizon, $stats);
                $bestMode = 'all_loose';
            }

            $targets[] = $best;
            $modeMap[$horizon] = $bestMode;
        }

        return [
            'targets' => $targets,
            'meta' => [
                'asof_ymd' => $asofYmd,
                'asof_idx' => $asofIdx,
                'feature_key' => $featureKey,
                'min_match_n' => $minMatchN,
                'hit_pct' => $hitPct,
                'mode_by_horizon' => $modeMap,
            ],
        ];
    }

    private function collectWindowStats(
        int $asofIdx,
        int $horizon,
        array $opens,
        array $highs,
        array $lows,
        array $closes,
        array $ma20,
        array $macdHist,
        array $rsi14,
        ?bool $aboveMaA,
        ?float $macdA,
        string $featureKey,
        string $mode,
        float $hitPct
    ): array {
        $win = 0;
        $sumRet = 0.0;
        $nRet = 0;
        $hitUp = 0;
        $hitDown = 0;
        $iStart = max(0, $asofIdx - 260);

        for ($i = $iStart; $i <= $asofIdx - $horizon; $i++) {
            $entryIdx = $i + 1;
            $exitIdx = $i + $horizon;
            if ($entryIdx > $asofIdx || $exitIdx > $asofIdx) {
                continue;
            }

            $c = $closes[$i] ?? null;
            $ma = $ma20[$i] ?? null;
            $aboveMa = null;
            if (is_numeric($c) && is_numeric($ma) && (float) $ma > 0.0) {
                $aboveMa = (float) $c >= (float) $ma;
            }
            $macd = $macdHist[$i] ?? null;
            $rsi = $rsi14[$i] ?? null;

            if ($mode === 'amr' && $this->featureKey($aboveMa, $macd, $rsi) !== $featureKey) {
                continue;
            }
            if ($mode === 'am' && (($aboveMaA !== null && $aboveMa !== null && $aboveMaA !== $aboveMa) || $this->bucket01($macd) !== $this->bucket01($macdA))) {
                continue;
            }
            if ($mode === 'a' && $aboveMaA !== null && $aboveMa !== null && $aboveMaA !== $aboveMa) {
                continue;
            }

            $entry = $opens[$entryIdx] ?? null;
            if (!is_numeric($entry) || (float) $entry <= 0.0) {
                $entry = $closes[$entryIdx] ?? null;
            }
            $exit = $closes[$exitIdx] ?? null;
            if (!is_numeric($entry) || !is_numeric($exit) || (float) $entry <= 0.0 || (float) $exit <= 0.0) {
                continue;
            }

            $ret = ((float) $exit - (float) $entry) / (float) $entry;
            if (!is_finite($ret)) {
                continue;
            }

            if ($ret >= 0.0) {
                $win++;
            }
            $sumRet += $ret;
            $nRet++;

            $maxHigh = null;
            $minLow = null;
            for ($k = $entryIdx; $k <= $exitIdx; $k++) {
                $hh = $highs[$k] ?? null;
                $ll = $lows[$k] ?? null;
                if (is_numeric($hh)) {
                    $maxHigh = $maxHigh === null ? (float) $hh : max($maxHigh, (float) $hh);
                }
                if (is_numeric($ll)) {
                    $minLow = $minLow === null ? (float) $ll : min($minLow, (float) $ll);
                }
            }
            if ($maxHigh !== null && $maxHigh >= (float) $entry * (1.0 + $hitPct)) {
                $hitUp++;
            }
            if ($minLow !== null && $minLow <= (float) $entry * (1.0 - $hitPct)) {
                $hitDown++;
            }
        }

        return [
            'n' => $nRet,
            'win' => $win,
            'sum_ret' => $sumRet,
            'hit_up' => $hitUp,
            'hit_down' => $hitDown,
        ];
    }

    private function buildTargetRow(int $horizon, array $stats): array
    {
        $nRet = max(0, (int) ($stats['n'] ?? 0));
        $win = max(0, (int) ($stats['win'] ?? 0));
        $hitUp = max(0, (int) ($stats['hit_up'] ?? 0));
        $hitDown = max(0, (int) ($stats['hit_down'] ?? 0));
        $sumRet = $this->safeFloat($stats['sum_ret'] ?? 0.0) ?? 0.0;

        $denominator = max(1, $nRet);
        $pUp = ($win + 1.0) / ($denominator + 2.0);
        $pHitUp = ($hitUp + 1.0) / ($denominator + 2.0);
        $pHitDown = ($hitDown + 1.0) / ($denominator + 2.0);
        $er = $nRet > 0 ? ($sumRet / $nRet) : 0.0;

        return [
            'h' => $horizon,
            'p_up' => $this->clampProb01($pUp),
            'expected_return' => is_finite($er) ? $er : null,
            'er' => is_finite($er) ? $er : null,
            'p_hit_up' => $this->clampProb01($pHitUp),
            'p_hit_down' => $this->clampProb01($pHitDown),
            'n' => $nRet,
        ];
    }

    private function maSeries(array $closes, int $period): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n <= 0 || $period <= 0 || $n < $period) {
            return $out;
        }

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += (float) ($closes[$i] ?? 0.0);
            if ($i >= $period) {
                $sum -= (float) ($closes[$i - $period] ?? 0.0);
            }
            if ($i >= $period - 1) {
                $out[$i] = $sum / $period;
            }
        }

        return $out;
    }

    private function emaSeries(array $values, int $period): array
    {
        $n = count($values);
        if ($n <= 0 || $period <= 0) {
            return [];
        }

        $k = 2.0 / ($period + 1.0);
        $ema = [];
        $ema[0] = (float) ($values[0] ?? 0.0);
        for ($i = 1; $i < $n; $i++) {
            $ema[$i] = (float) ($values[$i] ?? 0.0) * $k + $ema[$i - 1] * (1.0 - $k);
        }

        return $ema;
    }

    private function rsiSeries(array $closes, int $period): array
    {
        $n = count($closes);
        $rsi = array_fill(0, $n, null);
        if ($n <= 0 || $period <= 0 || $n < $period + 1) {
            return $rsi;
        }

        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = (float) ($closes[$i] ?? 0.0) - (float) ($closes[$i - 1] ?? 0.0);
            if ($diff >= 0.0) {
                $gain += $diff;
            } else {
                $loss += -$diff;
            }
        }

        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;
        $rsi[$period] = $avgLoss == 0.0 ? 100.0 : (100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss))));

        for ($i = $period + 1; $i < $n; $i++) {
            $diff = (float) ($closes[$i] ?? 0.0) - (float) ($closes[$i - 1] ?? 0.0);
            $g = $diff > 0.0 ? $diff : 0.0;
            $l = $diff < 0.0 ? -$diff : 0.0;
            $avgGain = (($avgGain * ($period - 1)) + $g) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $l) / $period;
            $rsi[$i] = $avgLoss == 0.0 ? 100.0 : (100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss))));
        }

        return $rsi;
    }

    private function macdHistSeries(array $closes, int $short, int $long, int $signal): array
    {
        $n = count($closes);
        if ($n <= 0) {
            return [];
        }

        $emaShort = $this->emaSeries($closes, $short);
        $emaLong = $this->emaSeries($closes, $long);
        if (empty($emaShort) || empty($emaLong)) {
            return array_fill(0, $n, null);
        }

        $dif = [];
        for ($i = 0; $i < $n; $i++) {
            $dif[$i] = (float) ($emaShort[$i] ?? 0.0) - (float) ($emaLong[$i] ?? 0.0);
        }

        $dea = $this->emaSeries($dif, $signal);
        $hist = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            $hist[$i] = ((float) ($dif[$i] ?? 0.0) - (float) ($dea[$i] ?? 0.0)) * 2.0;
        }

        return $hist;
    }

    private function featureKey(?bool $aboveMa20, ?float $macdHist, ?float $rsi): string
    {
        $a = $aboveMa20 === null ? 'u' : ($aboveMa20 ? 'a' : 'b');
        return $a . '_' . $this->bucket01($macdHist) . '_' . $this->bucketRsi($rsi);
    }

    private function bucket01(?float $value): string
    {
        if (!is_numeric($value)) {
            return 'u';
        }
        return (float) $value >= 0.0 ? 'p' : 'n';
    }

    private function bucketRsi(?float $value): string
    {
        if (!is_numeric($value)) {
            return 'u';
        }
        $x = (float) $value;
        if ($x < 35.0) {
            return 'l';
        }
        if ($x > 65.0) {
            return 'h';
        }
        return 'm';
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return $text;
        }

        if (preg_match('/^\d{8}$/', $text)) {
            return substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2);
        }

        $ts = strtotime($text);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    private function safeFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $num = (float) $value;
            return is_finite($num) ? $num : null;
        }

        $text = trim((string) $value);
        if ($text === '' || $text === '--' || $text === '---') {
            return null;
        }

        $multiplier = 1.0;
        if (str_contains($text, '%') || str_contains($text, '％')) {
            $multiplier *= 0.01;
        }
        if (str_contains($text, '亿')) {
            $multiplier *= 100000000.0;
        }
        if (str_contains($text, '万')) {
            $multiplier *= 10000.0;
        }

        $text = str_replace([',', '，', '%', '％', '元', '股', '手', '万', '亿', ' '], '', $text);
        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        $num = (float) $text * $multiplier;
        return is_finite($num) ? $num : null;
    }

    private function clampProb01(float $value): float
    {
        if (!is_finite($value)) {
            return 0.0;
        }
        return max(0.0, min(1.0, $value));
    }
}
