<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Support\FormGear;
use App\Support\SurveyJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * JSON export of a questionnaire (structure, validation rules, responses, logos) and its import,
 * plus the exchange with FormGear/FASIH (template, validation and preset files).
 */
class SurveyJsonController extends Controller
{
    public function export(Survey $survey): JsonResponse
    {
        $filename = str($survey->title)->slug()->append('-', now()->format('Ymd-His'), '.json')->toString();

        return response()
            ->json(SurveyJson::export($survey), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    /**
     * The questionnaire as FormGear files in a ZIP: template.json, validation.json, preset.json and notes.
     */
    public function exportFormGear(Survey $survey): BinaryFileResponse
    {
        $files = FormGear::export($survey);
        $path = tempnam(sys_get_temp_dir(), 'formgear');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $zip->addFromString('template.json', (string) json_encode($files['template'], $flags));
        $zip->addFromString('validation.json', (string) json_encode($files['validation'], $flags));
        $zip->addFromString('preset.json', (string) json_encode($files['preset'], $flags));
        $zip->addFromString('CATATAN.txt', "Ekspor FormGear dari PRISMA: {$survey->title}\n\n"
            .($files['notes'] === [] ? 'Semua isian, logika, dan validasi memiliki padanan di FormGear.' : "Yang perlu diperiksa:\n- ".implode("\n- ", array_unique($files['notes'])))."\n");
        $zip->close();

        $filename = str($survey->title)->slug()->append('-formgear.zip')->toString();

        return response()->download($path, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Imports this app's JSON export, or FormGear files (a template, optionally with its validation
     * and preset files, chosen together).
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(
            [
                'file' => ['required_without:files', 'file', 'extensions:json', 'max:20480'],
                'files' => ['required_without:file', 'array', 'max:3'],
                'files.*' => ['file', 'extensions:json', 'max:20480'],
            ],
            [
                'file.required_without' => 'Pilih file JSON yang akan diimpor.',
                'files.required_without' => 'Pilih file JSON yang akan diimpor.',
                'file.extensions' => 'File harus berformat .json.',
                'files.*.extensions' => 'File harus berformat .json.',
                'file.max' => 'Ukuran file maksimal 20 MB.',
            ],
        );

        /** @var list<UploadedFile> $uploads */
        $uploads = $request->hasFile('files') ? $request->file('files') : [$request->file('file')];
        $documents = array_map(fn (UploadedFile $upload): mixed => json_decode((string) $upload->get(), true), $uploads);
        $notes = [];

        try {
            if (in_array(null, $documents, true)) {
                throw new InvalidArgumentException('Isi file bukan JSON yang valid.');
            }

            $template = collect($documents)->first(fn (array $document): bool => FormGear::isTemplate($document));

            if ($template !== null) {
                [$survey, $notes] = FormGear::import(
                    $template,
                    collect($documents)->first(fn (array $document): bool => FormGear::isValidation($document)),
                    collect($documents)->first(fn (array $document): bool => FormGear::isPreset($document)),
                );
                $definition = SurveyJson::definition($survey);
                $file = null;
            } else {
                $file = $documents[0];
                SurveyJson::assertExportFile($file);
                $definition = SurveyJson::definition($file['survey']);
            }
        } catch (InvalidArgumentException $failure) {
            return back()->withErrors(['file' => $failure->getMessage()]);
        } catch (ValidationException $failure) {
            return back()->withErrors(['file' => 'Kuesioner di dalam file tidak valid: '.collect($failure->errors())->flatten()->first()]);
        }

        if ($file !== null) {
            SurveyJson::restoreLogos($file);
        }
        $survey = Survey::query()->create($definition);

        return redirect()->route('surveys.edit', $survey)
            ->with('status', $template !== null ? 'Template FormGear berhasil diimpor sebagai draf baru.' : 'Kuesioner berhasil diimpor dari JSON sebagai draf baru.')
            ->with('importNotes', array_values(array_unique($notes)));
    }
}
