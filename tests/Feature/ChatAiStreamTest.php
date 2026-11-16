<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatAiStreamTest extends TestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://ollama.test';

    private const SENOPATI = 'https://senopati.test/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'llm.providers.ollama.base_url' => self::OLLAMA,
            'llm.providers.senopati.base_url' => self::SENOPATI,
            'llm.providers.senopati.api_key' => 'kunci-uji',
        ]);

        Http::preventStrayRequests();
    }

    public function test_streams_ollama_chunks_as_server_sent_events_and_logs_the_result(): void
    {
        $ndjson = implode("\n", [
            json_encode(['message' => ['content' => '<think>rahasia']]),
            json_encode(['message' => ['content' => '</think>Middleware ']]),
            json_encode(['message' => ['content' => 'adalah ']]),
            json_encode(['message' => ['content' => 'penyaring.']]),
            json_encode(['message' => ['content' => ''], 'done' => true, 'prompt_eval_count' => 12, 'eval_count' => 5]),
        ])."\n";

        Http::fake([self::OLLAMA.'/api/chat' => Http::response($ndjson)]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/chat-ai', ['prompt' => 'Jelaskan middleware!', 'stream' => true]);

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $events = $this->parseEvents($response->streamedContent());

        $this->assertSame('meta', $events[0]['event']);
        $this->assertSame('ollama', $events[0]['data']['provider']);

        $deltas = array_column(array_column(array_filter($events, fn ($e) => $e['event'] === 'delta'), 'data'), 'content');
        $this->assertSame('Middleware adalah penyaring.', implode('', $deltas));
        $this->assertStringNotContainsString('rahasia', implode('', $deltas));

        $done = end($events);
        $this->assertSame('done', $done['event']);
        $this->assertSame('success', $done['data']['status']);
        $this->assertSame(17, $done['data']['data']['tokens']['total']);

        $this->assertDatabaseHas('chat_logs', [
            'user_id' => $user->id,
            'ai_response' => 'Middleware adalah penyaring.',
            'is_streamed' => true,
            'total_tokens' => 17,
        ]);
    }

    public function test_stream_falls_back_to_senopati_before_any_text_was_sent(): void
    {
        $sse = implode("\n\n", [
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'Halo ']]]]),
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'dari Senopati.']]]]),
            'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4]]),
            'data: [DONE]',
        ])."\n\n";

        Http::fake([
            self::OLLAMA.'/*' => Http::failedConnection(),
            self::SENOPATI.'/chat/completions' => Http::response($sse),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $events = $this->parseEvents(
            $this->postJson('/api/chat-ai', ['prompt' => 'Halo', 'stream' => true])->streamedContent()
        );

        $metas = array_values(array_filter($events, fn ($e) => $e['event'] === 'meta'));
        $this->assertSame(['ollama', 'senopati'], array_column(array_column($metas, 'data'), 'provider'));
        $this->assertTrue($metas[1]['data']['is_fallback']);

        $done = end($events);
        $this->assertSame('done', $done['event']);
        $this->assertSame('Halo dari Senopati.', $done['data']['data']['ai_response']);
        $this->assertTrue($done['data']['data']['is_fallback']);
        $this->assertSame(12, $done['data']['data']['tokens']['total']);
    }

    public function test_stream_sends_error_event_when_every_provider_is_down(): void
    {
        Http::fake([
            self::OLLAMA.'/*' => Http::failedConnection(),
            self::SENOPATI.'/*' => Http::failedConnection(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $events = $this->parseEvents(
            $this->postJson('/api/chat-ai', ['prompt' => 'Halo', 'stream' => true])->streamedContent()
        );

        $last = end($events);
        $this->assertSame('error', $last['event']);
        $this->assertSame('error', $last['data']['status']);
        $this->assertDatabaseCount('chat_logs', 0);
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private function parseEvents(string $body): array
    {
        $events = [];
        foreach (preg_split('/\n\n/', trim($body)) as $block) {
            preg_match('/^event: (.+)$/m', $block, $event);
            preg_match('/^data: (.+)$/m', $block, $data);
            $events[] = ['event' => $event[1], 'data' => json_decode($data[1], true)];
        }

        return $events;
    }
}
