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
    public function index(Survey $survey): View
    {
        $responses = $survey->responses()->latest()->get();

        return view('responses.index', [
            'survey' => $survey,
            'responses' => $responses,
            'summary' => SurveySummary::build($survey, $responses),
            'previewQuestions' => array_slice(array_values(array_filter($survey->questions(), fn (array $question): bool => $question['type'] !== 'table')), 0, 3),
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
     * detail page as clickable links, GPS accuracy next to each location.
     */
    public function exportXlsx(Survey $survey): BinaryFileResponse
    {
        $questions = $survey->questions();
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $position = 0;

        Xlsx::write(
            $path,
            $survey->title,
            ['No', 'Waktu kirim', ...array_map(fn (array $question): string => $question['number'].'. '.$question['text'], $questions), 'Detail respons'],
            $survey->responses()->oldest()->lazy()->map(function (SurveyResponse $response) use ($survey, $questions, &$position): array {
                return [
                    ++$position,
                    $response->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    ...array_map(function (array $question) use ($response): string {
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
                'Waktu kirim',
                ...array_map(fn (array $question): string => $question['number'].' '.$question['text'], $questions),
            ]);

            $position = 0;
            foreach ($survey->responses()->oldest()->lazy() as $response) {
                fputcsv($output, [
                    ++$position,
                    $response->created_at->format('Y-m-d H:i:s'),
                    ...array_map(fn (array $question): string => $response->displayAnswer($question), $questions),
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
