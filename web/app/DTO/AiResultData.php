<?php

declare(strict_types=1);

namespace App\DTO;

/** Typed mirror of the AI service's AiResult response. */
final readonly class AiResultData
{
    /**
     * @param  array<int, array{attempt: int, model: string, outcome: string,
     *     latency_ms: int, prompt_tokens: ?int, completion_tokens: ?int,
     *     response_excerpt: ?string}>  $attempts
     */
    public function __construct(
        public array $schema,
        public string $model,
        public int $totalLatencyMs,
        public int $promptTokens,
        public int $completionTokens,
        public array $attempts,
    ) {
    }

    public static function fromResponse(array $data): self
    {
        return new self(
            schema: $data['schema'] ?? [],
            model: $data['model'] ?? 'unknown',
            totalLatencyMs: (int) ($data['total_latency_ms'] ?? 0),
            promptTokens: (int) ($data['prompt_tokens'] ?? 0),
            completionTokens: (int) ($data['completion_tokens'] ?? 0),
            attempts: $data['attempts'] ?? [],
        );
    }
}
