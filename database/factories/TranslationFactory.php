<?php

namespace Database\Factories;

use App\Models\Locale;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Translation>
 */
class TranslationFactory extends Factory
{
    protected $model = Translation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'locale_id' => Locale::factory(),
            'translation_key' => $this->faker->unique()->words(3, true).'.'.$this->faker->word(),
            'content' => $this->faker->sentence(),
        ];
    }
}
