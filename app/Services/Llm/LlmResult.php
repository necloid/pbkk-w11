<?php

namespace App\Services\Llm;

/**
 * Hasil satu kali percakapan dengan provider LLM.
 */
final class LlmResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly bool $isFallback = false,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function asFallback(): self
    {
        return new self(
            $this->content,
            $this->provider,
            $this->model,
            $this->promptTokens,
            $this->completionTokens,
            true,
        );
    }
}
