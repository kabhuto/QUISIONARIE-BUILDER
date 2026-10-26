<?php

namespace App\Support;

use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells another system about each new response: a signed JSON POST to the survey's webhook URL,
 * sent after the respondent's page has been answered so a slow receiver never delays them.
 */
class Webhook
{
    /**
     * The key receivers use to check X-Ruang-Signature (HMAC-SHA256 of the raw body).
     */
    public static function secret(): string
    {
        return hash_hmac('sha256', 'ruang-kuesioner-webhook', (string) config('app.key'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Survey $survey, SurveyResponse $response): array
    {
        return [
            'event' => 'response.created',
            'survey' => ['id' => $survey->id, 'slug' => $survey->slug, 'title' => $survey->title, 'form_code' => $survey->form_code],
            'response' => SurveyJson::responseData($response),
        ];
    }

    public static function responseCreated(Survey $survey, SurveyResponse $response): void
    {
        $url = trim((string) ($survey->formSettings()['webhook_url'] ?? ''));

        if ($url === '') {
            return;
        }

        $body = json_encode(self::payload($survey, $response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        app()->terminating(function () use ($url, $body, $survey) {
            try {
                Http::timeout(5)
                    ->withHeaders([
                        'X-Ruang-Event' => 'response.created',
                        'X-Ruang-Signature' => 'sha256='.hash_hmac('sha256', (string) $body, self::secret()),
                    ])
                    ->withBody((string) $body, 'application/json')
                    ->post($url)
                    ->throw();
            } catch (Throwable $exception) {
                Log::warning('Webhook kuesioner gagal dikirim.', ['survey' => $survey->id, 'url' => $url, 'error' => $exception->getMessage()]);
            }
        });
    }
}
