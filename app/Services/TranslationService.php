<?php

namespace App\Services;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TranslationService
{
    /**
     * Cache tag applied to every cached export payload, allowing a single,
     * reliable invalidation point whenever any translation data changes.
     */
    private const EXPORT_CACHE_TAG = 'translations-export';

    private const EXPORT_CACHE_TTL = 3600;

    /**
     * Search and paginate
     *
     * @param  array{key?: ?string, content?: ?string, locale?: ?string, tag?: ?string}  $filters
     */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Translation::query()
            ->select(['id', 'locale_id', 'translation_key', 'content', 'created_at', 'updated_at'])
            ->with(['locale:id,code', 'tags:id,name'])
            ->when($filters['key'] ?? null, fn ($query, $key) => $query->where('translation_key', 'like', "%{$key}%"))
            ->when($filters['content'] ?? null, fn ($query, $content) => $query->where('content', 'like', "%{$content}%"))
            ->when($filters['locale'] ?? null, fn ($query, $locale) => $query->whereRelation('locale', 'code', $locale))
            ->when($filters['tag'] ?? null, fn ($query, $tag) => $query->whereRelation('tags', 'name', $tag))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Create a translation along tag associations.
     *
     * @param  array{key: string, locale: string, content: string, tags?: list<string>}  $data
     */
    public function create(array $data): Translation
    {
        return DB::transaction(function () use ($data): Translation {
            $localeId = Locale::query()->where('code', $data['locale'])->value('id');

            $translation = Translation::query()->create([
                'locale_id' => $localeId,
                'translation_key' => $data['key'],
                'content' => $data['content'],
            ]);

            $this->syncTags($translation, $data['tags'] ?? []);
            $this->flushExportCache();

            return $translation->load(['locale:id,code', 'tags:id,name']);
        });
    }

    /**
     * Update a translation
     *
     * @param  array{key?: string, locale?: string, content?: string, tags?: list<string>}  $data
     */
    public function update(Translation $translation, array $data): Translation
    {
        return DB::transaction(function () use ($translation, $data): Translation {
            $attributes = [];

            if (array_key_exists('locale', $data)) {
                $attributes['locale_id'] = Locale::query()->where('code', $data['locale'])->value('id');
            }

            if (array_key_exists('key', $data)) {
                $attributes['translation_key'] = $data['key'];
            }

            if (array_key_exists('content', $data)) {
                $attributes['content'] = $data['content'];
            }

            if ($attributes !== []) {
                $translation->update($attributes);
            }

            if (array_key_exists('tags', $data)) {
                $this->syncTags($translation, $data['tags'] ?? []);
            }

            $this->flushExportCache();

            $fresh = $translation->fresh(['locale:id,code', 'tags:id,name']);

            return $fresh;
        });
    }

    public function delete(Translation $translation): void
    {
        $translation->delete();
        $this->flushExportCache();
    }

    /**
     * Build the locale => { key => content } export payload as a raw JSON
     * string using a cursor over a minimal, joined query builder result set,
     * caching the result so repeat requests avoid touching the database.
     */
    public function export(?string $locale, ?string $tag): string
    {
        $cacheKey = sprintf('translations:export:%s:%s', $locale ?? 'all', $tag ?? 'all');

        return Cache::tags([self::EXPORT_CACHE_TAG])->remember(
            $cacheKey,
            self::EXPORT_CACHE_TTL,
            fn (): string => $this->buildExportJson($locale, $tag),
        );
    }

    public function flushExportCache(): void
    {
        Cache::tags([self::EXPORT_CACHE_TAG])->flush();
    }

    /**
     * Attach the given tag names to the translation, creating any tags that
     * do not exist yet, and removing associations that are no longer present.
     *
     * @param  list<string>  $tagNames
     */
    private function syncTags(Translation $translation, array $tagNames): void
    {
        $tagIds = collect($tagNames)
            ->unique()
            ->map(fn (string $name): int => Tag::query()->firstOrCreate(['name' => $name])->id)
            ->all();

        $translation->tags()->sync($tagIds);
    }

    /**
     * @return string Raw JSON, e.g. {"en":{"welcome.message":"Welcome"},"fr":{...}}
     */
    private function buildExportJson(?string $locale, ?string $tag): string
    {
        $query = DB::table('translations')
            ->join('locales', 'locales.id', '=', 'translations.locale_id')
            ->when(
                $tag,
                fn ($query) => $query
                    ->join('translation_tag', 'translation_tag.translation_id', '=', 'translations.id')
                    ->join('tags', 'tags.id', '=', 'translation_tag.tag_id')
                    ->where('tags.name', $tag),
            )
            ->when($locale, fn ($query, $localeCode) => $query->where('locales.code', $localeCode))
            ->select(['locales.code as locale_code', 'translations.translation_key', 'translations.content'])
            ->orderBy('locales.code')
            ->orderBy('translations.translation_key');

        $json = '{';
        $currentLocale = null;
        $isFirstLocale = true;
        $isFirstKey = true;

        foreach ($query->cursor() as $row) {
            if ($row->locale_code !== $currentLocale) {
                $json .= $currentLocale === null ? '' : '}';
                $json .= $isFirstLocale ? '' : ',';
                $json .= json_encode($row->locale_code).':{';
                $currentLocale = $row->locale_code;
                $isFirstLocale = false;
                $isFirstKey = true;
            }

            $json .= ($isFirstKey ? '' : ',').json_encode($row->translation_key).':'.json_encode($row->content);
            $isFirstKey = false;
        }

        $json .= $currentLocale === null ? '' : '}';
        $json .= '}';

        return $json;
    }
}
