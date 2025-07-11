<?php

namespace Database\Factories;

use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Website>
 */
class WebsiteFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Website::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Store',
            'url' => fake()->url(),
            'platform' => 'woocommerce',
            'language' => 'en',
            'woocommerce_credentials' => json_encode([
                'consumer_key' => fake()->uuid(),
                'consumer_secret' => fake()->uuid(),
            ]),
            'wordpress_credentials' => null,
            'last_checked_at' => now(),
        ];
    }

    /**
     * Indicate that the website should have specific WooCommerce credentials.
     */
    public function withWooCommerceCredentials(array $credentials): static
    {
        return $this->state(fn (array $attributes) => [
            'woocommerce_credentials' => json_encode($credentials),
        ]);
    }

    /**
     * Indicate that the website should be for a specific platform.
     */
    public function platform(string $platform): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => $platform,
        ]);
    }
}
