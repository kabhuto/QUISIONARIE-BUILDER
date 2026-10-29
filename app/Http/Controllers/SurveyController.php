<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSurveyRequest;
use App\Models\ReferenceList;
use App\Models\Survey;
use App\Support\FieldValidation;
use App\Support\Formula;
use App\Support\Lookup;
use App\Support\SurveyCheck;
use App\Support\SurveyLogic;
use App\Support\SurveyTemplates;
use App\Support\SurveyVersions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SurveyController extends Controller
{
    public function index(): View
    {
        return view('surveys.index', [
            'surveys' => Survey::query()->withCount('responses')->latest('updated_at')->get(),
            'templates' => SurveyTemplates::all(),
        ]);
    }

    public function create(Request $request): View
    {
        $template = SurveyTemplates::exists($request->query('template')) ? $request->query('template') : SurveyTemplates::DEFAULT;
        $previous = Survey::query()->latest('id')->first();

        // Reuse the last kop and help contact, so logos are uploaded once per office.
        $survey = new Survey([
            'blocks' => SurveyTemplates::blocks($template),
            'header' => $previous?->header,
            'intro' => $previous ? array_intersect_key($previous->formIntro(), array_flip(['help_title', 'help_org', 'help_phone', 'help_email'])) : null,
        ]);

        return $this->builder($survey);
    }

    public function store(StoreSurveyRequest $request): RedirectResponse
    {
        $survey = Survey::query()->create($request->surveyData());

        return $this->afterSaving($request, $survey, 'Kuesioner berhasil dibuat.');
    }

    public function edit(Survey $survey): View
    {
        return $this->builder($survey);
    }

    public function update(StoreSurveyRequest $request, Survey $survey): RedirectResponse
    {
        $survey->update($request->surveyData());
        $message = 'Perubahan kuesioner tersimpan.';
        // Petugas may be filling it in right now: answers from here on belong to a new version.
        if ($survey->isPublished()) {
            $before = $survey->versions()->max('number');
            $version = SurveyVersions::record($survey);
            if ($version->number !== $before) {
                $message .= " Formulir sedang dipublikasikan, jadi tercatat sebagai versi {$version->number}.";
            }
        }

        return $this->afterSaving($request, $survey, $message);
    }

    public function destroy(Survey $survey): RedirectResponse
    {
        $survey->delete();

        return redirect()->route('surveys.index')->with('status', "Kuesioner \"{$survey->title}\" dihapus.");
    }

    private function afterSaving(StoreSurveyRequest $request, Survey $survey, string $message): RedirectResponse
    {
        return match ($request->input('intent')) {
            'preview' => redirect()->route('surveys.preview', $survey),
            'preview-phone' => redirect()->route('surveys.preview-phone', $survey),
            'print' => redirect()->route('surveys.print', $survey),
            default => redirect()->route('surveys.edit', $survey)->with('status', $message),
        };
    }

    private function builder(Survey $survey): View
    {
        return view('surveys.builder', [
            'survey' => $survey,
            'checks' => $survey->exists ? SurveyCheck::problems($survey) : [],
            'versionCount' => $survey->exists ? $survey->versions()->count() : 0,
            'settings' => $survey->formSettings(),
            'intro' => $survey->formIntro(),
            'activities' => Survey::query()->distinct()->orderBy('activity')->pluck('activity'),
            'questionTypes' => Survey::QUESTION_TYPES,
            'paperSizes' => Survey::PAPER_SIZES,
            'periodTypes' => Survey::PERIOD_TYPES,
            'themes' => Survey::THEMES,
            'numberingStyles' => Survey::NUMBERING_STYLES,
            'builderConfig' => [
                'questionTypes' => Survey::QUESTION_TYPES,
                'choiceTypes' => Survey::CHOICE_TYPES,
                'nonLogicTypes' => Survey::NON_LOGIC_TYPES,
                'noCodeTypes' => Survey::NO_CODE_TYPES,
                'rangeDefaults' => Survey::RANGE_DEFAULTS,
                'rangeLimit' => Survey::RANGE_LIMIT,
                'maxRangePoints' => Survey::MAX_RANGE_POINTS,
                'maxMatrixRows' => Survey::MAX_MATRIX_ROWS,
                'maxMatrixColumns' => Survey::MAX_MATRIX_COLUMNS,
                'maxCodeLength' => Survey::MAX_CODE_LENGTH,
                'paperSizes' => Survey::PAPER_SIZES,
                'periodTypes' => Survey::PERIOD_TYPES,
                'operators' => Survey::CONDITION_OPERATORS,
                'regionFields' => Survey::REGION_FIELDS,
                'tableColumnTypes' => Survey::TABLE_COLUMN_TYPES,
                'maxTableColumns' => Survey::MAX_TABLE_COLUMNS,
                'maxTableRows' => Survey::MAX_TABLE_ROWS,
                'valuelessOperators' => SurveyLogic::VALUELESS_OPERATORS,
                'numericOperators' => SurveyLogic::NUMERIC_OPERATORS,
                'rangeOperators' => SurveyLogic::RANGE_OPERATORS,
                'logicActions' => SurveyLogic::ACTIONS,
                'maxRules' => SurveyLogic::MAX_RULES,
                'fieldWidths' => Survey::FIELD_WIDTHS,
                'prefillTypes' => Survey::PREFILL_TYPES,
                'placeholderTypes' => Survey::PLACEHOLDER_TYPES,
                'unitTypes' => Survey::UNIT_TYPES,
                'nonresponseTypes' => Survey::NONRESPONSE_TYPES,
                'nonresponse' => Survey::NONRESPONSE,
                'validationRules' => FieldValidation::RULES,
                'checkLevels' => FieldValidation::CHECK_LEVELS,
                'maxChecks' => FieldValidation::MAX_CHECKS,
                'fileKinds' => Survey::FILE_KINDS,
                'maxFileMb' => Survey::MAX_FILE_MB,
                'maxLocationAccuracy' => Survey::MAX_LOCATION_ACCURACY,
                'formulaFormats' => Formula::FORMATS,
                'maxMaskLength' => Survey::MAX_MASK_LENGTH,
                'rosterSourceTypes' => Survey::ROSTER_SOURCE_TYPES,
                'rosterTypes' => Survey::ROSTER_TYPES,
                'maxRosterRows' => Survey::MAX_ROSTER_ROWS,
                'maxListItems' => Survey::MAX_LIST_ITEMS,
                'lookupSources' => Lookup::SOURCES,
                'referenceLists' => ReferenceList::query()->orderBy('name')->get(['name', 'slug', 'columns'])->map(fn (ReferenceList $list): array => ['slug' => $list->slug, 'name' => $list->name, 'columns' => $list->columns])->values(),
                'referencesUrl' => route('references.index'),
            ],
            'headerConfig' => [
                'items' => $survey->headerItems(),
                'align' => $survey->headerAlign(),
                'alignments' => Survey::HEADER_ALIGNMENTS,
                'slots' => Survey::HEADER_SLOTS,
                'itemTypes' => Survey::HEADER_ITEM_TYPES,
                'badgeStyles' => Survey::BADGE_STYLES,
                'logoIndexUrl' => route('logos.index'),
                'logoStoreUrl' => route('logos.store'),
                'storageUrl' => asset('storage'),
            ],
        ]);
    }

    /**
     * The versions of an instrument with what changed in each, and how many answers each holds.
     */
    public function versions(Survey $survey): View
    {
        // Published before versions were kept: what is live now is its first version.
        if ($survey->isPublished()) {
            SurveyVersions::record($survey);
        }
        $counts = $survey->responses()->selectRaw('survey_version, count(*) as total')->groupBy('survey_version')->pluck('total', 'survey_version');

        return view('surveys.versions', [
            'survey' => $survey,
            'versions' => $survey->versions()->orderByDesc('number')->get(),
            'counts' => $counts,
        ]);
    }
}
