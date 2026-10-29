<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Support\SurveyCheck;
use App\Support\SurveyVersions;
use Illuminate\Http\RedirectResponse;

class PublishedSurveyController extends Controller
{
    public function store(Survey $survey): RedirectResponse
    {
        // An instrument with errors would collect data that cannot be used; fix them first.
        if (($errors = SurveyCheck::errors($survey)) !== []) {
            return back()->with('publishErrors', $errors)->with('error', 'Kuesioner belum dapat dipublikasikan. Perbaiki '.count($errors).' masalah pada pemeriksaan publikasi.');
        }

        $survey->update(['published_at' => now()]);
        $version = SurveyVersions::record($survey);

        return back()->with('status', "Kuesioner dipublikasikan (versi {$version->number}). Bagikan tautannya ke responden.");
    }

    public function destroy(Survey $survey): RedirectResponse
    {
        $survey->update(['published_at' => null]);

        return back()->with('status', 'Kuesioner ditutup. Responden tidak dapat mengisi lagi.');
    }
}
