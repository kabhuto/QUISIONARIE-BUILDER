<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\GoogleDrive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Linking a survey to a Google Drive folder: the guide with the Apps Script to deploy, the web
 * app address, a connection test, and sending a response (again) by hand.
 */
class GoogleDriveController extends Controller
{
    public function show(Request $request, Survey $survey): View
    {
        $folderLink = (string) $request->query('folder', '');
        $folderId = $folderLink !== '' ? GoogleDrive::folderId($folderLink) : null;

        return view('google-drive.show', [
            'survey' => $survey,
            'folderLink' => $folderLink,
            'folderId' => $folderId,
            'script' => $folderId !== null ? GoogleDrive::script($folderId) : null,
            'url' => GoogleDrive::url($survey),
            'counts' => $survey->responses()->get(['drive'])->countBy(fn (SurveyResponse $response): string => $response->drive['status'] ?? 'none'),
        ]);
    }

    public function update(Request $request, Survey $survey): RedirectResponse
    {
        $validated = $request->validate(
            ['drive_url' => ['nullable', 'max:500', 'regex:'.GoogleDrive::WEB_APP_PATTERN]],
            ['drive_url.regex' => 'Tempel alamat Web App dari Apps Script: https://script.google.com/macros/s/…/exec.'],
        );
        $url = trim((string) ($validated['drive_url'] ?? ''));

        $survey->update(['settings' => ['drive_url' => $url] + $survey->formSettings()]);

        if ($url === '') {
            return back()->with('status', 'Sambungan Google Drive dilepas. Respons baru hanya disimpan di aplikasi.');
        }

        $ping = GoogleDrive::ping($url);

        return $ping['ok']
            ? back()->with('status', "Tersambung ke folder Google Drive \"{$ping['folder']}\". Respons baru akan disalin otomatis.")
            : back()->withErrors(['drive_url' => 'Alamat tersimpan, tetapi tes sambungan gagal: '.$ping['error']]);
    }

    /**
     * Sends one response now (e.g. after a failure, or one submitted before the folder was linked).
     */
    public function send(Survey $survey, SurveyResponse $response): RedirectResponse
    {
        abort_if(GoogleDrive::url($survey) === '', 422, 'Kuesioner ini belum disambungkan ke Google Drive.');

        return GoogleDrive::send($survey, $response)
            ? back()->with('status', 'Respons tersalin ke Google Drive.')
            : back()->withErrors(['drive' => 'Gagal menyalin ke Google Drive: '.($response->fresh()->drive['error'] ?? '')]);
    }

    /**
     * Sends every response not yet in the folder.
     */
    public function sendAll(Survey $survey): RedirectResponse
    {
        abort_if(GoogleDrive::url($survey) === '', 422, 'Kuesioner ini belum disambungkan ke Google Drive.');

        $sent = 0;
        $failed = 0;
        foreach ($survey->responses()->oldest()->lazy() as $response) {
            if (($response->drive['status'] ?? null) === 'sent') {
                continue;
            }
            GoogleDrive::send($survey, $response) ? $sent++ : $failed++;
        }

        return back()->with('status', "{$sent} respons tersalin ke Google Drive".($failed ? ", {$failed} gagal (lihat detail respons)." : '.'));
    }
}
