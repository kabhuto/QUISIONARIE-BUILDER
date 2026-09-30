<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use Illuminate\Http\RedirectResponse;

class PublishedSurveyController extends Controller
{
    public function store(Survey $survey): RedirectResponse
    {
        $survey->update(['published_at' => now()]);

        return back()->with('status', 'Kuesioner dipublikasikan. Bagikan tautannya ke responden.');
    }

    public function destroy(Survey $survey): RedirectResponse
    {
        $survey->update(['published_at' => null]);

        return back()->with('status', 'Kuesioner ditutup. Responden tidak dapat mengisi lagi.');
    }
}
