<?php

namespace App\Services\Llm;

use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Exceptions\LlmProviderException;
use App\Services\Llm\Exceptions\LlmUnavailableException;
use App\Services\Llm\Providers\OllamaProvider;
use App\Services\Llm\Providers\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Titik masuk tunggal ke LLM: menyuntikkan system prompt tema kelompok
 * dan mengalihkan otomatis ke provider fallback bila provider utama gagal.
 */
class LlmManager
{
    /**
     * @param  array<string, mixed>  $config  isi config/llm.php
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return list<array{role: string, content: string}>
     */
    public function messagesFor(string $prompt): array
    {
        return [
            ['role' => 'system', 'content' => (string) $this->config['system_prompt']],
            ['role' => 'user', 'content' => $prompt],
        ];
    }

    /**
     * @throws LlmUnavailableException
     */
    public function chat(string $prompt): LlmResult
    {
        $messages = $this->messagesFor($prompt);
        $failures = [];

        foreach ($this->chain() as $index => $provider) {
            try {
                $result = $provider->chat($messages);

                return $index === 0 ? $result : $result->asFallback();
            } catch (LlmProviderException $e) {
                $failures[] = $e->getMessage();
                $this->logFailure($e, $index);
            }
        }

        throw new LlmUnavailableException($failures);
    }

    /**
     * Alirkan jawaban. Fallback hanya dilakukan bila provider utama gagal
     * sebelum mengirim potongan teks pertama, agar klien tidak menerima
     * jawaban campuran dari dua model.
     *
     * @param  callable(string): void  $onDelta
     * @param  callable(LlmProvider, bool): void  $onStart  dipanggil saat sebuah provider mulai dicoba
     *
     * @throws LlmUnavailableException
     * @throws LlmProviderException bila aliran terputus di tengah jalan
     */
    public function stream(string $prompt, callable $onDelta, callable $onStart): LlmResult
    {
        $messages = $this->messagesFor($prompt);
        $failures = [];

        foreach ($this->chain() as $index => $provider) {
            $onStart($provider, $index > 0);

            try {
                $result = $provider->stream($messages, $onDelta);

                return $index === 0 ? $result : $result->asFallback();
            } catch (LlmProviderException $e) {
                $this->logFailure($e, $index);

                if ($e->deltaEmitted) {
                    throw $e;
                }

                $failures[] = $e->getMessage();
            }
        }

        throw new LlmUnavailableException($failures);
    }

    /**
     * Status keterjangkauan setiap provider.
     *
     * @return list<array{name: string, model: string, role: string, online: bool}>
     */
    public function status(): array
    {
        return array_map(fn (LlmProvider $provider, int $index) => [
            'name' => $provider->name(),
            'model' => $provider->model(),
            'role' => $index === 0 ? 'primary' : 'fallback',
            'online' => $provider->ping(),
        ], $this->chain(), array_keys($this->chain()));
    }

    /**
     * @return list<LlmProvider>
     */
    public function chain(): array
    {
        $names = array_values(array_unique(array_filter([
            $this->config['primary'] ?? null,
            $this->config['fallback'] ?? null,
        ])));

        return array_map(fn (string $name) => $this->provider($name), $names);
    }

    public function provider(string $name): LlmProvider
    {
        $config = $this->config['providers'][$name] ?? null;
        if ($config === null) {
            throw new InvalidArgumentException("Provider LLM [{$name}] belum dikonfigurasi di config/llm.php.");
        }

        $temperature = (float) ($this->config['temperature'] ?? 0.2);

        return match ($config['driver']) {
            'ollama' => new OllamaProvider($name, $config, $temperature),
            'openai' => new OpenAiCompatibleProvider($name, $config, $temperature),
            default => throw new InvalidArgumentException("Driver LLM [{$config['driver']}] tidak dikenal."),
        };
    }

    private function logFailure(LlmProviderException $e, int $index): void
    {
        $index === 0
            ? Log::warning('Provider LLM utama gagal, mencoba fallback. '.$e->getMessage())
            : Log::error('Provider LLM fallback gagal. '.$e->getMessage());
    }
}
