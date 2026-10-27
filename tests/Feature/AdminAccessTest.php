<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function survey(): Survey
    {
        return Survey::query()->create([
            'title' => 'Absensi', 'form_code' => 'ABS', 'activity' => 'Uji coba', 'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => [['id' => 'b-1', 'title' => 'BLOK', 'layout' => 'full', 'condition' => null, 'questions' => [
                ['id' => 'q-nama', 'type' => 'short', 'label' => 'Nama', 'required' => false, 'code_length' => 0, 'options' => []],
            ]]],
            'published_at' => now(),
        ]);
    }

    public function test_on_a_development_pc_admin_pages_open_from_the_local_network(): void
    {
        $this->get(route('surveys.index'))->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->get(route('surveys.index'))->assertOk();
    }

    public function test_on_a_hosting_every_admin_page_needs_a_login_but_forms_stay_open(): void
    {
        config(['app.admin_open_on_lan' => false]);
        $survey = $this->survey();

        $this->get(route('surveys.index'))->assertRedirect(route('login'));
        $this->get(route('surveys.responses.index', $survey))->assertRedirect(route('login'));
        $this->get(route('form.create', $survey))->assertOk();

        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@bps.test', 'password' => 'rahasia-123']);
        $this->post(route('login.store'), ['login' => 'admin@bps.test', 'password' => 'salah'])->assertSessionHasErrors(['login' => 'Username/email atau kata sandi salah.']);
        $this->assertGuest();

        // After signing in the admin lands on the page they asked for.
        $this->post(route('login.store'), ['login' => 'admin@bps.test', 'password' => 'rahasia-123'])->assertRedirect(route('surveys.responses.index', $survey));
        $this->assertAuthenticatedAs($admin);
        $this->get(route('surveys.responses.index', $survey))->assertOk()->assertSee('KELUAR');

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('surveys.index'))->assertRedirect(route('login'));
    }

    public function test_visitors_through_the_https_tunnel_must_sign_in_for_admin_pages(): void
    {
        $survey = $this->survey();
        $tunnel = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '36.68.10.5', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'contoh.trycloudflare.com'];

        $this->withServerVariables($tunnel)->get(route('surveys.index'))->assertRedirect();
        $this->withServerVariables($tunnel)->get(route('surveys.responses.index', $survey))->assertRedirect();

        // The form links its styles and scripts over https, so the browser does not block them.
        $this->withServerVariables($tunnel)->get(route('form.create', $survey))
            ->assertOk()
            ->assertSee('https://contoh.trycloudflare.com/css/fill.css', false);

        // A visitor cannot pretend to be local by sending the headers without the trusted proxy.
        $this->withServerVariables(['REMOTE_ADDR' => '36.68.10.5', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'])->get(route('surveys.index'))->assertRedirect();
    }

    public function test_an_admin_account_is_made_from_the_command_line(): void
    {
        $this->artisan('admin:buat', ['akun' => 'humas@bps.test', '--name' => 'Humas'])
            ->expectsQuestion('Kata sandi (minimal 8 karakter)', 'kuesioner-2026')
            ->expectsQuestion('Ulangi kata sandi', 'kuesioner-2026')
            ->expectsOutput('Admin dibuat: humas@bps.test')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('kuesioner-2026', User::query()->sole()->password));

        $this->artisan('admin:buat', ['akun' => 'humas@bps.test'])
            ->expectsQuestion('Kata sandi (minimal 8 karakter)', 'pendek')
            ->assertExitCode(1);
    }

    public function test_an_admin_signs_in_with_a_username(): void
    {
        config(['app.admin_open_on_lan' => false]);

        $this->artisan('admin:buat', ['akun' => 'admin3526'])
            ->expectsQuestion('Kata sandi (minimal 8 karakter)', 'sandi-uji-123')
            ->expectsQuestion('Ulangi kata sandi', 'sandi-uji-123')
            ->expectsOutput('Admin dibuat: admin3526')
            ->assertExitCode(0);
        $this->artisan('admin:buat', ['akun' => 'admin 3526'])->assertExitCode(1);

        $this->get(route('login'))->assertOk()->assertSee('Username atau email');
        $this->post(route('login.store'), ['login' => 'admin3526', 'password' => 'salah'])->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->post(route('login.store'), ['login' => ' admin3526 ', 'password' => 'sandi-uji-123'])->assertRedirect(route('surveys.index'));
        $this->assertAuthenticatedAs(User::query()->where('username', 'admin3526')->sole());
        $this->get(route('surveys.index'))->assertOk();
    }
}
