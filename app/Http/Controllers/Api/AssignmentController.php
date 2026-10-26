<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Survey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Assignments: private links whose form opens with pre-filled answers (FormGear's predata),
 * optionally locked, sent once — e.g. one per sampled household.
 */
class AssignmentController extends Controller
{
    public function index(Request $request, Survey $survey): JsonResponse
    {
        $assignments = $survey->assignments()->oldest('id')->get();

        if ($status = $request->query('status')) {
            $assignments = $assignments->filter(fn (Assignment $assignment): bool => $assignment->status() === $status);
        }

        return response()->json(['data' => $assignments->map(fn (Assignment $assignment): array => $assignment->setRelation('survey', $survey)->toApi())->values()]);
    }

    /**
     * One assignment, or many at once with {"assignments": [...]} (up to 1000).
     */
    public function store(Request $request, Survey $survey): JsonResponse
    {
        $items = is_array($request->input('assignments')) ? $request->input('assignments') : [$request->all()];

        if (count($items) > 1000) {
            throw ValidationException::withMessages(['assignments' => 'Maksimal 1000 penugasan sekaligus.']);
        }

        $created = self::createMany($survey, $items);

        return response()->json(['data' => array_map(fn (Assignment $assignment): array => $assignment->toApi(), $created)], 201);
    }

    public function destroy(Survey $survey, Assignment $assignment): JsonResponse
    {
        $assignment->delete();

        return response()->json(null, 204);
    }

    /**
     * Checks then creates assignments; pre-filled answers may only name questions of this survey.
     *
     * @param  list<mixed>  $items
     * @return list<Assignment>
     */
    public static function createMany(Survey $survey, array $items): array
    {
        $questionIds = array_column($survey->questions(), 'id');
        $rows = [];

        foreach (array_values($items) as $index => $item) {
            $prefix = count($items) > 1 ? "assignments.{$index}." : '';
            $item = is_array($item) ? $item : [];
            $predata = $item['predata'] ?? $item['answers'] ?? [];
            $locked = $item['locked'] ?? [];

            $problem = match (true) {
                ! is_array($predata) => ['predata', 'Predata harus berupa objek {id_pertanyaan: jawaban}.'],
                array_diff(array_keys($predata), $questionIds) !== [] => ['predata', 'Predata memuat pertanyaan yang tidak ada: '.implode(', ', array_diff(array_keys($predata), $questionIds)).'.'],
                ! is_array($locked) || array_diff($locked, array_keys($predata)) !== [] => ['locked', 'Isian terkunci harus termasuk dalam predata.'],
                isset($item['label']) && (! is_string($item['label']) || mb_strlen($item['label']) > 255) => ['label', 'Label maksimal 255 karakter.'],
                isset($item['expires_at']) && strtotime((string) $item['expires_at']) === false => ['expires_at', 'Tanggal kedaluwarsa tidak valid.'],
                default => null,
            };

            if ($problem) {
                throw ValidationException::withMessages([$prefix.$problem[0] => $problem[1]]);
            }

            $rows[] = [
                'label' => $item['label'] ?? null,
                'predata' => $predata,
                'locked' => array_values($locked),
                'expires_at' => isset($item['expires_at']) ? date('Y-m-d H:i:s', strtotime((string) $item['expires_at'])) : null,
            ];
        }

        return array_map(fn (array $row): Assignment => $survey->assignments()->create($row)->setRelation('survey', $survey), $rows);
    }
}
