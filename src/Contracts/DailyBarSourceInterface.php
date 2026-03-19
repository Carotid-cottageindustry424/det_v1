<?php

namespace DetV1\Contracts;

interface DailyBarSourceInterface
{
    /**
     * @return array{0: array<int, mixed>|null, 1: string|null, 2: array<string, mixed>}
     */
    public function loadBars(string $symbol, array $options = []): array;
}
