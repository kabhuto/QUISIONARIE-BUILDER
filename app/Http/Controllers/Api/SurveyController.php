<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Support\FormGear;
use App\Support\Lookup;
use App\Support\SurveyJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Questionnaires over the API: list, read (in this app's format or FormGear's), create, replace,
 * publish. Definitions are checked exactly like the builder checks them.
 */
class SurveyController extends Controller
{
    public function about(): JsonResponse
    {
        return response()->json([
            'name' => 'Ruang Kuesioner API',
            'version' => 'v1',
            'documentation' => route('api-access.index'),
            'endpoints' => [
                'GET /api/v1/surveys' => 'Daftar kuesioner',
                'GET /api/v1/surveys/{id}?format=formgear' => 'Definisi kuesioner (format Ruang Kuesioner atau FormGear)',
                'POST /api/v1/surveys' => 'Buat kuesioner dari JSON (format Ruang Kuesioner atau template FormGear)',
                'PUT /api/v1/surveys/{id}' => 'Ganti definisi kuesioner',
                'POST|DELETE /api/v1/surveys/{id}/publish' => 'Terbitkan / tarik kuesioner',
                'POST /api/v1/surveys/{id}/validate' => 'Periksa jawaban tanpa menyimpan',
                'GET|POST /api/v1/surveys/{id}/responses' => 'Daftar / kirim respons',
                'POST /api/v1/surveys/{id}/responses/batch' => 'Kirim banyak respons sekaligus',
                'GET|POST /api/v1/surveys/{id}/assignments' => 'Daftar / buat penugasan berpredata',
                'GET /api/v1/surveys/{id}/lookup/{question}?parent=' => 'Opsi daftar pilihan dari referensi/API',
                'GET|POST /api/v1/references' => 'Data referensi untuk daftar pilihan',
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $surveys = Survey::query()->withCount('responses')->latest('updated_at')->get();

        return response()->json(['data' => $surveys->map(fn (Survey $survey): array => self::summary($survey))->values()]);
    }

    public function show(Request $request, Survey $survey): JsonResponse
    {
        if ($request->query('format') === 'formgear') {
            return response()->json(FormGear::export($survey));
        }

        return response()->json([
            'data' => self::summary($survey->loadCount('responses')) + [
                'numbers' => $survey->questionNumbers(),
                'definition' => SurveyJson::definitionData($survey),
                'validation_rules' => SurveyJson::rulesSummary($survey),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$definition, $notes] = $this->definitionFrom($request->all());
        $survey = Survey::query()->create([...$definition, 'published_at' => $request->boolean('publish') ? now() : null]);

        return response()->json(['data' => self::summary($survey->loadCount('responses')), 'notes' => $notes], 201);
    }

    public function update(Request $request, Survey $survey): JsonResponse
    {
        [$definition, $notes] = $this->definitionFrom($request->all());
        $survey->update($definition);

        return response()->json(['data' => self::summary($survey->loadCount('responses')), 'notes' => $notes]);
    }

    public function publish(Survey $survey): JsonResponse
    {
        $survey->update(['published_at' => $survey->published_at ?? now()]);

        return response()->json(['data' => self::summary($survey->loadCount('responses'))]);
    }

    public function unpublish(Survey $survey): JsonResponse
    {
        $survey->update(['published_at' => null]);

        return response()->json(['data' => self::summary($survey->loadCount('responses'))]);
    }

    /**
     * Options of a dropdown that takes them from a reference list or outside API.
     */
    public function lookup(Request $request, Survey $survey, string $question): JsonResponse
    {
        $found = collect($survey->questions())->firstWhere('id', $question);
        abort_unless($found && Lookup::isLookup($found), 404);

        $parent = ($found['lookup']['depends_on'] ?? '') === '' ? null : (string) $request->query('parent', '');
        $options = $parent === '' ? [] : Lookup::optionsFor($found, $parent);

        return response()->json(['data' => $options ?? [], 'available' => $options !== null]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Survey $survey): array
    {
        return [
            'id' => $survey->id,
            'slug' => $survey->slug,
            'title' => $survey->title,
            'form_code' => $survey->form_code,
            'activity' => $survey->activity,
            'published' => $survey->isPublished(),
            'published_at' => $survey->published_at?->toIso8601String(),
            'responses_count' => $survey->responses_count ?? $survey->responses()->count(),
            'updated_at' => $survey->updated_at?->toIso8601String(),
            'links' => [
                'form' => route('form.create', $survey),
                'self' => route('api.surveys.show', $survey),
                'responses' => route('api.responses.index', $survey),
            ],
        ];
    }

    /**
     * A builder-checked definition from a request body: this app's export file, its "survey" part,
     * the definition itself, or a FormGear template (converted, with notes on what was simplified).
     *
     * @param  array<string, mixed>  $body
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function definitionFrom(array $body): array
    {
        $notes = [];

        if (FormGear::isTemplate($body['template'] ?? $body)) {
            [$survey, $notes] = FormGear::import($body['template'] ?? $body, $body['validation'] ?? null, $body['preset'] ?? null);
        } else {
            $survey = is_array($body['survey'] ?? null) ? $body['survey'] : $body;
        }

        try {
            return [SurveyJson::definition($survey), $notes];
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['survey' => $exception->getMessage()]);
        }
    }
}
