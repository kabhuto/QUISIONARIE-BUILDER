<?php

namespace App\Support;

use App\Http\Requests\SubmitSurveyResponseRequest;
use App\Models\Assignment;
use App\Models\Survey;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/**
 * Checks a set of answers against a survey with exactly the rules a respondent's submission gets:
 * question types, required fields, options, code lengths, table limits, logic, formulas,
 * repeating blocks and consistency checks. Used by data validation and the JSON API.
 */
class ResponseValidator
{
    /**
     * @param  array<string, mixed>  $answers  keyed by question id
     * @return array{answers: array<string, mixed>, errors: list<array{question_id: string|null, message: string}>}
     */
    public static function check(Survey $survey, array $answers): array
    {
        $result = self::evaluate($survey, $answers);
        $errors = [];

        foreach ($result['errors'] as $key => $messages) {
            $segments = explode('.', $key);
            foreach ($messages as $message) {
                $errors[] = ['question_id' => $segments[1] ?? null, 'message' => $message];
            }
        }

        return ['answers' => $result['valid'] ? $result['answers'] : [], 'errors' => $errors];
    }

    /**
     * Runs a submission without storing anything except, when $keepFiles is true, uploaded files.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $confirmations  check id => "1" (or row => "1" in a repeating block)
     * @param  array<string, UploadedFile>  $files  question id => uploaded file
     * @return array{
     *     valid: bool,
     *     answers: array<string, mixed>,
     *     flags: array<string, mixed>|null,
     *     errors: array<string, list<string>>,
     *     warnings: list<array<string, mixed>>,
     *     computed: array<string, mixed>,
     *     rows: array<string, list<string>>,
     *     visible: list<string>,
     *     assignment: Assignment|null
     * }
     */
    public static function evaluate(Survey $survey, array $answers, array $confirmations = [], ?string $assignmentToken = null, array $files = [], bool $keepFiles = false): array
    {
        $data = ['answers' => $answers, 'confirmations' => $confirmations];
        if ($assignmentToken !== null && $assignmentToken !== '') {
            $data['a'] = $assignmentToken;
        }

        $request = SubmitSurveyResponseRequest::create('/', 'POST', $data, [], $files === [] ? [] : ['answers' => $files]);
        $route = (new Route('POST', '{survey}', []))->bind($request);
        $route->setParameter('survey', $survey);
        $request->setRouteResolver(fn () => $route);
        $request->setContainer(app())->setRedirector(app('redirect'));

        try {
            $request->validateResolved();
        } catch (ValidationException $failure) {
            return [
                'valid' => false,
                'answers' => [],
                'flags' => null,
                'errors' => $failure->errors(),
                'warnings' => $request->pendingWarnings(),
                ...$request->evaluation(),
                'assignment' => $request->assignment(),
            ];
        }

        return [
            'valid' => true,
            'answers' => $keepFiles || $files === [] ? $request->answers() : [],
            'flags' => $request->flags(),
            'errors' => [],
            'warnings' => [],
            ...$request->evaluation(),
            'assignment' => $request->assignment(),
        ];
    }
}
