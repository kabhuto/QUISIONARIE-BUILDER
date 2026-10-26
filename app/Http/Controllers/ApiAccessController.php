<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Survey;
use App\Support\Webhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The "Akses API" page: tokens for other systems, the webhook key, and the API's documentation.
 */
class ApiAccessController extends Controller
{
    public function index(): View
    {
        return view('api.index', [
            'tokens' => ApiToken::query()->latest()->get(),
            'webhookSecret' => Webhook::secret(),
            'exampleSurvey' => Survey::query()->latest('updated_at')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'can_write' => ['sometimes', 'boolean'],
        ]);

        [, $plain] = ApiToken::issue($data['name'], (bool) ($data['can_write'] ?? false));

        return redirect()->route('api-access.index')->with('newToken', $plain)->with('status', 'Token dibuat. Salin sekarang — token tidak akan ditampilkan lagi.');
    }

    public function destroy(ApiToken $token): RedirectResponse
    {
        $token->delete();

        return redirect()->route('api-access.index')->with('status', 'Token dicabut.');
    }
}
