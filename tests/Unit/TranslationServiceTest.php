<?php

namespace Tests\Unit;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TranslationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TranslationService::class);
    }

    public function test_create_persists_translation_and_attaches_tags(): void
    {
        Locale::factory()->create(['code' => 'en']);

        $translation = $this->service->create([
            'key' => 'welcome.message',
            'locale' => 'en',
            'content' => 'Welcome',
            'tags' => ['web', 'mobile'],
        ]);

        $this->assertSame('welcome.message', $translation->translation_key);
        $this->assertSame('en', $translation->locale->code);
        $this->assertCount(2, $translation->tags);
        $this->assertDatabaseCount('tags', 2);
    }

    public function test_update_changes_locale_content_and_tags(): void
    {
        $en = Locale::factory()->create(['code' => 'en']);
        $fr = Locale::factory()->create(['code' => 'fr']);
        $translation = Translation::factory()->create(['locale_id' => $en->id]);
        $translation->tags()->attach(Tag::factory()->create(['name' => 'old']));

        $updated = $this->service->update($translation, [
            'locale' => 'fr',
            'content' => 'Bienvenue',
            'tags' => ['new'],
        ]);

        $this->assertSame('fr', $updated->locale->code);
        $this->assertSame('Bienvenue', $updated->content);
        $this->assertSame(['new'], $updated->tags->pluck('name')->all());
    }

    public function test_delete_removes_the_translation(): void
    {
        $en = Locale::factory()->create(['code' => 'en']);
        $translation = Translation::factory()->create(['locale_id' => $en->id]);

        $this->service->delete($translation);

        $this->assertDatabaseMissing('translations', ['id' => $translation->id]);
    }

    public function test_export_groups_content_by_locale_and_key(): void
    {
        $en = Locale::factory()->create(['code' => 'en']);
        Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'a.b', 'content' => 'Hello']);

        $json = $this->service->export(null, null);

        $this->assertSame(['en' => ['a.b' => 'Hello']], json_decode($json, true));
    }

    public function test_export_cache_is_invalidated_after_a_write(): void
    {
        $en = Locale::factory()->create(['code' => 'en']);
        $translation = Translation::factory()->create(['locale_id' => $en->id, 'translation_key' => 'a', 'content' => 'Old']);

        $this->assertSame(['en' => ['a' => 'Old']], json_decode($this->service->export(null, null), true));

        $this->service->update($translation, ['content' => 'New']);

        $this->assertSame(['en' => ['a' => 'New']], json_decode($this->service->export(null, null), true));
    }
}
