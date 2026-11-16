<?php

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatAiTest extends TestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://ollama.test';

    private const SENOPATI = 'https://senopati.test/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'llm.primary' => 'ollama',
            'llm.fallback' => 'senopati',
            'llm.providers.ollama.base_url' => self::OLLAMA,
            'llm.providers.senopati.base_url' => self::SENOPATI,
            'llm.providers.senopati.api_key' => 'kunci-uji',
        ]);

        Http::preventStrayRequests();
    }

    public function test_chat_returns_structured_json_and_logs_the_conversation(): void
    {
        Http::fake([
            self::OLLAMA.'/api/chat' => Http::response([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => '<think>menimbang...</think>Middleware adalah lapisan penyaring request.'],
                'prompt_eval_count' => 42,
                'eval_count' => 17,
                'done' => true,
            ]),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/chat-ai', ['prompt' => 'Jelaskan middleware!']);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.model_used', 'qwen3:14b')
            ->assertJsonPath('data.provider', 'ollama')
            ->assertJsonPath('data.is_fallback', false)
            ->assertJsonPath('data.prompt', 'Jelaskan middleware!')
            ->assertJsonPath('data.ai_response', 'Middleware adalah lapisan penyaring request.')
            ->assertJsonPath('data.tokens.total', 59)
            ->assertJsonStructure(['status', 'data' => ['session_id', 'model_used', 'prompt', 'ai_response', 'timestamp']]);

        $this->assertDatabaseHas('chat_logs', [
            'id' => $response->json('data.session_id'),
            'user_id' => $user->id,
            'prompt' => 'Jelaskan middleware!',
            'model_used' => 'qwen3:14b',
            'prompt_tokens' => 42,
            'completion_tokens' => 17,
            'total_tokens' => 59,
        ]);
    }

    public function test_system_prompt_is_injected_before_the_user_prompt(): void
    {
        config(['llm.system_prompt' => 'Anda adalah agen siber ITS.']);

        Http::fake([
            self::OLLAMA.'/api/chat' => Http::response(['message' => ['content' => 'Aman.'], 'done' => true]),
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/chat-ai', ['prompt' => 'Cek kode ini'])->assertCreated();

        Http::assertSent(function (Request $request) {
            return $request['messages'][0] === ['role' => 'system', 'content' => 'Anda adalah agen siber ITS.']
                && $request['messages'][1] === ['role' => 'user', 'content' => 'Cek kode ini']
                && $request['stream'] === false;
        });
    }

    public function test_falls_back_to_senopati_when_ollama_is_down(): void
    {
        Http::fake([
            self::OLLAMA.'/*' => Http::failedConnection(),
            self::SENOPATI.'/chat/completions' => Http::response([
                'model' => 'qwen3:14b',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban dari Senopati.']]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10, 'total_tokens' => 40],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/chat-ai', ['prompt' => 'Halo'])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'senopati')
            ->assertJsonPath('data.is_fallback', true)
            ->assertJsonPath('data.ai_response', 'Jawaban dari Senopati.');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer kunci-uji'));
        $this->assertDatabaseHas('chat_logs', ['provider' => 'senopati', 'is_fallback' => true]);
    }

    public function test_returns_503_when_every_provider_fails(): void
    {
        Http::fake([
            self::OLLAMA.'/*' => Http::response('server error', 500),
            self::SENOPATI.'/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/chat-ai', ['prompt' => 'Halo'])
            ->assertStatus(503)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('chat_logs', 0);
    }

    public function test_prompt_is_required_string_and_at_most_1000_characters(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/chat-ai', [])->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->postJson('/api/chat-ai', ['prompt' => '   '])->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->postJson('/api/chat-ai', ['prompt' => ['bukan', 'string']])->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->postJson('/api/chat-ai', ['prompt' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('errors.prompt.0', 'Prompt maksimal 1000 karakter.');

        Http::assertNothingSent();
    }

    public function test_prompt_of_exactly_1000_characters_is_accepted(): void
    {
        Http::fake([self::OLLAMA.'/api/chat' => Http::response(['message' => ['content' => 'Oke.'], 'done' => true])]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/chat-ai', ['prompt' => str_repeat('a', 1000)])->assertCreated();
    }

    public function test_history_only_lists_the_current_users_logs(): void
    {
        $user = User::factory()->create();
        ChatLog::factory()->count(3)->for($user)->create();
        ChatLog::factory()->count(2)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/chat-ai/history')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_cannot_open_another_users_log(): void
    {
        $other = ChatLog::factory()->create();
        $mine = ChatLog::factory()->for($me = User::factory()->create())->create();

        Sanctum::actingAs($me);

        $this->getJson("/api/chat-ai/history/{$mine->id}")->assertOk()->assertJsonPath('data.session_id', $mine->id);
        $this->getJson("/api/chat-ai/history/{$other->id}")->assertNotFound()->assertJsonPath('status', 'error');
    }
}
