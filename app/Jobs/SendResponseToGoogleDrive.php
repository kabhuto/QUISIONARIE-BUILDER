<?php

namespace App\Jobs;

use App\Models\SurveyResponse;
use App\Support\GoogleDrive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies a response to the survey's Google Drive folder in the background, so the respondent
 * does not wait for the upload; a failed attempt is retried a few minutes later.
 */
class SendResponseToGoogleDrive implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $responseId) {}

    public function handle(): void
    {
        $response = SurveyResponse::query()->with('survey')->find($this->responseId);

        if ($response === null || $response->survey === null || GoogleDrive::url($response->survey) === '') {
            return;
        }

        if (! GoogleDrive::send($response->survey, $response) && $this->attempts() < $this->tries) {
            $this->release(120 * $this->attempts());
        }
    }
}
