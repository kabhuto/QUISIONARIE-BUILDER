<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Support\ResponseValidator;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DummySurveySeeder;
use Database\Seeders\ReadySurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DummySurveySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_loads_the_dummy_surveys_with_valid_responses_and_logos(): void
    {
        Storage::fake('public');

        $this->seed(DatabaseSeeder::class);

        $surveys = Survey::query()->withCount('responses')->orderBy('title')->get();
        $this->assertSame([
            'ABSENSI HARIAN PESERTA MAGANG',
            'DUMMY: PENDATAAN RUMAH TANGGA DAN KETENAGAKERJAAN 2026',
            'DUMMY: SURVEI KEBUTUHAN DATA KONSUMEN 2026',
            'DUMMY: SURVEI RUMAH TANGGA USAHA PETERNAKAN 2026',
            'DUMMY: SURVEI SOSIAL EKONOMI RUMAH TANGGA 2026',
        ], $surveys->pluck('title')->all());
        $this->assertSame([0, 12, 10, 8, 10], $surveys->pluck('responses_count')->all());
        $this->assertSame([16, 61, 10, 50, 40], $surveys->map(fn (Survey $survey): int => count($survey->questions()))->all());

        foreach ($surveys as $survey) {
            $this->assertTrue($survey->isPublished());
            $this->assertNotEmpty($survey->slug);
            $this->get(route('form.create', $survey))->assertOk();

            foreach ($survey->headerItems() as $item) {
                if ($item['path']) {
                    Storage::disk('public')->assertExists($item['path']);
                }
            }

            foreach ($survey->responses as $response) {
                // The only problems left are the consistency warnings the respondent confirmed.
                $messages = array_column(ResponseValidator::check($survey, $response->answers)['errors'], 'message');
                $confirmed = array_map(fn (array $warning): string => 'Peringatan: '.$warning['message'], $response->flags['confirmed_warnings'] ?? []);
                sort($messages);
                sort($confirmed);
                $this->assertSame($confirmed, $messages);
            }
        }
    }

    public function test_the_ready_attendance_survey_can_be_seeded_on_its_own(): void
    {
        Storage::fake('public');

        $this->seed(ReadySurveySeeder::class);
        $this->seed(ReadySurveySeeder::class);

        $survey = Survey::query()->sole();
        $this->assertSame('ABSENSI HARIAN PESERTA MAGANG', $survey->title);
        $this->assertSame(0, $survey->responses()->count());
        $this->assertSame('', $survey->formSettings()['drive_url']);
        $location = collect($survey->questions())->firstWhere('type', 'location');
        $this->assertSame(['max_accuracy' => 100], $location['location']);
    }

    public function test_seeding_twice_does_not_duplicate_the_dummy_surveys(): void
    {
        Storage::fake('public');

        $this->seed(DummySurveySeeder::class);
        $this->seed(DummySurveySeeder::class);

        $this->assertSame(4, Survey::query()->count());
    }
}
