<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\AssignmentController as ApiAssignments;
use App\Models\Assignment;
use App\Models\ReferenceList;
use App\Models\Survey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Assignments for one survey: private links with pre-filled answers, made from a CSV whose columns
 * are "label" and question numbers (101, 102, …) or question ids.
 */
class AssignmentController extends Controller
{
    public function index(Survey $survey): View
    {
        return view('assignments.index', [
            'survey' => $survey,
            'assignments' => $survey->assignments()->latest('id')->get()->each->setRelation('survey', $survey),
            'questions' => $survey->questions(),
        ]);
    }

    public function store(Request $request, Survey $survey): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']], ['file.required' => 'Pilih berkas CSV.']);

        try {
            $table = ReferenceList::parseCsv((string) $request->file('file')->get());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        // Columns may name questions by their printed number or by id.
        $byNumber = array_flip($survey->questionNumbers());
        $ids = array_column($survey->questions(), 'id');
        $columns = [];
        foreach ($table['columns'] as $column) {
            $key = strtolower(trim($column));
            if (in_array($key, ['label', 'nama', 'keterangan'], true)) {
                $columns[$column] = 'label';
            } elseif (isset($byNumber[$column]) || in_array($column, $ids, true)) {
                $columns[$column] = $byNumber[$column] ?? $column;
            } else {
                throw ValidationException::withMessages(['file' => "Kolom \"{$column}\" bukan nomor atau id pertanyaan di kuesioner ini."]);
            }
        }

        $items = array_map(function (array $row) use ($columns, $request): array {
            $predata = [];
            $label = null;
            foreach ($columns as $column => $target) {
                if ($target === 'label') {
                    $label = $row[$column] !== '' ? $row[$column] : null;
                } elseif ($row[$column] !== '') {
                    $predata[$target] = $row[$column];
                }
            }

            return ['label' => $label, 'predata' => $predata, 'locked' => $request->boolean('lock') ? array_keys($predata) : []];
        }, $table['rows']);

        $created = ApiAssignments::createMany($survey, $items);

        return redirect()->route('assignments.index', $survey)->with('status', count($created).' penugasan dibuat.');
    }

    public function destroy(Survey $survey, Assignment $assignment): RedirectResponse
    {
        $assignment->delete();

        return redirect()->route('assignments.index', $survey)->with('status', 'Penugasan dihapus.');
    }

    /**
     * The assignments' links as CSV, to hand out by mail, WhatsApp or a mail merge.
     */
    public function export(Survey $survey): StreamedResponse
    {
        $filename = str($survey->title)->slug()->append('-penugasan.csv')->toString();

        return response()->streamDownload(function () use ($survey) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'label', 'status', 'tautan', 'response_id'], escape: '');
            foreach ($survey->assignments()->oldest('id')->get() as $assignment) {
                $assignment->setRelation('survey', $survey);
                fputcsv($handle, [$assignment->id, $assignment->label, $assignment->status(), $assignment->url(), $assignment->response_id], escape: '');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
