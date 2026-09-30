<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Logos for questionnaire kop, shared by every survey so each office uploads them once.
 */
class LogoController extends Controller
{
    public function index(): JsonResponse
    {
        $logos = collect(Storage::disk('public')->files('logos'))
            ->filter(fn (string $path): bool => (bool) preg_match('/\.(png|jpe?g|webp)$/i', $path))
            ->sortByDesc(fn (string $path): int => Storage::disk('public')->lastModified($path))
            ->map(fn (string $path): array => ['path' => $path, 'url' => Storage::disk('public')->url($path)])
            ->values();

        return response()->json(['logos' => $logos]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(
            ['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048']],
            ['logo.mimes' => 'Logo harus berupa gambar PNG, JPG, atau WEBP.', 'logo.max' => 'Ukuran logo maksimal 2 MB.'],
        );

        $path = $request->file('logo')->store('logos', 'public');

        return response()->json(['path' => $path, 'url' => Storage::disk('public')->url($path)], 201);
    }
}
