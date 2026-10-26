<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\GoogleDrive;
use App\Support\ResponseValidator;
use App\Support\SurveyJson;
use App\Support\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Responses over the API: read them, check answers without saving (the form engine as a service),
 * and send one or many — every one goes through the same rules as the respondent form.
 */
class ResponseController extends Controller
{
    public function index(Request $request, Survey $survey): JsonResponse
    {
        $query = $survey->responses()->with('assignment')->oldest('id');

        if ($since = $request->query('since')) {
            $query->where('created_at', '>=', Carbon::parse((string) $since));
        }
        if ($after = (int) $request->query('after_id', 0)) {
            $query->where('id', '>', $after);
        }

        $page = $query->paginate(min(200, max(1, (int) $request->query('per_page', 50))));

        return response()->json([
            'data' => collect($page->items())->map(fn (SurveyResponse $response): array => SurveyJson::responseData($response))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ]);
    }

    public function show(Survey $survey, SurveyResponse $response): JsonResponse
    {
        return response()->json(['data' => SurveyJson::responseData($response->load('assignment'))]);
    }

    /**
     * Checks answers like a submission would, without storing them.
     */
    public function check(Request $request, Survey $survey): JsonResponse
    {
        $result = $this->evaluate($request, $survey, keepFiles: false);

        return response()->json($this->report($result) + ['answers' => $result['answers'] ?: (object) []]);
    }

    public function store(Request $request, Survey $survey): JsonResponse
    {
        $result = $this->evaluate($request, $survey, keepFiles: true);

        if (! $result['valid']) {
            return response()->json(['message' => 'Jawaban belum valid.'] + $this->report($result), 422);
        }

        $response = $this->save($survey, $result, $request->input('submitted_at'));

        return response()->json(['data' => SurveyJson::responseData($response), 'computed' => $result['computed'] ?: (object) []], 201);
    }

    /**
     * Sends up to 500 responses (e.g. from offline CAPI devices); each is accepted or rejected on its own.
     */
    public function batch(Request $request, Survey $survey): JsonResponse
    {
        $request->validate(['responses' => ['required', 'array', 'min:1', 'max:500']]);
        $results = [];

        foreach (array_values($request->input('responses')) as $index => $item) {
            $item = is_array($item) ? $item : [];
            $result = ResponseValidator::evaluate($survey, (array) ($item['answers'] ?? []), (array) ($item['confirmations'] ?? []), $item['a'] ?? $item['assignment_token'] ?? null);

            if (! $result['valid']) {
                $results[] = ['index' => $index, 'status' => 'rejected'] + $this->report($result);

                continue;
            }

            $response = $this->save($survey, $result, $item['submitted_at'] ?? null);
            $results[] = ['index' => $index, 'status' => 'created', 'id' => $response->id, 'external_id' => $item['external_id'] ?? null];
        }

        $created = count(array_filter($results, fn (array $result): bool => $result['status'] === 'created'));

        return response()->json(['created' => $created, 'rejected' => count($results) - $created, 'results' => $results], $created > 0 ? 201 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(Request $request, Survey $survey, bool $keepFiles): array
    {
        $request->validate([
            'answers' => ['present', 'array'],
            'confirmations' => ['sometimes', 'array'],
        ]);

        return ResponseValidator::evaluate(
            $survey,
            (array) $request->input('answers', []),
            (array) $request->input('confirmations', []),
            $request->input('a', $request->input('assignment_token')),
            array_filter((array) $request->file('answers', []), fn ($file): bool => $file instanceof UploadedFile),
            $keepFiles,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function report(array $result): array
    {
        return [
            'valid' => $result['valid'],
            'errors' => $result['errors'] ?: (object) [],
            // Warnings only block sending until confirmed: resend with confirmations[check_id] = 1
            // (confirmations[check_id][row] = 1 in a repeating block).
            'warnings' => $result['warnings'],
            'computed' => $result['computed'] ?: (object) [],
            'rows' => $result['rows'] ?: (object) [],
            'visible_questions' => $result['visible'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function save(Survey $survey, array $result, mixed $submittedAt): SurveyResponse
    {
        $response = $survey->responses()->create(['answers' => $result['answers'], 'flags' => $result['flags'], 'source' => 'api']);

        if ($submittedAt) {
            $time = Carbon::parse((string) $submittedAt);
            $response->forceFill(['created_at' => $time, 'updated_at' => $time])->save();
        }

        $result['assignment']?->update(['response_id' => $response->id]);
        Webhook::responseCreated($survey, $response);
        GoogleDrive::responseCreated($survey, $response);

        return $response->load('assignment');
    }
}
