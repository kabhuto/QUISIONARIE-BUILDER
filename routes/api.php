<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\ResponseController;
use App\Http\Controllers\Api\SurveyController;
use Illuminate\Support\Facades\Route;

// JSON API v1. Every request needs a token from the "Akses API" page; changes need a write token.
Route::prefix('v1')->middleware(['throttle:240,1', 'api.token'])->name('api.')->group(function () {
    Route::get('/', [SurveyController::class, 'about'])->name('about');

    Route::get('surveys', [SurveyController::class, 'index'])->name('surveys.index');
    Route::get('surveys/{survey}', [SurveyController::class, 'show'])->name('surveys.show');
    Route::post('surveys/{survey}/validate', [ResponseController::class, 'check'])->name('surveys.validate');
    Route::get('surveys/{survey}/responses', [ResponseController::class, 'index'])->name('responses.index');
    Route::get('surveys/{survey}/responses/{response}', [ResponseController::class, 'show'])->name('responses.show')->scopeBindings();
    Route::get('surveys/{survey}/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('surveys/{survey}/lookup/{question}', [SurveyController::class, 'lookup'])->name('surveys.lookup');
    Route::get('references', [ReferenceController::class, 'index'])->name('references.index');
    Route::get('references/{referenceList:slug}', [ReferenceController::class, 'show'])->name('references.show');

    Route::middleware('api.token:write')->group(function () {
        Route::post('surveys', [SurveyController::class, 'store'])->name('surveys.store');
        Route::put('surveys/{survey}', [SurveyController::class, 'update'])->name('surveys.update');
        Route::post('surveys/{survey}/publish', [SurveyController::class, 'publish'])->name('surveys.publish');
        Route::delete('surveys/{survey}/publish', [SurveyController::class, 'unpublish'])->name('surveys.unpublish');
        Route::post('surveys/{survey}/responses', [ResponseController::class, 'store'])->name('responses.store');
        Route::post('surveys/{survey}/responses/batch', [ResponseController::class, 'batch'])->name('responses.batch');
        Route::post('surveys/{survey}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
        Route::delete('surveys/{survey}/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy')->scopeBindings();
        Route::post('references', [ReferenceController::class, 'store'])->name('references.store');
        Route::put('references/{referenceList:slug}', [ReferenceController::class, 'update'])->name('references.update');
        Route::delete('references/{referenceList:slug}', [ReferenceController::class, 'destroy'])->name('references.destroy');
    });
});
