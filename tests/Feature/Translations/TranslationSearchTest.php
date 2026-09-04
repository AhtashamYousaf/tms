<?php

namespace Tests\Feature\Translations;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TranslationSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_filter_translations_by_key(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'welcome.message']);
        Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'login.title']);

        $response = $this->getJson('/api/translations?key=welcome');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'welcome.message');
    }

    public function test_can_filter_translations_by_content(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        Translation::factory()->create(['locale_id' => $en->id, 'content' => 'Hello there']);
        Translation::factory()->create(['locale_id' => $en->id, 'content' => 'Goodbye']);

        $response = $this->getJson('/api/translations?content=Hello');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_can_filter_translations_by_locale(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $fr = Locale::factory()->create(['code' => 'fr']);
        Translation::factory()->count(2)->create(['locale_id' => $en->id]);
        Translation::factory()->create(['locale_id' => $fr->id]);

        $response = $this->getJson('/api/translations?locale=fr');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.locale', 'fr');
    }

    public function test_can_filter_translations_by_tag(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $mobile = Tag::factory()->create(['name' => 'mobile']);
        Tag::factory()->create(['name' => 'web']);

        $tagged = Translation::factory()->create(['locale_id' => $en->id]);
        $tagged->tags()->attach($mobile);
        Translation::factory()->create(['locale_id' => $en->id]);

        $response = $this->getJson('/api/translations?tag=mobile');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tagged->id);
    }

    public function test_can_combine_key_locale_and_tag_filters(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $fr = Locale::factory()->create(['code' => 'fr']);
        $mobile = Tag::factory()->create(['name' => 'mobile']);

        $match = Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'welcome.message']);
        $match->tags()->attach($mobile);

        $wrongLocale = Translation::factory()->create(['locale_id' => $fr->id, 'translation_key' => 'welcome.message']);
        $wrongLocale->tags()->attach($mobile);

        $response = $this->getJson('/api/translations?locale=en&tag=mobile&key=welcome');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public function test_results_are_paginated(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        Translation::factory()->count(20)->create(['locale_id' => $en->id]);

        $response = $this->getJson('/api/translations?per_page=5');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_search_parameters_are_safe_from_sql_injection(): void
    {
        $this->authenticate();
        Locale::factory()->create(['code' => 'en']);

        $response = $this->getJson('/api/translations?key='.urlencode("' OR '1'='1"));

        $response->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('translations', 0);
    }

    public function test_listing_does_not_trigger_n_plus_one_queries(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $tag = Tag::factory()->create(['name' => 'web']);

        Translation::factory()->count(15)->create(['locale_id' => $en->id])
            ->each(fn (Translation $translation) => $translation->tags()->attach($tag));

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->getJson('/api/translations')->assertOk();

        $this->assertLessThanOrEqual(6, $queryCount, 'Listing translations should use a bounded, small number of queries regardless of row count.');
    }
}
