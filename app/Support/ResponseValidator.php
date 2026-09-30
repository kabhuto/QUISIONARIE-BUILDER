<?php

namespace App\Support;

use App\Http\Requests\SubmitSurveyResponseRequest;
use App\Models\Survey;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/**
 * Checks a set of answers against a survey with exactly the rules a respondent's submission gets:
 * question types, required fields, options, code lengths, table limits and display logic.
 */
class ResponseValidator
{
    /**
     * @param  array<string, mixed>  $answers  keyed by question id
     * @return array{answers: array<string, mixed>, errors: list<array{question_id: string|null, message: string}>}
     */
    public static function check(Survey $survey, array $answers): array
    {
        $request = SubmitSurveyResponseRequest::create('/', 'POST', ['answers' => $answers]);
        $route = (new Route('POST', '{survey}', []))->bind($request);
        $route->setParameter('survey', $survey);
        $request->setRouteResolver(fn () => $route);
        $request->setContainer(app())->setRedirector(app('redirect'));

        try {
            $request->validateResolved();
        } catch (ValidationException $failure) {
            $errors = [];
            foreach ($failure->errors() as $key => $messages) {
                $segments = explode('.', $key);
                foreach ($messages as $message) {
                    $errors[] = ['question_id' => $segments[1] ?? null, 'message' => $message];
                }
            }

            return ['answers' => [], 'errors' => $errors];
        }

        return ['answers' => $request->answers(), 'errors' => []];
    }
}
