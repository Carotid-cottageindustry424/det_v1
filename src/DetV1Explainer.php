<?php

namespace DetV1;

use DetV1\Contracts\AiClientInterface;

final class DetV1Explainer
{
    public function buildLocalExplanation(string $symbol, array $analysis): string
    {
        if (empty($analysis['ok'])) {
            return 'det_v1 分析失败：' . (string) ($analysis['error'] ?? '未知错误');
        }

        $decision = is_array($analysis['decision'] ?? null) ? $analysis['decision'] : [];
        $state = is_array($analysis['state'] ?? null) ? $analysis['state'] : [];
        $targets = is_array($analysis['targets'] ?? null) ? $analysis['targets'] : [];

        $lines = [];
        $lines[] = '标的：' . $symbol;
        $lines[] = '截至：' . (string) ($analysis['asof'] ?? '-');
        $lines[] = '当前状态：' . (string) ($state['feature_key'] ?? '-');
        $lines[] = '教学结论：' . (string) ($decision['action'] ?? 'watch');
        $lines[] = '说明：' . (string) ($decision['reason'] ?? '无');

        foreach ($targets as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = sprintf(
                '%d 日 -> p_up=%s, er=%s, n=%d',
                (int) ($row['h'] ?? 0),
                isset($row['p_up']) && is_numeric($row['p_up']) ? number_format((float) $row['p_up'], 4, '.', '') : '-',
                isset($row['er']) && is_numeric($row['er']) ? number_format((float) $row['er'], 4, '.', '') : '-',
                isset($row['n']) ? (int) $row['n'] : 0
            );
        }

        $lines[] = '这只是统计学习示例，不是实盘建议。';
        return implode("\n", $lines);
    }

    public function buildAiPrompt(string $symbol, array $analysis): string
    {
        $primaryHorizon = isset($analysis['decision']['primary_horizon']) && is_numeric($analysis['decision']['primary_horizon'])
            ? max(1, (int) $analysis['decision']['primary_horizon'])
            : 5;

        return implode("\n", [
            '你是一个负责解释 det_v1 教学模型结果的助手。',
            '要求：',
            '1. 只解释模型逻辑，不要输出买卖承诺。',
            '2. 明确指出这是入门示例，不适合直接实盘。',
            '3. 先解释当前状态，再解释 ' . $primaryHorizon . ' 日窗口结论，最后说清模型缺陷。',
            '4. 用中文输出，尽量短。',
            '',
            '标的：' . $symbol,
            '分析结果(JSON)：',
            json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);
    }

    public function explainWithAi(AiClientInterface $client, string $symbol, array $analysis): array
    {
        $prompt = $this->buildAiPrompt($symbol, $analysis);
        return $client->chat($prompt);
    }
}
