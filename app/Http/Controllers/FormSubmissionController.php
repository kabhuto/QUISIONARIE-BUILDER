<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitSurveyResponseRequest;
use App\Models\Assignment;
use App\Models\Survey;
use App\Support\GoogleDrive;
use App\Support\Lookup;
use App\Support\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public, respondent-facing side of a published survey.
 */
class FormSubmissionController extends Controller
{
    public function create(Request $request, Survey $survey): View
    {
        $assignment = $this->assignmentFor($request, $survey);

        if ($assignment && ($assignment->isSubmitted() || $assignment->isExpired())) {
            return view('form.thanks', ['survey' => $survey, 'assignmentClosed' => $assignment->isSubmitted() ? 'submitted' : 'expired']);
        }

        // An assignment's pre-filled answers start the form, unless the respondent's own input is back after an error.
        if ($assignment && ! $request->session()->hasOldInput()) {
            $request->session()->now('_old_input', ['answers' => $assignment->predata ?? []]);
        }

        return view('form.fill', [
            'survey' => $assignment ? $assignment->applyTo($survey) : $survey,
            'isPreview' => false,
            'assignment' => $assignment,
        ]);
    }

    public function store(SubmitSurveyResponseRequest $request, Survey $survey): RedirectResponse
    {
        abort_unless($survey->isPublished(), 404);

        $response = $survey->responses()->create(['answers' => $request->answers(), 'flags' => $request->flags(), 'source' => 'form']);
        $request->assignment()?->update(['response_id' => $response->id]);
        Webhook::responseCreated($survey, $response);
        GoogleDrive::responseCreated($survey, $response);

        return redirect()->route('form.thanks', $survey);
    }

    public function thanks(Survey $survey): View
    {
        return view('form.thanks', ['survey' => $survey]);
    }

    public function preview(Survey $survey): View
    {
        return view('form.fill', ['survey' => $survey, 'isPreview' => true, 'assignment' => null]);
    }

    /**
     * Options of a dropdown that takes them from a reference list or outside API (fetched by the form).
     */
    public function lookup(Request $request, Survey $survey, string $question): JsonResponse
    {
        $found = collect($survey->questions())->firstWhere('id', $question);
        abort_unless($found && Lookup::isLookup($found), 404);

        $parent = ($found['lookup']['depends_on'] ?? '') === '' ? null : trim((string) $request->query('parent', ''));
        $options = $parent === '' ? [] : Lookup::optionsFor($found, $parent);

        return response()->json(['data' => $options ?? [], 'available' => $options !== null]);
    }

    private function assignmentFor(Request $request, Survey $survey): ?Assignment
    {
        $token = (string) $request->query('a', '');

        return $token === '' ? null : $survey->assignments()->where('token', $token)->firstOrFail();
    }
}
