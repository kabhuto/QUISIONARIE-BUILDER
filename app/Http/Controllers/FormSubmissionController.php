<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitSurveyResponseRequest;
use App\Models\Assignment;
use App\Models\Survey;
use App\Support\GoogleDrive;
use App\Support\Lookup;
use App\Support\SurveyVersions;
use App\Support\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

        // Sent again after a lost connection: the first one was stored, so just say thanks.
        $submissionId = $this->submissionId($request);
        if ($submissionId && $survey->responses()->where('submission_id', $submissionId)->exists()) {
            return redirect()->route('form.thanks', $survey);
        }

        $response = $survey->responses()->create([
            'survey_version' => SurveyVersions::current($survey),
            'submission_id' => $submissionId,
            'answers' => $request->answers(),
            'flags' => $request->flags(),
            'source' => 'form',
            'started_at' => $this->startedAt($request),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
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
     * The preview inside a phone frame, to try the form as a petugas would on their phone.
     */
    public function phonePreview(Survey $survey): View
    {
        return view('form.phone-preview', ['survey' => $survey]);
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

    /**
     * When the form was opened, as the page reported it (milliseconds since 1970); ignored when it
     * is missing or not within the last week.
     */
    private function startedAt(Request $request): ?Carbon
    {
        $milliseconds = $request->input('_started_at');
        if (! is_numeric($milliseconds)) {
            return null;
        }

        $startedAt = Carbon::createFromTimestampMs((int) $milliseconds);

        return $startedAt->between(now()->subWeek(), now()->addMinute()) ? $startedAt->min(now()) : null;
    }

    /**
     * The id the page gave this submission (kept with its draft), so a resend is recognised.
     */
    private function submissionId(Request $request): ?string
    {
        $id = (string) $request->input('_submission_id', '');

        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) ? $id : null;
    }

    private function assignmentFor(Request $request, Survey $survey): ?Assignment
    {
        $token = (string) $request->query('a', '');

        return $token === '' ? null : $survey->assignments()->where('token', $token)->firstOrFail();
    }
}
