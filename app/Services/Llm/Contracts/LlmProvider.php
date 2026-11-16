<?php

namespace App\Services\Llm\Contracts;

use App\Services\Llm\Exceptions\LlmProviderException;
use App\Services\Llm\LlmResult;

interface LlmProvider
{
    /**
     * Nama provider, misalnya "ollama" atau "senopati".
     */
    public function name(): string;

    /**
     * Model yang dipakai provider ini.
     */
    public function model(): string;

    /**
     * Kirim percakapan dan tunggu jawaban utuh.
     *
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws LlmProviderException
     */
    public function chat(array $messages): LlmResult;

    /**
     * Kirim percakapan dan alirkan jawaban potong demi potong.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     *
     * @throws LlmProviderException
     */
    public function stream(array $messages, callable $onDelta): LlmResult;

    /**
     * Cek cepat apakah server provider dapat dijangkau.
     */
    public function ping(): bool;
}
