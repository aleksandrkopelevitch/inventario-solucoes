<?php

namespace Database\Factories;

use App\Models\McpToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpToken>
 */
class McpTokenFactory extends Factory
{
    protected $model = McpToken::class;

    /**
     * Hashes a throwaway plaintext rather than leaving `token_hash` blank, so a
     * factory-made token is a token that could really answer — a test asserting
     * "an unknown bearer is refused" then means something.
     *
     * The plaintext itself is discarded: a test that needs one calls
     * `McpToken::mint()`, which is the only door the app has.
     */
    public function definition(): array
    {
        $plain = McpToken::PREFIX . Str::random(McpToken::RANDOM_LENGTH);

        return [
            'name'       => fake()->unique()->words(2, true),
            'token_hash' => McpToken::hash($plain),
            'last_four'  => substr($plain, -4),
        ];
    }
}
