<?php

namespace Database\Factories;

use App\Models\ChatLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatLog>
 */
class ChatLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $promptTokens = fake()->numberBetween(20, 200);
        $completionTokens = fake()->numberBetween(50, 600);

        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(),
            'ai_response' => fake()->paragraph(),
            'model_used' => 'qwen3:14b',
            'provider' => 'ollama',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'is_fallback' => false,
            'is_streamed' => false,
            'duration_ms' => fake()->numberBetween(300, 9000),
        ];
    }
}
