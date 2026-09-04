<?php

namespace App\Console\Commands;

use App\Models\Locale;
use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedTranslations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'translations:seed
        {--count=100000 : Total number of translation records to generate}
        {--locales=en,fr,es,de,it,pt,ar : Comma separated list of locale codes to seed}
        {--tags=web,mobile,desktop,backend,admin : Comma separated list of tag names to seed}
        {--chunk=2000 : Number of translation rows to insert per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed the database with a large, realistic set of translations for performance testing';

    private const LOCALE_NAMES = [
        'en' => 'English',
        'fr' => 'French',
        'es' => 'Spanish',
        'de' => 'German',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ar' => 'Arabic',
        'nl' => 'Dutch',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
    ];

    public function handle(): int
    {
        $totalCount = max(1, (int) $this->option('count'));
        $localeCodes = array_filter(array_map('trim', explode(',', (string) $this->option('locales'))));
        $tagNames = array_filter(array_map('trim', explode(',', (string) $this->option('tags'))));
        $chunkSize = max(100, (int) $this->option('chunk'));

        if ($localeCodes === []) {
            $this->error('At least one locale must be provided.');

            return self::FAILURE;
        }

        $locales = collect($localeCodes)->map(fn (string $code): Locale => Locale::query()->firstOrCreate(
            ['code' => $code],
            ['name' => self::LOCALE_NAMES[$code] ?? Str::title($code)],
        ));

        $tags = collect($tagNames)->map(fn (string $name): Tag => Tag::query()->firstOrCreate(['name' => $name]));
        $tagIds = $tags->pluck('id')->all();

        $localeIds = $locales->pluck('id')->all();
        $keysPerLocale = (int) ceil($totalCount / count($localeIds));

        $this->info(sprintf(
            'Seeding up to %d translations across %d locale(s) (~%d keys per locale)...',
            $keysPerLocale * count($localeIds),
            count($localeIds),
            $keysPerLocale,
        ));

        $faker = fake();
        $bar = $this->output->createProgressBar($keysPerLocale * count($localeIds));
        $bar->start();

        $sections = ['auth', 'dashboard', 'settings', 'billing', 'profile', 'notifications', 'errors', 'validation', 'nav', 'checkout'];

        $rows = [];
        $now = now();

        for ($i = 0; $i < $keysPerLocale; $i++) {
            $section = $sections[$i % count($sections)];
            $key = sprintf('%s.%s_%d', $section, Str::slug($faker->words(2, true), '_'), $i);

            foreach ($localeIds as $localeId) {
                $rows[] = [
                    'locale_id' => $localeId,
                    'translation_key' => $key,
                    'content' => $faker->sentence(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) >= $chunkSize) {
                    $this->insertChunk($rows, $tagIds);
                    $bar->advance(count($rows));
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            $this->insertChunk($rows, $tagIds);
            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Seeding complete. Total translations: '.DB::table('translations')->count());

        return self::SUCCESS;
    }

    /**
     * Bulk insert a chunk of translation rows and attach a random set of tags to each.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $tagIds
     */
    private function insertChunk(array $rows, array $tagIds): void
    {
        DB::transaction(function () use ($rows, $tagIds): void {
            DB::table('translations')->insert($rows);

            // MySQL's LAST_INSERT_ID() (and therefore PDO::lastInsertId()) reports the
            // FIRST auto-generated id of a multi-row insert, not the last.
            $firstId = (int) DB::getPdo()->lastInsertId();
            $lastId = $firstId + count($rows) - 1;

            if ($tagIds === []) {
                return;
            }

            $pivotRows = [];
            $now = now();

            for ($translationId = $firstId; $translationId <= $lastId; $translationId++) {
                $tagCount = random_int(0, min(3, count($tagIds)));

                if ($tagCount === 0) {
                    continue;
                }

                foreach ((array) array_rand($tagIds, $tagCount) as $tagIndex) {
                    $pivotRows[] = [
                        'translation_id' => $translationId,
                        'tag_id' => $tagIds[$tagIndex],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($pivotRows !== []) {
                DB::table('translation_tag')->insert($pivotRows);
            }
        });
    }
}
