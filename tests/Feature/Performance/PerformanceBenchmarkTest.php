<?php

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Lightweight, repeatable performance smoke tests.
 *
 * These seed a few thousand translations (a small slice of the 100k+
 * production-scale dataset produced by `php artisan translations:seed
 * --count=100000`) and report response timings for the key endpoints.
 *
 * Thresholds are intentionally generous because CI/sandboxed hardware varies
 * significantly; the goal is to catch gross regressions, not to enforce the
 * <200ms/<500ms production targets documented in the README (those should be
 * measured locally against the full dataset).
 *
 * Run only this suite with: php artisan test --group=performance
 */
#[Group('performance')]
class PerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('translations:seed', [
            '--count' => 3000,
            '--locales' => 'en,fr,es',
            '--tags' => 'web,mobile,desktop',
            '--chunk' => 1000,
        ]);

        $this->authenticate();
    }

    public function test_translation_listing_responds_within_a_reasonable_time(): void
    {
        $this->benchmark('GET /api/translations', fn () => $this->getJson('/api/translations'));
    }

    public function test_locale_filtered_listing_responds_within_a_reasonable_time(): void
    {
        $this->benchmark('GET /api/translations?locale=en', fn () => $this->getJson('/api/translations?locale=en'));
    }

    public function test_tag_filtered_listing_responds_within_a_reasonable_time(): void
    {
        $this->benchmark('GET /api/translations?tag=mobile', fn () => $this->getJson('/api/translations?tag=mobile'));
    }

    public function test_export_responds_within_a_reasonable_time(): void
    {
        $this->benchmark('GET /api/translations/export', fn () => $this->getJson('/api/translations/export'), 3000.0);
    }

    /**
     * @param  callable(): TestResponse  $request
     */
    private function benchmark(string $label, callable $request, float $thresholdMs = 2000.0): void
    {
        $start = microtime(true);
        $response = $request();
        $durationMs = (microtime(true) - $start) * 1000;

        $response->assertOk();

        fwrite(STDERR, sprintf("\n[benchmark] %s -> %.2fms (threshold %.0fms)\n", $label, $durationMs, $thresholdMs));

        $this->assertLessThan($thresholdMs, $durationMs, "{$label} took {$durationMs}ms, exceeding the {$thresholdMs}ms sandbox threshold.");
    }
}
