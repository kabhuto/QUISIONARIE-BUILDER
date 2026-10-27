<?php

namespace App\Http\Controllers;

use App\Support\Palette;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The admin's own colour for the admin pages.
 */
class AccentColorController extends Controller
{
    /**
     * The style sheet for one colour; it never changes, so browsers may keep it.
     */
    public function css(string $hex): Response
    {
        return response(Palette::accentCss('#'.$hex), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['color' => ['nullable', 'regex:'.Palette::HEX_PATTERN]], [
            'color.regex' => 'Pilih warna dalam format #RRGGBB.',
        ]);

        $user = $request->user();
        abort_unless($user, 403, 'Masuk sebagai admin untuk menyimpan warna.');

        $color = $validated['color'] ?? null;
        $user->update(['accent_color' => $color === null ? null : Palette::normalize($color)]);
        $accent = $user->accentColor();

        return response()->json([
            'color' => $accent ?? Palette::DEFAULT_ACCENT,
            'css' => $accent ? route('accent.css', ['hex' => ltrim($accent, '#'), 'v' => Palette::cssVersion()]) : null,
        ]);
    }
}
