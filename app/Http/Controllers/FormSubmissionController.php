<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitSurveyResponseRequest;
use App\Models\Survey;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The public, respondent-facing side of a published survey.
 */
class FormSubmissionController extends Controller
{
    public function create(Survey $survey): View
    {
        return view('form.fill', ['survey' => $survey, 'isPreview' => false]);
    }

    public function store(SubmitSurveyResponseRequest $request, Survey $survey): RedirectResponse
    {
        abort_unless($survey->isPublished(), 404);

        $survey->responses()->create(['answers' => $request->answers(), 'flags' => $request->flags()]);

        return redirect()->route('form.thanks', $survey);
    }

    public function thanks(Survey $survey): View
    {
        return view('form.thanks', ['survey' => $survey]);
    }

    public function preview(Survey $survey): View
    {
        return view('form.fill', ['survey' => $survey, 'isPreview' => true]);
    }
}
