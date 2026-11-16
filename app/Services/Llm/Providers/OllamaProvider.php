<?php

namespace App\Services\Llm\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ollama lokal (http://localhost:11434) memakai endpoint native /api/chat.
 */
class OllamaProvider extends HttpProvider
{
    protected function chatRequest(array $messages, bool $stream): array
    {
        return ['/api/chat', [
            'model' => $this->model(),
            'messages' => $messages,
            'stream' => $stream,
            'think' => false,
            'options' => ['temperature' => $this->temperature],
        ]];
    }

    protected function parseChatResponse(Response $response): array
    {
        return [
            (string) $response->json('message.content', ''),
            (int) $response->json('prompt_eval_count', 0),
            (int) $response->json('eval_count', 0),
            (string) $response->json('model', ''),
        ];
    }

    /**
     * Ollama mengalirkan NDJSON: satu objek JSON per baris.
     */
    protected function parseStreamLine(string $line): ?array
    {
        $data = json_decode($line, true);
        if (! is_array($data)) {
            return null;
        }

        $done = (bool) ($data['done'] ?? false);
        $usage = $done ? [(int) ($data['prompt_eval_count'] ?? 0), (int) ($data['eval_count'] ?? 0)] : null;

        return [(string) ($data['message']['content'] ?? ''), $usage, $done];
    }

    public function ping(): bool
    {
        try {
            return Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->connectTimeout(2)->timeout(3)
                ->get('/api/tags')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
