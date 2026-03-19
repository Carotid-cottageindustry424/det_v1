<?php

namespace DetV1\Contracts;

interface AiClientInterface
{
    /**
     * @return array{0: string|null, 1: string|null, 2: array<string, mixed>}
     */
    public function chat(string $prompt, array $options = []): array;
}
