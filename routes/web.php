<?php

use App\Http\Controllers\ApiAccessController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\DataValidationController;
use App\Http\Controllers\FormSubmissionController;
use App\Http\Controllers\GoogleDriveController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\PublishedSurveyController;
use App\Http\Controllers\ReferenceListController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\SurveyJsonController;
use App\Http\Controllers\SurveyPrintController;
use App\Http\Controllers\SurveyResponseController;
use Illuminate\Support\Facades\Route;

// Admin sign-in.
Route::get('/masuk', [LoginController::class, 'create'])->name('login');
Route::post('/masuk', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
Route::post('/keluar', [LoginController::class, 'destroy'])->name('logout');

// Admin: build, preview, publish and review surveys (signed in, or from the LAN when allowed).
Route::middleware('admin')->group(function (): void {
    Route::redirect('/', '/surveys');

    Route::post('/surveys/import', [SurveyJsonController::class, 'import'])->name('surveys.import');
    Route::get('/surveys/{survey}/export.json', [SurveyJsonController::class, 'export'])->name('surveys.export-json');
    Route::get('/surveys/{survey}/formgear.zip', [SurveyJsonController::class, 'exportFormGear'])->name('surveys.export-formgear');
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
    Route::get('/surveys/{survey}/penugasan', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::post('/surveys/{survey}/penugasan', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::get('/surveys/{survey}/penugasan/export', [AssignmentController::class, 'export'])->name('assignments.export');
    Route::delete('/surveys/{survey}/penugasan/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy')->scopeBindings();
    Route::get('/referensi', [ReferenceListController::class, 'index'])->name('references.index');
    Route::post('/referensi', [ReferenceListController::class, 'store'])->name('references.store');
    Route::get('/referensi/{referenceList:slug}', [ReferenceListController::class, 'show'])->name('references.show');
    Route::delete('/referensi/{referenceList:slug}', [ReferenceListController::class, 'destroy'])->name('references.destroy');
    Route::get('/akses-api', [ApiAccessController::class, 'index'])->name('api-access.index');
    Route::post('/akses-api', [ApiAccessController::class, 'store'])->name('api-access.store');
    Route::delete('/akses-api/{token}', [ApiAccessController::class, 'destroy'])->name('api-access.destroy');
    Route::get('/surveys/{survey}/google-drive', [GoogleDriveController::class, 'show'])->name('google-drive.show');
    Route::put('/surveys/{survey}/google-drive', [GoogleDriveController::class, 'update'])->name('google-drive.update');
    Route::post('/surveys/{survey}/google-drive/kirim', [GoogleDriveController::class, 'sendAll'])->name('google-drive.send-all');
    Route::post('/surveys/{survey}/responses/{response}/google-drive', [GoogleDriveController::class, 'send'])->name('google-drive.send')->scopeBindings();
});

// Respondents: fill in a published survey by its public link.
Route::get('/f/{survey:slug}', [FormSubmissionController::class, 'create'])->name('form.create');
Route::post('/f/{survey:slug}', [FormSubmissionController::class, 'store'])->name('form.store');
Route::get('/f/{survey:slug}/terima-kasih', [FormSubmissionController::class, 'thanks'])->name('form.thanks');
Route::get('/f/{survey:slug}/pilihan/{question}', [FormSubmissionController::class, 'lookup'])->middleware('throttle:120,1')->name('form.lookup');
