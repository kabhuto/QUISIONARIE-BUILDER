<?php

namespace App\Support;

use App\Jobs\SendResponseToGoogleDrive;
use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copies each response to a Google Drive folder through a small Apps Script web app that the
 * folder's owner deploys (a plain Gmail account cannot give a server direct write access).
 * The uploaded files, signatures included, go into "<survey>/<date>/" and every response is one
 * row of the "Rekap" spreadsheet next to them; the links come back and are kept on the response.
 */
class GoogleDrive
{
    public const WEB_APP_PATTERN = '/^https:\/\/script\.google\.com\/macros\/s\/[A-Za-z0-9_-]+\/exec$/';

    /**
     * The key the Apps Script checks, so only this app can write into the folder.
     */
    public static function secret(): string
    {
        return substr(hash_hmac('sha256', 'ruang-kuesioner-google-drive', (string) config('app.key')), 0, 32);
    }

    /**
     * The folder id in a Drive folder link, or the id itself.
     */
    public static function folderId(string $link): ?string
    {
        $link = trim($link);
        if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $link, $match) === 1) {
            return $match[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{10,}$/', $link) === 1 ? $link : null;
    }

    /**
     * The Apps Script source for the folder, ready to paste into script.google.com.
     */
    public static function script(string $folderId): string
    {
        return str_replace(
            ['__FOLDER_ID__', '__KEY__'],
            [$folderId, self::secret()],
            (string) file_get_contents(resource_path('stubs/google-drive-apps-script.js')),
        );
    }

    public static function url(Survey $survey): string
    {
        return trim((string) ($survey->formSettings()['drive_url'] ?? ''));
    }

    /**
     * Queues the copy of a new response when the survey is linked to a Drive folder.
     */
    public static function responseCreated(Survey $survey, SurveyResponse $response): void
    {
        if (self::url($survey) === '') {
            return;
        }

        $response->update(['drive' => ['status' => 'pending']]);
        SendResponseToGoogleDrive::dispatch($response->id);
    }

    /**
     * What is sent for one response: the recap row (header, text, file keys) and the files.
     *
     * @return array<string, mixed>
     */
    public static function payload(Survey $survey, SurveyResponse $response): array
    {
        $files = [];
        $row = [];

        foreach ($survey->questions() as $question) {
            $keys = [];
            $answer = $response->answerTo($question['id']);

            if (in_array($question['type'], ['file', 'signature'], true)) {
                foreach (array_values(is_array($answer) ? $answer : [$answer]) as $index => $value) {
                    $file = self::file($question, $value, $response, is_array($answer) ? $index + 1 : null);
                    if ($file !== null) {
                        $files[] = $file;
                        $keys[] = $file['key'];
                    }
                }
            }

            $text = $response->displayAnswer($question);
            if ($question['type'] === 'location' && is_numeric($accuracy = $response->answers[$question['id'].Survey::ACCURACY_SUFFIX] ?? null)) {
                $text .= " (±{$accuracy} m)";
            }
            $row[] = [$question['number'].'. '.$question['text'], $text, $keys];
        }

        return [
            'key' => self::secret(),
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'folder' => Str::limit($survey->title, 100, ''),
                'sheet' => 'Rekap - '.Str::limit($survey->title, 90, ''),
            ],
            'response' => [
                'id' => $response->id,
                'submitted_at' => $response->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'date' => $response->created_at->timezone(config('app.timezone'))->format('Y-m-d'),
                'started_at' => $response->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'ip_address' => $response->ip_address,
                'device' => $response->deviceLabel(),
                'detail_url' => route('surveys.responses.show', [$survey, $response]),
            ],
            'row' => $row,
            'files' => $files,
        ];
    }

    /**
     * Sends the response and keeps the outcome (links or the error) on it.
     */
    public static function send(Survey $survey, SurveyResponse $response): bool
    {
        try {
            $result = Http::timeout(120)
                ->acceptJson()
                ->post(self::url($survey), self::payload($survey, $response))
                ->throw()
                ->json();

            if (! is_array($result) || ($result['ok'] ?? false) !== true) {
                throw new RuntimeException(is_array($result) ? (string) ($result['error'] ?? 'Jawaban Apps Script tidak dikenali.') : 'Jawaban Apps Script bukan JSON. Periksa akses Web App: "Anyone".');
            }

            $response->update(['drive' => [
                'status' => 'sent',
                'sent_at' => now()->toIso8601String(),
                'folder_url' => $result['folder_url'] ?? null,
                'sheet_url' => $result['sheet_url'] ?? null,
                'files' => $result['files'] ?? [],
            ]]);

            return true;
        } catch (Throwable $exception) {
            $response->update(['drive' => [
                'status' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'error' => Str::limit($exception->getMessage(), 300),
            ]]);

            return false;
        }
    }

    /**
     * Checks that the web app answers with the right key; returns the folder's name and link.
     *
     * @return array{ok: bool, folder?: string, folder_url?: string, error?: string}
     */
    public static function ping(string $url): array
    {
        try {
            $result = Http::timeout(30)->acceptJson()->post($url, ['key' => self::secret(), 'ping' => true])->throw()->json();

            return is_array($result) && ($result['ok'] ?? false) === true
                ? ['ok' => true, 'folder' => (string) ($result['folder'] ?? ''), 'folder_url' => (string) ($result['folder_url'] ?? '')]
                : ['ok' => false, 'error' => is_array($result) ? (string) ($result['error'] ?? 'Jawaban tidak dikenali.') : 'Jawaban bukan JSON. Pastikan Web App dapat diakses oleh "Anyone".'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => Str::limit($exception->getMessage(), 300)];
        }
    }

    /**
     * One uploaded file or signature as base64, named so it sorts by response and question.
     *
     * @param  array<string, mixed>  $question
     * @return array{key: string, name: string, mime: string, data: string, signature?: bool}|null
     */
    private static function file(array $question, mixed $value, SurveyResponse $response, ?int $row): ?array
    {
        $base = 'R'.$response->id.'_'.$question['number'].'_'.Str::slug(Str::limit($question['text'], 40, ''), '-').($row !== null ? '_'.$row : '');

        if ($question['type'] === 'signature') {
            if (! is_string($value) || ! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $value, $match)) {
                return null;
            }

            return ['key' => $question['id'].($row !== null ? '.'.$row : ''), 'name' => $base.'.png', 'mime' => 'image/png', 'data' => $match[1], 'signature' => true];
        }

        if (! Survey::isUploadPath($value) || ! Storage::disk('public')->exists($value)) {
            return null;
        }

        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));

        return [
            'key' => $question['id'].($row !== null ? '.'.$row : ''),
            'name' => $base.(UploadCompressor::isSignedPath($value) ? UploadCompressor::SIGNED_SUFFIX : '').'.'.$extension,
            'mime' => (string) (Storage::disk('public')->mimeType($value) ?: 'application/octet-stream'),
            'data' => base64_encode((string) Storage::disk('public')->get($value)),
        ];
    }
}
