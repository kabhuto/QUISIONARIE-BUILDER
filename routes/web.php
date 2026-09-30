<?php

use App\Http\Controllers\DataValidationController;
use App\Http\Controllers\FormSubmissionController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\PublishedSurveyController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\SurveyJsonController;
use App\Http\Controllers\SurveyPrintController;
use App\Http\Controllers\SurveyResponseController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/surveys');

// Admin: build, preview, publish and review surveys.
Route::post('/surveys/import', [SurveyJsonController::class, 'import'])->name('surveys.import');
Route::get('/surveys/{survey}/export.json', [SurveyJsonController::class, 'export'])->name('surveys.export-json');
Route::get('/validasi', [DataValidationController::class, 'create'])->name('validation.create');
Route::post('/validasi', [DataValidationController::class, 'store'])->name('validation.store');
Route::resource('surveys', SurveyController::class)->except('show');
Route::get('/surveys/{survey}/preview', [FormSubmissionController::class, 'preview'])->name('surveys.preview');
Route::post('/published-surveys/{survey}', [PublishedSurveyController::class, 'store'])->name('published-surveys.store');
Route::delete('/published-surveys/{survey}', [PublishedSurveyController::class, 'destroy'])->name('published-surveys.destroy');
Route::get('/surveys/{survey}/print', [SurveyPrintController::class, 'form'])->name('surveys.print');
Route::get('/surveys/{survey}/responses/listing', [SurveyPrintController::class, 'listing'])->name('surveys.responses.listing');
Route::get('/surveys/{survey}/responses/{response}/print', [SurveyPrintController::class, 'response'])->name('surveys.responses.print')->scopeBindings();
Route::get('/logos', [LogoController::class, 'index'])->name('logos.index');
Route::post('/logos', [LogoController::class, 'store'])->name('logos.store');
Route::get('/surveys/{survey}/responses/export', [SurveyResponseController::class, 'export'])->name('surveys.responses.export');
Route::resource('surveys.responses', SurveyResponseController::class)->only(['index', 'show'])->scoped(['response' => 'id']);

// Respondents: fill in a published survey by its public link.
Route::get('/f/{survey:slug}', [FormSubmissionController::class, 'create'])->name('form.create');
Route::post('/f/{survey:slug}', [FormSubmissionController::class, 'store'])->name('form.store');
Route::get('/f/{survey:slug}/terima-kasih', [FormSubmissionController::class, 'thanks'])->name('form.thanks');
