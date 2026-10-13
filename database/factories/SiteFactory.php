<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Magazine', 'url' => 'https://source.test/', 'host' => 'source.test',
            'max_pages' => 20, 'max_depth' => 3, 'include_subdomains' => false,
            'include_query' => false, 'schedule_frequency' => 'manual',
        ];
    }
}
