<?php

namespace Tests\Feature\Documents;

use App\Models\Applicant;
use App\Models\User;
use App\Services\Documents\FilenameGenerator;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FilenameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function applicant(string $first, string $last): Applicant
    {
        return new Applicant(['first_name' => $first, 'last_name' => $last, 'email' => 'x@example.com']);
    }

    public function test_default_pattern(): void
    {
        $name = app(FilenameGenerator::class)->forDocument($this->applicant('Maria', 'Garcia'), 'I-9', 'pdf', Carbon::parse('2026-11-05 12:00'));

        $this->assertSame('Garcia_Maria_I-9_20261105.pdf', $name);
    }

    public function test_unicode_and_unsafe_characters_are_sanitized(): void
    {
        $gen = app(FilenameGenerator::class);
        $name = $gen->forDocument($this->applicant("Zoë\u{0301} ../../etc", 'Nuñez-García\\x/y'), 'SSA 89', 'PDF', Carbon::parse('2026-01-02'));

        $this->assertSame('Nunez-Garciaxy_Zoe-etc_SSA-89_20260102.pdf', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
    }

    public function test_empty_tokens_and_extension_cleanup(): void
    {
        $name = app(FilenameGenerator::class)->forDocument($this->applicant('世界', '!!!'), '', 'p/d\\f', Carbon::parse('2026-01-02'));

        $this->assertSame('shi-jie_20260102.pdf', $name);
    }

    public function test_collisions_get_suffixes(): void
    {
        $gen = app(FilenameGenerator::class);
        $taken = [];

        $this->assertSame('a.pdf', $gen->unique('a.pdf', $taken));
        $this->assertSame('a-2.pdf', $gen->unique('a.pdf', $taken));
        $this->assertSame('A-3.pdf', $gen->unique('A.pdf', $taken));
    }

    public function test_pattern_and_date_format_follow_settings(): void
    {
        $hr = User::factory()->create();
        app(SettingsService::class)->update([
            'filename_pattern' => '{document_type}-{last_name}-{date}',
            'filename_date_format' => 'Y-m-d',
        ], $hr);

        $name = app(FilenameGenerator::class)->forDocument($this->applicant('A', 'Lee'), 'W4', 'jpg', Carbon::parse('2026-03-04'), 'Photo ID', 2);

        $this->assertSame('W4-Lee-2026-03-04_Photo-ID_v2.jpg', $name);
    }

    public function test_long_values_are_truncated(): void
    {
        $name = app(FilenameGenerator::class)->forDocument($this->applicant(str_repeat('a', 300), str_repeat('b', 300)), str_repeat('c', 300), 'pdf', now());

        $this->assertLessThanOrEqual(155, strlen($name));
    }
}
