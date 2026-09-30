<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Paper renditions: the questionnaire itself (blank or filled) and a listing of responses.
 */
class SurveyPrintController extends Controller
{
    public function form(Survey $survey): View
    {
        return $this->paper($survey, null);
    }

    public function response(Survey $survey, SurveyResponse $response): View
    {
        return $this->paper($survey, $response);
    }

    public function listing(Request $request, Survey $survey): View
    {
        $tables = $survey->tableQuestions();
        $table = collect($tables)->firstWhere('id', $request->query('source'));
        $responses = $survey->responses()->oldest()->get();

        [$columns, $rows] = $table
            ? $this->tableListing($table, $responses)
            : $this->responseListing($survey, $responses);

        return view('print.listing', [
            'survey' => $survey,
            'tables' => $tables,
            'source' => $table['id'] ?? 'responses',
            'columns' => $columns,
            'rows' => $rows,
            'blankRows' => max(0, 12 - count($rows)),
        ]);
    }

    private function paper(Survey $survey, ?SurveyResponse $response): View
    {
        return view('print.form', [
            'survey' => $survey,
            'response' => $response,
            'answers' => $response->answers ?? [],
            'numbers' => $survey->questionNumbers(),
            'settings' => $survey->formSettings(),
        ]);
    }

    /**
     * One row per response; region questions spread over one column per field.
     *
     * @param  iterable<SurveyResponse>  $responses
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function responseListing(Survey $survey, iterable $responses): array
    {
        $cells = [];

        foreach ($survey->questions() as $question) {
            if ($question['type'] === 'table') {
                continue;
            }

            if ($question['type'] === 'region') {
                foreach (Survey::regionFields($question) as $field) {
                    $cells[] = [$field['label'], fn (SurveyResponse $response): string => (string) ($response->answerTo($question['id'])[$field['key']] ?? '')];
                }

                continue;
            }

            $cells[] = [$question['label'], fn (SurveyResponse $response): string => $response->displayAnswer($question)];
        }

        $rows = [];
        foreach ($responses as $response) {
            $rows[] = array_map(fn (array $cell): string => $cell[1]($response), $cells);
        }

        return [array_column($cells, 0), $rows];
    }

    /**
     * Every row of one table question, across all responses.
     *
     * @param  array<string, mixed>  $table
     * @param  iterable<SurveyResponse>  $responses
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function tableListing(array $table, iterable $responses): array
    {
        $columns = $table['table']['columns'];
        $rows = [];

        foreach ($responses as $response) {
            foreach ((array) $response->answerTo($table['id']) as $row) {
                $rows[] = array_map(fn (array $column): string => (string) ($row[$column['id']] ?? ''), $columns);
            }
        }

        return [array_column($columns, 'label'), $rows];
    }
}
