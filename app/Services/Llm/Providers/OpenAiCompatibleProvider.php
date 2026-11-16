<?php

namespace App\Services\Llm\Providers;

use App\Services\Llm\Exceptions\LlmProviderException;
use App\Services\Llm\LlmResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Gateway berformat OpenAI Chat Completions, misalnya Senopati AI ITS.
 */
class OpenAiCompatibleProvider extends HttpProvider
{
    protected function client(): PendingRequest
    {
        return parent::client()->withToken((string) ($this->config['api_key'] ?? ''));
    }

    public function chat(array $messages): LlmResult
    {
        $this->ensureConfigured();

        return parent::chat($messages);
    }

    public function stream(array $messages, callable $onDelta): LlmResult
    {
        $this->ensureConfigured();

        return parent::stream($messages, $onDelta);
    }

    protected function chatRequest(array $messages, bool $stream): array
    {
        $payload = [
            'model' => $this->model(),
            'messages' => $messages,
            'temperature' => $this->temperature,
            'stream' => $stream,
        ];

        if ($stream) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        return ['/chat/completions', $payload];
    }

    protected function parseChatResponse(Response $response): array
    {
        return [
            (string) $response->json('choices.0.message.content', ''),
            (int) $response->json('usage.prompt_tokens', 0),
            (int) $response->json('usage.completion_tokens', 0),
            (string) $response->json('model', ''),
        ];
    }

    /**
     * Format Server-Sent Events: "data: {...}" dan diakhiri "data: [DONE]".
     */
    protected function parseStreamLine(string $line): ?array
    {
        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $data = trim(substr($line, 5));
        if ($data === '[DONE]') {
            return ['', null, true];
        }

        $json = json_decode($data, true);
        if (! is_array($json)) {
            return null;
        }

        $usage = isset($json['usage']['prompt_tokens'])
            ? [(int) $json['usage']['prompt_tokens'], (int) ($json['usage']['completion_tokens'] ?? 0)]
            : null;

        return [(string) ($json['choices'][0]['delta']['content'] ?? ''), $usage, false];
    }

    public function ping(): bool
    {
        if (blank($this->config['api_key'] ?? null)) {
            return false;
        }

        try {
            return $this->client()->connectTimeout(2)->timeout(4)->get('/models')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function ensureConfigured(): void
    {
        if (blank($this->config['api_key'] ?? null)) {
            throw new LlmProviderException($this->name, 'API key belum diisi (SENOPATI_API_KEY).');
        }
    }
}
