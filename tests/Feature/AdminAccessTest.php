<?php

namespace Tests\Feature;

use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_admin_pages_open_from_this_computer_and_the_local_network(): void
    {
        $this->get(route('surveys.index'))->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->get(route('surveys.index'))->assertOk();
    }

    public function test_visitors_through_the_https_tunnel_reach_only_the_forms(): void
    {
        $survey = $this->survey();
        $tunnel = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '36.68.10.5', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'contoh.trycloudflare.com'];

        $this->withServerVariables($tunnel)->get(route('surveys.index'))->assertForbidden();
        $this->withServerVariables($tunnel)->get(route('surveys.responses.index', $survey))->assertForbidden();

        // The form links its styles and scripts over https, so the browser does not block them.
        $this->withServerVariables($tunnel)->get(route('form.create', $survey))
            ->assertOk()
            ->assertSee('https://contoh.trycloudflare.com/css/fill.css', false);

        // A visitor cannot pretend to be local by sending the headers without the trusted proxy.
        $this->withServerVariables(['REMOTE_ADDR' => '36.68.10.5', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'])->get(route('surveys.index'))->assertForbidden();
    }
}
