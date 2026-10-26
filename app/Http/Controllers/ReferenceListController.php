<?php

namespace App\Http\Controllers;

use App\Models\ReferenceList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Reference lists (e.g. kode dan nama desa) that dropdowns can take their options from.
 */
class ReferenceListController extends Controller
{
    public function index(): View
    {
        return view('references.index', ['lists' => ReferenceList::query()->orderBy('name')->get()]);
    }

    public function show(ReferenceList $referenceList): View
    {
        return view('references.show', ['list' => $referenceList]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ], ['file.required' => 'Pilih berkas CSV.', 'file.mimes' => 'Berkas harus berformat CSV.']);

        try {
            $table = ReferenceList::parseCsv((string) $request->file('file')->get());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $list = ReferenceList::query()->create(['name' => $data['name']] + $table);

        return redirect()->route('references.show', $list)->with('status', 'Data referensi tersimpan: '.count($table['rows']).' baris.');
    }

    public function destroy(ReferenceList $referenceList): RedirectResponse
    {
        $referenceList->delete();

        return redirect()->route('references.index')->with('status', 'Data referensi dihapus.');
    }
}
