<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Support\SurveyJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * JSON export of a questionnaire (structure, validation rules, responses, logos) and its import.
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

    public function import(Request $request): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'extensions:json', 'max:20480']],
            ['file.required' => 'Pilih file JSON yang akan diimpor.', 'file.extensions' => 'File harus berformat .json.', 'file.max' => 'Ukuran file maksimal 20 MB.'],
        );

        $file = json_decode((string) $request->file('file')->get(), true);

        try {
            if (! is_array($file)) {
                throw new InvalidArgumentException('Isi file bukan JSON yang valid.');
            }
            SurveyJson::assertExportFile($file);
            $definition = SurveyJson::definition($file['survey']);
        } catch (InvalidArgumentException $failure) {
            return back()->withErrors(['file' => $failure->getMessage()]);
        } catch (ValidationException $failure) {
            return back()->withErrors(['file' => 'Kuesioner di dalam file tidak valid: '.collect($failure->errors())->flatten()->first()]);
        }

        SurveyJson::restoreLogos($file);
        $survey = Survey::query()->create($definition);

        return redirect()->route('surveys.edit', $survey)->with('status', 'Kuesioner berhasil diimpor dari JSON sebagai draf baru.');
    }
}
