<?php

namespace Database\Seeders;

use App\Models\Survey;
use App\Support\SurveyJson;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Loads the dummy questionnaires (with their responses and kop logos) from JSON export files,
 * so a fresh installation has realistic data to try the builder, responses, print and listing.
 */
class DummySurveySeeder extends Seeder
{
    /**
     * Seed the dummy surveys.
     */
    public function run(): void
    {
        foreach (glob(database_path('seeders/data/*.json')) as $path) {
            $file = json_decode((string) file_get_contents($path), true);
            SurveyJson::assertExportFile($file);

            if (Survey::query()->where('title', $file['survey']['title'])->exists()) {
                continue;
            }

            SurveyJson::restoreLogos($file);

            $survey = Survey::query()->create([
                ...SurveyJson::definition($file['survey']),
                // Set explicitly: the seeder runs without model events, so the slug hook does not fire.
                'slug' => Survey::uniqueSlug(),
                'published_at' => now(),
            ]);

            foreach ($file['responses'] as $response) {
                $survey->responses()->create(['answers' => $response['answers'], 'flags' => $response['flags'] ?? null])
                    ->forceFill(['created_at' => Carbon::parse($response['submitted_at']), 'updated_at' => Carbon::parse($response['submitted_at'])])
                    ->save();
            }
        }
    }
}
