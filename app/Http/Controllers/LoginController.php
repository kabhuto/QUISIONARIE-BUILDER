<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin sign-in with email and password; accounts are made with "php artisan admin:buat".
 */
class LoginController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        return $request->user() ? redirect()->route('surveys.index') : view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(
            ['email' => ['required', 'email'], 'password' => ['required', 'string']],
            ['email.required' => 'Isi email.', 'email.email' => 'Email tidak valid.', 'password.required' => 'Isi kata sandi.'],
        );

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('surveys.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Anda sudah keluar.');
    }
}
