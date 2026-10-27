<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin accounts: every admin has full access and may add other admins, change their names and
 * passwords, or remove them. The last admin and one's own account cannot be removed.
 */
class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admins.index', ['admins' => User::query()->orderBy('name')->orderBy('id')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('create', [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['nullable', 'required_without:email', 'regex:'.User::USERNAME_PATTERN, Rule::unique('users', 'username')],
            'email' => ['nullable', 'required_without:username', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], self::messages());

        $username = $data['username'] ?? null;
        User::query()->create([
            'name' => $data['name'],
            'username' => $username,
            'email' => $data['email'] ?? User::placeholderEmail((string) $username),
            'password' => $data['password'],
        ]);

        return redirect()->route('admins.index')->with('status', 'Admin '.($username ?? $data['email']).' ditambahkan.');
    }

    public function update(Request $request, User $admin): RedirectResponse
    {
        $data = $request->validateWithBag('edit'.$admin->id, [
            'name' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:8', 'max:100', 'confirmed'],
        ], self::messages());

        $admin->name = $data['name'];
        if (filled($data['password'] ?? null)) {
            $admin->password = $data['password'];
        }
        $admin->save();

        return redirect()->route('admins.index')->with('status', 'Akun '.$admin->loginName().' diperbarui.');
    }

    public function destroy(Request $request, User $admin): RedirectResponse
    {
        if ($request->user()?->is($admin)) {
            throw ValidationException::withMessages(['admin' => 'Akun yang sedang dipakai tidak dapat dihapus.']);
        }
        if (User::query()->count() <= 1) {
            throw ValidationException::withMessages(['admin' => 'Admin terakhir tidak dapat dihapus.']);
        }

        $admin->delete();

        return redirect()->route('admins.index')->with('status', 'Admin '.$admin->loginName().' dihapus.');
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'name.required' => 'Isi nama admin.',
            'username.required_without' => 'Isi username atau email (salah satu).',
            'email.required_without' => 'Isi username atau email (salah satu).',
            'username.regex' => 'Username 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.',
            'username.unique' => 'Username ini sudah dipakai.',
            'email.email' => 'Email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai.',
            'password.required' => 'Isi kata sandi.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Ulangan kata sandi tidak sama.',
        ];
    }
}
