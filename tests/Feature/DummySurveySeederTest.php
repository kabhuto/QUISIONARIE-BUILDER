<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Support\ResponseValidator;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DummySurveySeeder;
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
            'DUMMY: SURVEI KEBUTUHAN DATA KONSUMEN 2026',
            'DUMMY: SURVEI SOSIAL EKONOMI RUMAH TANGGA 2026',
        ], $surveys->pluck('title')->all());
        $this->assertSame([10, 10], $surveys->pluck('responses_count')->all());
        $this->assertSame([10, 40], $surveys->map(fn (Survey $survey): int => count($survey->questions()))->all());

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
                $this->assertSame([], ResponseValidator::check($survey, $response->answers)['errors']);
            }
        }
    }

    public function test_seeding_twice_does_not_duplicate_the_dummy_surveys(): void
    {
        Storage::fake('public');

        $this->seed(DummySurveySeeder::class);
        $this->seed(DummySurveySeeder::class);

        $this->assertSame(2, Survey::query()->count());
    }
}
