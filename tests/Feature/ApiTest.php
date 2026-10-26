<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Assignment;
use App\Models\ReferenceList;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\SurveyJson;
use App\Support\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(string $id, string $type, string $label, array $attributes = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'required' => false, 'code_length' => 0, 'options' => [], ...$attributes];
    }

    private function makeSurvey(array $settings = []): Survey
    {
        return Survey::query()->create([
            'title' => 'Survei API',
            'form_code' => 'API-01',
            'activity' => 'Uji API',
            'settings' => [...Survey::DEFAULT_SETTINGS, ...$settings],
            'blocks' => [['id' => 'b-1', 'title' => 'ART', 'layout' => 'full', 'condition' => null, 'questions' => [
                $this->question('q-nks', 'code', 'Nomor kode sampel', ['required' => true, 'code_length' => 4]),
                $this->question('q-nama', 'short', 'Nama', ['required' => true]),
                $this->question('q-jk', 'single', 'Jenis kelamin', ['options' => ['Laki-laki', 'Perempuan']]),
                $this->question('q-jam', 'number', 'Jam kerja', ['validation' => ['rules' => [], 'message' => '', 'checks' => [[
                    'id' => 'k-jam', 'level' => 'warning', 'message' => 'Jam kerja di atas 60.',
                    'conditions' => ['operator' => 'and', 'rules' => [['question_id' => 'q-jam', 'operator' => 'greater_than', 'value' => '60']]],
                ]]]]),
                $this->question('q-hari', 'calc', 'Jam per hari', ['formula' => 'round({{q-jam}} / 7, 1)', 'decimals' => 1]),
            ]]],
            'published_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $write = true): array
    {
        [, $plain] = ApiToken::issue('Uji', $write);

        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }

    public function test_requests_need_a_valid_token_and_writing_needs_a_write_token(): void
    {
        $survey = $this->makeSurvey();

        $this->getJson('/api/v1/surveys')->assertUnauthorized();
        $this->getJson('/api/v1/surveys', ['Authorization' => 'Bearer rk_salah'])->assertUnauthorized();

        $read = $this->headers(write: false);
        $this->getJson('/api/v1/surveys', $read)->assertOk()->assertJsonPath('data.0.title', 'Survei API');
        $this->postJson("/api/v1/surveys/{$survey->id}/responses", ['answers' => []], $read)->assertForbidden();
        $this->assertNotNull(ApiToken::query()->first()->last_used_at);
    }

    public function test_a_survey_definition_can_be_read_and_created(): void
    {
        $survey = $this->makeSurvey();
        $headers = $this->headers();

        $this->getJson("/api/v1/surveys/{$survey->id}", $headers)->assertOk()
            ->assertJsonPath('data.numbers.q-jam', '104')
            ->assertJsonPath('data.definition.blocks.0.questions.4.formula', 'round({{q-jam}} / 7, 1)');

        // An export file is accepted as is; the builder's rules still apply.
        $file = SurveyJson::export($survey);
        $this->postJson('/api/v1/surveys', $file + ['publish' => true], $headers)->assertCreated()
            ->assertJsonPath('data.title', 'Survei API')
            ->assertJsonPath('data.published', true);
        $this->assertSame(2, Survey::query()->count());

        $file['survey']['blocks'][0]['questions'][4]['formula'] = '{{q-jam}} / ';
        $this->postJson('/api/v1/surveys', $file, $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('blocks.0.questions.4.formula');
    }

    public function test_answers_can_be_checked_without_saving_then_sent(): void
    {
        $survey = $this->makeSurvey();
        $headers = $this->headers();
        $answers = ['q-nks' => '1201', 'q-nama' => 'Budi', 'q-jam' => '70'];

        $this->postJson("/api/v1/surveys/{$survey->id}/validate", ['answers' => ['q-nks' => '12']], $headers)->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonStructure(['errors' => ['answers.q-nks', 'answers.q-nama']]);

        $this->postJson("/api/v1/surveys/{$survey->id}/validate", ['answers' => $answers], $headers)->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('warnings.0.check_id', 'k-jam')
            ->assertJsonPath('computed.q-hari', '10.0');
        $this->assertSame(0, SurveyResponse::query()->count());

        $this->postJson("/api/v1/surveys/{$survey->id}/responses", ['answers' => $answers], $headers)->assertUnprocessable()
            ->assertJsonPath('warnings.0.message', 'Jam kerja di atas 60.');

        $this->postJson("/api/v1/surveys/{$survey->id}/responses", ['answers' => $answers, 'confirmations' => ['k-jam' => 1], 'submitted_at' => '2026-10-20T08:00:00+07:00'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.source', 'api')
            ->assertJsonPath('data.answers.q-hari', '10.0')
            ->assertJsonPath('data.flags.confirmed_warnings.0.check_id', 'k-jam');

        $this->assertSame('2026-10-20', SurveyResponse::query()->sole()->created_at->toDateString());
        $this->getJson("/api/v1/surveys/{$survey->id}/responses?since=2026-10-01", $headers)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.answers.q-nama', 'Budi');
    }

    public function test_many_responses_can_be_sent_at_once(): void
    {
        $survey = $this->makeSurvey();

        $this->postJson("/api/v1/surveys/{$survey->id}/responses/batch", ['responses' => [
            ['external_id' => 'hp-1', 'answers' => ['q-nks' => '1201', 'q-nama' => 'Budi']],
            ['external_id' => 'hp-2', 'answers' => ['q-nks' => 'xx']],
        ]], $this->headers())->assertCreated()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('rejected', 1)
            ->assertJsonPath('results.0.external_id', 'hp-1')
            ->assertJsonPath('results.1.status', 'rejected');

        $this->assertSame(1, $survey->responses()->count());
    }

    public function test_assignments_open_with_predata_lock_answers_and_send_once(): void
    {
        $survey = $this->makeSurvey();
        $headers = $this->headers();

        $this->postJson("/api/v1/surveys/{$survey->id}/assignments", ['predata' => ['q-tidak-ada' => 'x']], $headers)
            ->assertUnprocessable();

        $url = $this->postJson("/api/v1/surveys/{$survey->id}/assignments", ['assignments' => [
            ['label' => 'Ruta 001', 'predata' => ['q-nks' => '1201', 'q-nama' => 'Budi'], 'locked' => ['q-nks']],
        ]], $headers)->assertCreated()->assertJsonPath('data.0.status', 'open')->json('data.0.url');

        $assignment = Assignment::query()->sole();
        $this->get($url)->assertOk()
            ->assertSee('name="a" value="'.$assignment->token.'"', false)
            ->assertSee('value="Budi"', false)
            ->assertSee('data-static-disabled', false);

        // The locked sample number stays 1201 whatever is sent.
        $this->post(route('form.store', $survey), ['a' => $assignment->token, 'answers' => ['q-nks' => '9999', 'q-nama' => 'Budi Santoso']])
            ->assertRedirect(route('form.thanks', $survey));

        $response = SurveyResponse::query()->sole();
        $this->assertSame(['q-nks' => '1201', 'q-nama' => 'Budi Santoso'], $response->answers);
        $this->assertSame($response->id, $assignment->fresh()->response_id);

        $this->post(route('form.store', $survey), ['a' => $assignment->token, 'answers' => ['q-nama' => 'Lagi']])->assertSessionHasErrors('a');
        $this->get($url)->assertSee('Penugasan ini sudah dikirim');
        $this->getJson("/api/v1/surveys/{$survey->id}/assignments?status=submitted", $headers)->assertJsonPath('data.0.response_id', $response->id);
    }

    public function test_new_responses_are_sent_to_the_webhook_with_a_signature(): void
    {
        Http::fake();
        $survey = $this->makeSurvey(['webhook_url' => 'https://contoh.test/terima']);

        $this->post(route('form.store', $survey), ['answers' => ['q-nks' => '1201', 'q-nama' => 'Siti']]);

        Http::assertSent(function (HttpRequest $request): bool {
            $body = $request->body();

            return $request->url() === 'https://contoh.test/terima'
                && $request->header('X-Ruang-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, Webhook::secret())
                && $request['response']['answers']['q-nama'] === 'Siti'
                && $request['event'] === 'response.created';
        });
    }

    public function test_dropdowns_can_cascade_from_reference_lists(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/v1/references', [
            'name' => 'Desa Bangkalan',
            'csv' => "kode_kec;nama_kec;kode_desa;nama_desa\n010;KAMAL;3526010001;BANYUAJUH\n010;KAMAL;3526010002;TAJUNGAN\n020;LABANG;3526020001;SUKOLILO BARAT",
        ], $headers)->assertCreated()->assertJsonPath('data.row_count', 3);

        $reference = ['source' => 'reference', 'reference' => 'desa-bangkalan'];
        $survey = Survey::query()->create([
            'title' => 'Wilayah', 'activity' => 'Uji', 'settings' => Survey::DEFAULT_SETTINGS, 'published_at' => now(),
            'blocks' => [['id' => 'b-1', 'title' => 'WILAYAH', 'layout' => 'full', 'condition' => null, 'questions' => [
                $this->question('q-kec', 'dropdown', 'Kecamatan', ['required' => true, 'lookup' => $reference + ['value_column' => 'kode_kec', 'label_column' => 'nama_kec']]),
                $this->question('q-desa', 'dropdown', 'Desa', ['required' => true, 'lookup' => $reference + ['value_column' => 'kode_desa', 'label_column' => 'nama_desa', 'filter_column' => 'kode_kec', 'depends_on' => 'q-kec']]),
            ]]],
        ]);

        $this->getJson(route('form.lookup', [$survey, 'q-kec']))->assertJsonCount(2, 'data')->assertJsonPath('data.1.label', 'LABANG');
        $this->getJson(route('form.lookup', [$survey, 'q-desa']).'?parent=010')->assertJsonCount(2, 'data')->assertJsonPath('data.0.value', '3526010001');
        $this->getJson("/api/v1/surveys/{$survey->id}/lookup/q-desa?parent=020", $headers)->assertJsonPath('data.0.label', 'SUKOLILO BARAT');

        // A village of another district is refused; the chosen names are kept with the codes.
        $this->post(route('form.store', $survey), ['answers' => ['q-kec' => '010', 'q-desa' => '3526020001']])->assertSessionHasErrors('answers.q-desa');
        $this->post(route('form.store', $survey), ['answers' => ['q-kec' => '010', 'q-desa' => '3526010002']])->assertRedirect(route('form.thanks', $survey));

        $response = SurveyResponse::query()->sole();
        $this->assertSame(['q-kec' => '010', 'q-kec__label' => 'KAMAL', 'q-desa' => '3526010002', 'q-desa__label' => 'TAJUNGAN'], $response->answers);
        $this->assertSame('TAJUNGAN [3526010002]', $response->displayAnswer($survey->questions()[1]));

        $this->assertSame(1, ReferenceList::query()->count());
    }

    public function test_dropdowns_can_take_options_from_an_outside_api(): void
    {
        Http::fake(['contoh.test/*' => Http::response(['data' => [['id' => 7, 'nama' => 'Koperasi Maju'], ['id' => 9, 'nama' => 'Koperasi Jaya']]])]);
        $survey = Survey::query()->create([
            'title' => 'API luar', 'activity' => 'Uji', 'settings' => Survey::DEFAULT_SETTINGS, 'published_at' => now(),
            'blocks' => [['id' => 'b-1', 'title' => 'USAHA', 'layout' => 'full', 'condition' => null, 'questions' => [
                $this->question('q-kop', 'dropdown', 'Koperasi', ['lookup' => ['source' => 'api', 'url' => 'https://contoh.test/koperasi', 'data_path' => 'data', 'value_key' => 'id', 'label_key' => 'nama']]),
            ]]],
        ]);

        $this->getJson(route('form.lookup', [$survey, 'q-kop']))->assertJsonPath('data.1', ['value' => '9', 'label' => 'Koperasi Jaya']);
        $this->post(route('form.store', $survey), ['answers' => ['q-kop' => '8']])->assertSessionHasErrors('answers.q-kop');
        $this->post(route('form.store', $survey), ['answers' => ['q-kop' => '9']])->assertRedirect(route('form.thanks', $survey));
        $this->assertSame('Koperasi Jaya', SurveyResponse::query()->sole()->answers['q-kop__label']);
    }

    public function test_tokens_are_managed_on_the_api_page(): void
    {
        $this->post(route('api-access.store'), ['name' => 'Aplikasi CAPI', 'can_write' => '1'])
            ->assertRedirect(route('api-access.index'))
            ->assertSessionHas('newToken');

        $token = ApiToken::query()->sole();
        $this->assertTrue($token->can_write);
        $this->assertSame(hash('sha256', session('newToken')), $token->token_hash);

        $this->get(route('api-access.index'))->assertOk()->assertSee('Aplikasi CAPI')->assertSee('X-Ruang-Signature');
        $this->delete(route('api-access.destroy', $token))->assertRedirect();
        $this->assertSame(0, ApiToken::query()->count());
    }
}
