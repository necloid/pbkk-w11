<?php

namespace App\Services\Llm\Providers;

use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Exceptions\LlmProviderException;
use App\Services\Llm\LlmResult;
use App\Services\Llm\ThinkFilter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dasar bersama untuk provider LLM berbasis HTTP.
 */
abstract class HttpProvider implements LlmProvider
{
    /**
     * @param  array{base_url: string, model: string, timeout: int, connect_timeout: int, api_key?: string|null}  $config
     */
    public function __construct(
        protected readonly string $name,
        protected readonly array $config,
        protected readonly float $temperature,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    /**
     * Ubah satu baris respon streaming menjadi [potongan teks, usage|null, selesai?].
     *
     * @return array{0: string, 1: array{0: int, 1: int}|null, 2: bool}|null
     */
    abstract protected function parseStreamLine(string $line): ?array;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{0: string, 1: array<string, mixed>}
     */
    abstract protected function chatRequest(array $messages, bool $stream): array;

    /**
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    abstract protected function parseChatResponse(Response $response): array;

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['base_url'], '/'))
            ->acceptJson()
            ->timeout($this->config['timeout'])
            ->connectTimeout($this->config['connect_timeout']);
    }

    public function chat(array $messages): LlmResult
    {
        [$path, $payload] = $this->chatRequest($messages, false);

        $response = $this->send($path, $payload);
        [$content, $promptTokens, $completionTokens, $model] = $this->parseChatResponse($response);

        $content = ThinkFilter::strip($content);
        if ($content === '') {
            throw new LlmProviderException($this->name, 'Model mengembalikan jawaban kosong.');
        }

        return new LlmResult(
            $content,
            $this->name,
            $model ?: $this->model(),
            $promptTokens ?: $this->estimateTokens($messages),
            $completionTokens ?: $this->estimateText($content),
        );
    }

    public function stream(array $messages, callable $onDelta): LlmResult
    {
        [$path, $payload] = $this->chatRequest($messages, true);

        $response = $this->send($path, $payload, stream: true);
        $body = $response->toPsrResponse()->getBody();

        $filter = new ThinkFilter;
        $content = '';
        $usage = null;
        $emitted = false;
        $buffer = '';

        $emit = function (string $text) use (&$content, &$emitted, $onDelta): void {
            if ($text === '') {
                return;
            }
            $content .= $text;
            $emitted = true;
            $onDelta($text);
        };

        try {
            while (! $body->eof()) {
                $buffer .= $body->read(1024);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    $parsed = $line === '' ? null : $this->parseStreamLine($line);
                    if ($parsed === null) {
                        continue;
                    }

                    [$delta, $lineUsage, $done] = $parsed;
                    $emit($filter->push($delta));
                    $usage = $lineUsage ?? $usage;

                    if ($done) {
                        break 2;
                    }
                }
            }

            if (trim($buffer) !== '' && ($parsed = $this->parseStreamLine(trim($buffer))) !== null) {
                $emit($filter->push($parsed[0]));
                $usage = $parsed[1] ?? $usage;
            }
        } catch (Throwable $e) {
            throw new LlmProviderException($this->name, 'Aliran respon terputus: '.$e->getMessage(), $emitted, $e);
        }

        $emit($filter->flush());

        if (trim($content) === '') {
            throw new LlmProviderException($this->name, 'Model mengembalikan jawaban kosong.', $emitted);
        }

        return new LlmResult(
            trim($content),
            $this->name,
            $this->model(),
            $usage[0] ?? $this->estimateTokens($messages),
            $usage[1] ?? $this->estimateText($content),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $path, array $payload, bool $stream = false): Response
    {
        try {
            $request = $this->client();
            if ($stream) {
                $request = $request->withOptions(['stream' => true]);
            }

            $response = $request->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new LlmProviderException($this->name, 'Tidak dapat terhubung: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $stream ? '' : ' '.mb_substr((string) $response->body(), 0, 200);

            throw new LlmProviderException($this->name, "HTTP {$response->status()}.{$detail}");
        }

        return $response;
    }

    /**
     * Perkiraan kasar (sekitar 4 karakter per token) bila provider tidak mengirim usage.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    protected function estimateTokens(array $messages): int
    {
        return $this->estimateText(implode("\n", array_column($messages, 'content')));
    }

    protected function estimateText(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
