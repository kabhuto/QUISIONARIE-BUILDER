<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\SurveySummary;
use Illuminate\View\View;
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
                ...array_map(fn (array $question): string => $question['number'].' '.$question['label'], $questions),
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
