<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\User;
use App\Support\Palette;
use App\Support\SurveySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResponseDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; SM-A146P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';

    private function survey(array $settings = []): Survey
    {
        $question = fn (string $id, string $type, string $label, array $extra = []): array => ['id' => $id, 'type' => $type, 'label' => $label, 'required' => false, 'code_length' => 0, 'options' => [], ...$extra];

        return Survey::query()->create([
            'title' => 'Absensi Harian', 'form_code' => 'ABS', 'activity' => 'Uji coba', 'settings' => [...Survey::DEFAULT_SETTINGS, ...$settings],
            'blocks' => [['id' => 'b-1', 'title' => 'ABSEN', 'layout' => 'full', 'condition' => null, 'questions' => [
                $question('q-nama', 'short', 'Nama'),
                $question('q-hadir', 'single', 'Hari ini masuk?', ['options' => ['Masuk', 'Tidak']]),
                $question('q-kegiatan', 'multiple', 'Kegiatan', ['options' => ['Rapat', 'Lapangan', 'Kantor']]),
                $question('q-lahir', 'date', 'Tanggal lahir'),
                $question('q-foto', 'file', 'Foto kegiatan', ['file' => ['accept' => 'image', 'max_mb' => 5]]),
            ]]],
            'published_at' => now(),
        ]);
    }

    public function test_a_response_records_when_filling_began_and_the_sending_device(): void
    {
        $survey = $this->survey();
        $startedAt = now()->subMinutes(4)->subSeconds(12);

        $this->withHeaders(['User-Agent' => self::PHONE])
            ->post(route('form.store', $survey), ['_started_at' => $startedAt->getTimestampMs(), 'answers' => ['q-nama' => 'Alfin']])
            ->assertRedirect();

        $response = $survey->responses()->sole();
        $this->assertSame('127.0.0.1', $response->ip_address);
        $this->assertSame('Android · Chrome', $response->deviceLabel());
        $this->assertSame($startedAt->timestamp, $response->started_at->timestamp);
        $this->assertSame('4 mnt 12 dtk', $response->durationLabel());

        $this->get(route('surveys.responses.show', [$survey, $response]))
            ->assertOk()
            ->assertSee('Mulai mengisi')
            ->assertSee('4 mnt 12 dtk')
            ->assertSee('127.0.0.1')
            ->assertSee('Android · Chrome');

        $csv = $this->get(route('surveys.responses.export', $survey))->streamedContent();
        $this->assertStringContainsString('"Waktu mulai mengisi","Lama mengisi (detik)","Alamat IP",Perangkat,"User agent"', $csv);
        $this->assertStringContainsString(',252,127.0.0.1,"Android · Chrome",', $csv);
    }

    public function test_a_start_time_that_is_not_believable_is_ignored(): void
    {
        $survey = $this->survey();

        $this->post(route('form.store', $survey), ['_started_at' => now()->subMonth()->getTimestampMs(), 'answers' => ['q-nama' => 'A']]);
        $this->post(route('form.store', $survey), ['_started_at' => 'kemarin', 'answers' => ['q-nama' => 'B']]);

        $this->assertSame([null, null], $survey->responses()->pluck('started_at')->all());
    }

    public function test_each_question_gets_a_chart_that_suits_its_answers(): void
    {
        Storage::fake('public');
        $survey = $this->survey();
        foreach ([['Masuk', ['Rapat', 'Kantor'], '1980-02-01', 60], ['Masuk', ['Kantor'], '1995-07-12', 180], ['Tidak', [], '2003-11-30', 900]] as $index => [$present, $activities, $born, $kilobytes]) {
            $path = "uploads/{$survey->id}/foto{$index}.jpg";
            Storage::disk('public')->put($path, str_repeat('x', $kilobytes * 1024));
            $survey->responses()->create(['answers' => ['q-hadir' => $present, 'q-kegiatan' => $activities, 'q-lahir' => $born, 'q-foto' => $path]]);
        }

        $summary = collect(SurveySummary::build($survey, $survey->responses()->latest()->get()))->keyBy('question.id');

        $this->assertSame('pie', $summary['q-hadir']['chart']['type']);
        $this->assertSame(['Masuk', 'Tidak'], $summary['q-hadir']['chart']['categories']);
        $this->assertSame([2, 1], $summary['q-hadir']['chart']['series'][0]['data']);

        $this->assertSame('bar', $summary['q-kegiatan']['chart']['type']);
        $this->assertSame([1, 0, 2], $summary['q-kegiatan']['chart']['series'][0]['data']);

        // Dates spread over decades are counted per span of years, not per day.
        $this->assertSame('column', $summary['q-lahir']['chart']['type']);
        $this->assertSame(['1980–1984', '1985–1989', '1990–1994', '1995–1999', '2000–2004'], $summary['q-lahir']['chart']['categories']);

        $this->assertSame('files', $summary['q-foto']['kind']);
        $sizes = array_combine($summary['q-foto']['chart']['categories'], $summary['q-foto']['chart']['series'][0]['data']);
        $this->assertSame(1, $sizes['1–100 KB']);
        $this->assertSame(1, $sizes['101–250 KB']);
        $this->assertSame(1, $sizes['501 KB–1 MB']);
        $this->assertSame(3, $summary['q-foto']['files']['count']);

        $this->get(route('surveys.responses.index', $survey))
            ->assertOk()
            ->assertSee('Gambaran respons')
            ->assertSee('Perangkat responden')
            ->assertSee('data-chart', false)
            ->assertSee('echarts.min.js', false)
            ->assertSee('Lihat angka');
    }

    public function test_an_admin_picks_their_own_colour_for_the_admin_pages(): void
    {
        config(['app.admin_open_on_lan' => false]);
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->putJson(route('accent.update'), ['color' => 'pink'])->assertUnprocessable();
        $this->putJson(route('accent.update'), ['color' => '#D6336C'])->assertOk()->assertJsonPath('color', '#d6336c');
        $this->assertSame('#d6336c', $admin->fresh()->accentColor());

        $this->get(route('surveys.index'))
            ->assertOk()
            ->assertSee('dataset.accent = "#d6336c"', false)
            ->assertSee('/warna/d6336c.css?v='.Palette::cssVersion(), false)
            ->assertSee('Warna tampilan');

        $css = $this->get(route('accent.css', 'd6336c'))->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString(':root:root[data-accent]{', $css);
        $this->assertStringContainsString('--brand:#d6336c', $css);
        $this->assertStringContainsString('[data-theme="dark"]', $css);

        // Choosing the default colour goes back to the original look.
        $this->putJson(route('accent.update'), ['color' => Palette::DEFAULT_ACCENT])->assertOk()->assertJsonPath('css', null);
        $this->assertNull($admin->fresh()->accentColor());
    }

    public function test_text_on_an_accent_stays_readable(): void
    {
        $this->assertSame('#ffffff', Palette::ink('#087e78'));
        $this->assertSame('#ffffff', Palette::ink('#d6336c'));
        $this->assertSame('#1a1a1a', Palette::ink('#ffd43b'));
        $this->assertSame('#087e78', Palette::fromOklch(...Palette::oklch('#087e78')));
    }

    public function test_a_survey_form_can_use_any_colour(): void
    {
        $survey = $this->survey(['theme' => 'custom', 'theme_color' => '#ffd43b']);

        $this->get(route('form.create', $survey))
            ->assertOk()
            ->assertSee('--accent: #ffd43b; --accent-ink: #1a1a1a', false)
            ->assertSee('Ringkasan isian')
            ->assertSee('data-nav-questions', false)
            ->assertSee('data-mobilebar', false)
            ->assertSee('name="_started_at"', false);

        $this->assertSame('#d6336c', $this->survey(['theme' => 'pink'])->themeColor());
        $this->assertSame(Survey::THEMES['orange']['color'], $this->survey(['theme' => 'custom', 'theme_color' => 'merah'])->themeColor());
    }
}
