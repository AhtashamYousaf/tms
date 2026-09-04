<?php

namespace Tests\Feature\Translations;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_authentication(): void
    {
        $response = $this->getJson('/api/translations/export');

        $response->assertStatus(401);
    }

    public function test_export_returns_locale_keyed_json_structure(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $fr = Locale::factory()->create(['code' => 'fr']);
        Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'welcome.message', 'content' => 'Welcome']);
        Translation::factory()->create(['locale_id' => $fr->id, 'translation_key' => 'welcome.message', 'content' => 'Bienvenue']);

        $response = $this->getJson('/api/translations/export');

        $response->assertOk()->assertExactJson([
            'en' => ['welcome.message' => 'Welcome'],
            'fr' => ['welcome.message' => 'Bienvenue'],
        ]);
    }

    public function test_export_can_be_filtered_by_locale(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $fr = Locale::factory()->create(['code' => 'fr']);
        Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'a', 'content' => 'A']);
        Translation::factory()->create(['locale_id' => $fr->id, 'translation_key' => 'a', 'content' => 'B']);

        $response = $this->getJson('/api/translations/export?locale=en');

        $response->assertOk()->assertExactJson(['en' => ['a' => 'A']]);
    }

    public function test_export_can_be_filtered_by_tag(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $mobile = Tag::factory()->create(['name' => 'mobile']);
        Tag::factory()->create(['name' => 'web']);

        $tagged = Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'a', 'content' => 'A']);
        $tagged->tags()->attach($mobile);
        Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'b', 'content' => 'B']);

        $response = $this->getJson('/api/translations/export?tag=mobile');

        $response->assertOk()->assertExactJson(['en' => ['a' => 'A']]);
    }

    public function test_export_reflects_the_latest_content_after_an_update(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $translation = Translation::factory()->create([
            'locale_id' => $en->id,
            'translation_key' => 'welcome.message',
            'content' => 'Welcome',
        ]);

        $this->getJson('/api/translations/export')->assertExactJson([
            'en' => ['welcome.message' => 'Welcome'],
        ]);

        $this->putJson("/api/translations/{$translation->id}", ['content' => 'Welcome, friend!'])->assertOk();

        $this->getJson('/api/translations/export')->assertExactJson([
            'en' => ['welcome.message' => 'Welcome, friend!'],
        ]);
    }

    public function test_export_reflects_deletions_immediately(): void
    {
        $this->authenticate();
        $en = Locale::factory()->create(['code' => 'en']);
        $translation = Translation::factory()->create([
            'locale_id' => $en->id,
            'translation_key' => 'welcome.message',
            'content' => 'Welcome',
        ]);

        $this->getJson('/api/translations/export')->assertExactJson(['en' => ['welcome.message' => 'Welcome']]);

        $this->deleteJson("/api/translations/{$translation->id}")->assertNoContent();

        $this->getJson('/api/translations/export')->assertExactJson([]);
    }
}
