<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\SurveySummary;
use App\Support\Xlsx;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SurveyResponseController extends Controller
{
    /**
     * When and from what each response was sent, at the start of every export row.
     */
    private const METADATA_COLUMNS = ['Waktu kirim', 'Waktu mulai mengisi', 'Lama mengisi (detik)', 'Alamat IP', 'Perangkat', 'User agent', 'Versi instrumen'];

    public function index(Survey $survey): View
    {
        $responses = $survey->responses()->latest()->get();

        return view('responses.index', [
            'survey' => $survey,
            'responses' => $responses,
            'summary' => SurveySummary::build($survey, $responses),
            'overview' => SurveySummary::overview($responses),
            'previewQuestions' => self::previewQuestions($survey),
        ]);
    }

    public function show(Survey $survey, SurveyResponse $response): View
    {
        $position = $survey->responses()->where('id', '<=', $response->id)->count();

        return view('responses.show', [
            'survey' => $survey,
            'response' => $response,
            'position' => $position,
            'numbers' => $survey->questionNumbers(),
        ]);
    }

    /**
     * All responses as an Excel/Google Sheets workbook: one row per response, file answers and the
     * detail page as clickable links, signatures as pictures, GPS accuracy next to each location.
     */
    public function exportXlsx(Survey $survey): BinaryFileResponse
    {
        $questions = $survey->questions();
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $position = 0;

        Xlsx::write(
            $path,
            $survey->title,
            ['No', ...self::METADATA_COLUMNS, ...array_map(fn (array $question): string => self::heading($question, '. '), $questions), 'Detail respons'],
            $survey->responses()->oldest()->lazy()->map(function (SurveyResponse $response) use ($survey, $questions, &$position): array {
                return [
                    ++$position,
                    ...self::metadata($response),
                    ...array_map(function (array $question) use ($response): string|array {
                        // A drawn signature goes into its cell as a picture.
                        $answer = $response->answerTo($question['id']);
                        if ($question['type'] === 'signature' && Survey::isSignatureImage($answer)) {
                            return Xlsx::image((string) base64_decode(substr($answer, strlen('data:image/png;base64,'))));
                        }
                        $text = $response->displayAnswer($question);
                        $accuracy = $response->answers[$question['id'].Survey::ACCURACY_SUFFIX] ?? null;

                        return $question['type'] === 'location' && $text !== '' && is_numeric($accuracy) ? "{$text} (±{$accuracy} m)" : $text;
                    }, $questions),
                    route('surveys.responses.show', [$survey, $response]),
                ];
            }),
        );

        $filename = str($survey->title)->slug()->append('-respons-', now()->format('Ymd-His'), '.xlsx')->toString();

        return response()->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function export(Survey $survey): StreamedResponse
    {
        $questions = $survey->questions();
        $filename = str($survey->title)->slug()->append('-respons-', now()->format('Ymd-His'), '.csv')->toString();

        return response()->streamDownload(function () use ($survey, $questions) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'No',
                ...self::METADATA_COLUMNS,
                ...array_map(fn (array $question): string => self::heading($question, ' '), $questions),
            ]);

            $position = 0;
            foreach ($survey->responses()->oldest()->lazy() as $response) {
                fputcsv($output, [
                    ++$position,
                    ...self::metadata($response),
                    ...array_map(fn (array $question): string => $response->displayAnswer($question), $questions),
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A question's column heading: its variable code when it has one, its number, text and unit.
     *
     * @param  array<string, mixed>  $question
     */
    private static function heading(array $question, string $separator): string
    {
        $variable = ($question['variable'] ?? '') !== '' ? '['.$question['variable'].'] ' : '';
        $unit = ($question['unit'] ?? '') !== '' ? ' ('.$question['unit'].')' : '';

        return $variable.$question['number'].$separator.$question['text'].$unit;
    }

    /**
     * @return list<string|int>
     */
    private static function metadata(SurveyResponse $response): array
    {
        $timezone = config('app.timezone');

        return [
            $response->created_at->timezone($timezone)->format('Y-m-d H:i:s'),
            $response->started_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
            $response->durationSeconds() ?? '',
            $response->ip_address ?? '',
            $response->deviceLabel(),
            $response->user_agent ?? '',
            $response->survey_version ?? '',
        ];
    }

    /**
     * The table's answer columns: the first three questions, plus any signature (shown as its picture).
     *
     * @return list<array<string, mixed>>
     */
    private static function previewQuestions(Survey $survey): array
    {
        $questions = array_values(array_filter($survey->questions(), fn (array $question): bool => $question['type'] !== 'table' && empty($question['in_roster'])));
        $first = array_slice($questions, 0, 3);
        $signatures = array_filter($questions, fn (array $question): bool => $question['type'] === 'signature' && ! in_array($question, $first, true));

        return [...$first, ...array_slice(array_values($signatures), 0, 2)];
    }
}
