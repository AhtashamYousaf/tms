<?php

namespace Tests\Feature\Translations;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Locale::factory()->create(['code' => 'en', 'name' => 'English']);
        Locale::factory()->create(['code' => 'fr', 'name' => 'French']);
    }

    public function test_authenticated_user_can_create_translation_with_tags(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.message',
            'locale' => 'en',
            'content' => 'Welcome',
            'tags' => ['web', 'mobile'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.key', 'welcome.message')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.content', 'Welcome')
            ->assertJsonCount(2, 'data.tags');

        $this->assertDatabaseHas('translations', [
            'translation_key' => 'welcome.message',
            'content' => 'Welcome',
        ]);
        $this->assertDatabaseCount('tags', 2);
        $this->assertDatabaseCount('translation_tag', 2);
    }

    public function test_unauthenticated_user_cannot_create_translation(): void
    {
        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.message',
            'locale' => 'en',
            'content' => 'Welcome',
        ]);

        $response->assertStatus(401);
    }

    public function test_create_requires_key_locale_and_content(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/translations', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['key', 'locale', 'content']);
    }

    public function test_create_rejects_unknown_locale(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.message',
            'locale' => 'zz',
            'content' => 'Welcome',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('locale');
    }

    public function test_create_prevents_duplicate_locale_and_key_combination(): void
    {
        $this->authenticate();
        $locale = Locale::query()->where('code', 'en')->first();

        Translation::factory()->create(['locale_id' => $locale->id, 'translation_key' => 'welcome.message']);

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.message',
            'locale' => 'en',
            'content' => 'Welcome again',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_same_key_is_allowed_across_different_locales(): void
    {
        $this->authenticate();
        $en = Locale::query()->where('code', 'en')->first();

        Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'welcome.message']);

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.message',
            'locale' => 'fr',
            'content' => 'Bienvenue',
        ]);

        $response->assertCreated();
    }

    public function test_authenticated_user_can_view_a_translation(): void
    {
        $this->authenticate();
        $translation = Translation::factory()->create([
            'locale_id' => Locale::query()->where('code', 'en')->value('id'),
        ]);

        $response = $this->getJson("/api/translations/{$translation->id}");

        $response->assertOk()->assertJsonPath('data.id', $translation->id);
    }

    public function test_show_returns_404_for_missing_translation(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/translations/999999');

        $response->assertStatus(404);
    }

    public function test_authenticated_user_can_update_translation_content_locale_and_tags(): void
    {
        $this->authenticate();
        $translation = Translation::factory()->create([
            'locale_id' => Locale::query()->where('code', 'en')->value('id'),
            'translation_key' => 'welcome.message',
            'content' => 'Welcome',
        ]);
        $translation->tags()->attach(Tag::factory()->create(['name' => 'old-tag']));

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'content' => 'Welcome back',
            'locale' => 'fr',
            'tags' => ['web'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.content', 'Welcome back')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.tags', ['web']);

        $this->assertDatabaseHas('translations', ['id' => $translation->id, 'content' => 'Welcome back']);
        $this->assertDatabaseMissing('translation_tag', ['translation_id' => $translation->id, 'tag_id' => Tag::query()->where('name', 'old-tag')->value('id')]);
    }

    public function test_update_prevents_duplicate_locale_and_key_combination(): void
    {
        $this->authenticate();
        $localeId = Locale::query()->where('code', 'en')->value('id');

        Translation::factory()->create(['locale_id' => $localeId, 'translation_key' => 'existing.key']);
        $translation = Translation::factory()->create(['locale_id' => $localeId, 'translation_key' => 'other.key']);

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'key' => 'existing.key',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_authenticated_user_can_delete_translation(): void
    {
        $this->authenticate();
        $translation = Translation::factory()->create([
            'locale_id' => Locale::query()->where('code', 'en')->value('id'),
        ]);
        $translation->tags()->attach(Tag::factory()->create());

        $response = $this->deleteJson("/api/translations/{$translation->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('translations', ['id' => $translation->id]);
        $this->assertDatabaseMissing('translation_tag', ['translation_id' => $translation->id]);
    }
}
