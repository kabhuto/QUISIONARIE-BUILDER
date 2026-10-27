<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin sign-in with a username or email and a password; accounts are made with "php artisan admin:buat".
 */
class LoginController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        return $request->user() ? redirect()->route('surveys.index') : view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']],
            ['login.required' => 'Isi username atau email.', 'password.required' => 'Isi kata sandi.'],
        );

        $login = trim($data['login']);
        $field = str_contains($login, '@') ? 'email' : 'username';
        if (! Auth::attempt([$field => $login, 'password' => $data['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['login' => 'Username/email atau kata sandi salah.']);
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
