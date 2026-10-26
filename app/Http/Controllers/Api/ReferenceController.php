<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferenceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reference lists over the API, e.g. to keep a master list of villages in sync.
 * A list is sent as {"name", "columns", "rows"} or {"name", "csv"}.
 */
class ReferenceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ReferenceList::query()->orderBy('name')->get()->map(fn (ReferenceList $list): array => self::summary($list))->values()]);
    }

    public function show(Request $request, ReferenceList $referenceList): JsonResponse
    {
        $rows = collect($referenceList->rows);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            $rows = $rows->filter(fn (array $row): bool => (string) ($row[$column] ?? '') === (string) $value);
        }

        return response()->json(['data' => self::summary($referenceList) + ['rows' => $rows->values()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:120']]);
        $list = ReferenceList::query()->create(['name' => $request->input('name')] + $this->table($request));

        return response()->json(['data' => self::summary($list)], 201);
    }

    public function update(Request $request, ReferenceList $referenceList): JsonResponse
    {
        $request->validate(['name' => ['sometimes', 'string', 'max:120']]);
        $referenceList->update(['name' => $request->input('name', $referenceList->name)] + $this->table($request));

        return response()->json(['data' => self::summary($referenceList)]);
    }

    public function destroy(ReferenceList $referenceList): JsonResponse
    {
        $referenceList->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, string>>}
     */
    private function table(Request $request): array
    {
        try {
            if ($request->filled('csv') || $request->hasFile('file')) {
                return ReferenceList::parseCsv($request->hasFile('file') ? (string) $request->file('file')->get() : (string) $request->input('csv'));
            }

            $columns = array_values(array_map('strval', (array) $request->input('columns', [])));
            $rows = array_map(
                fn ($row): array => array_combine($columns, array_map(fn (string $column): string => is_scalar($row[$column] ?? null) ? trim((string) $row[$column]) : '', $columns)),
                array_values(array_filter((array) $request->input('rows', []), 'is_array')),
            );
            ReferenceList::assertTable($columns, $rows);

            return ['columns' => $columns, 'rows' => $rows];
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rows' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(ReferenceList $list): array
    {
        return [
            'id' => $list->id,
            'slug' => $list->slug,
            'name' => $list->name,
            'columns' => $list->columns,
            'row_count' => count($list->rows),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }
}
