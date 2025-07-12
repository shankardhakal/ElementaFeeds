<?php

namespace Database\Factories;

use App\Models\Feed;
use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Feed>
 */
class FeedFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Feed::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_id' => Network::factory(),
            'name' => fake()->company() . ' ' . fake()->word() . ' Feed',
            'feed_url' => fake()->url() . '/feed.csv',
            'language' => fake()->randomElement(['en', 'fi', 'sv', 'no', 'da']),
            'is_active' => true,
            'delimiter' => fake()->randomElement(['comma', 'tab', 'pipe', 'semicolon']),
            'enclosure' => fake()->randomElement(['"', "'"]),
        ];
    }

    /**
     * Indicate that the feed should be inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the feed should be active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the feed should use a specific delimiter.
     */
    public function withDelimiter(string $delimiter): static
    {
        return $this->state(fn (array $attributes) => [
            'delimiter' => $delimiter,
        ]);
    }

    /**
     * Indicate that the feed should belong to a specific network.
     */
    public function forNetwork(Network $network): static
    {
        return $this->state(fn (array $attributes) => [
            'network_id' => $network->id,
        ]);
    }
}
