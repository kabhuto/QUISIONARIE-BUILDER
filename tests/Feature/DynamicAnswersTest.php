<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\Formula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DynamicAnswersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(string $id, string $type, string $label, array $attributes = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'required' => false, 'code_length' => 0, 'options' => [], ...$attributes];
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function makeSurvey(array $questions): Survey
    {
        return Survey::query()->create([
            'title' => 'Survei Dinamis',
            'form_code' => 'SD-01',
            'activity' => 'Uji coba',
            'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => [['id' => 'b-1', 'title' => 'BLOK', 'layout' => 'full', 'condition' => null, 'questions' => $questions]],
            'published_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function builderPayload(array $questions): array
    {
        return [
            'title' => 'Survei', 'form_code' => 'S-1', 'activity' => 'Pendataan',
            'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0'],
            'header' => json_encode(['items' => []]),
            'blocks' => json_encode([['id' => 'b-1', 'title' => 'BLOK', 'layout' => 'full', 'condition' => null, 'questions' => $questions]]),
        ];
    }

    public function test_formulas_support_arithmetic_functions_and_dates(): void
    {
        $answers = ['a' => '10', 'b' => '2,5', 'm' => ['x', 'y'], 'lahir' => '2000-10-27', 'kosong' => ''];

        $this->assertSame(15.0, Formula::evaluate('{{a}} + {{b}} * 2', $answers));
        $this->assertSame(3.33, Formula::evaluate('round({{a}} / 3, 2)', $answers));
        $this->assertSame(2.0, Formula::evaluate('count({{m}})', $answers));
        $this->assertSame(100.0, Formula::evaluate('if({{a}} >= 10, 100, 0)', $answers));
        $this->assertSame(25.0, Formula::evaluate('years({{lahir}}, "2026-10-26")', $answers));
        $this->assertSame(59.0, Formula::evaluate('days("2026-01-01", "2026-03-01")', $answers));
        $this->assertNull(Formula::evaluate('{{a}} / 0', $answers));
        $this->assertNull(Formula::evaluate('{{kosong}} + 1', $answers), 'All tags empty: no result yet.');
        $this->assertSame('berakhir sebelum selesai', Formula::problem('{{a}} +', ['a']));
        $this->assertSame('memakai fungsi "akar" yang tidak dikenal', Formula::problem('akar(4)', []));
        $this->assertSame('memakai tag jawaban dari pertanyaan yang tidak tersedia', Formula::problem('{{x}} * 2', ['a']));
    }

    public function test_calculated_answers_are_worked_out_on_the_server_and_feed_checks(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-makan', 'currency', 'Makanan'),
            $this->question('q-lain', 'currency', 'Bukan makanan'),
            $this->question('q-total', 'calc', 'Total', ['formula' => '{{q-makan}} + {{q-lain}}', 'format' => 'currency', 'decimals' => 0]),
            $this->question('q-pendapatan', 'currency', 'Pendapatan', ['validation' => ['rules' => [], 'message' => '', 'checks' => [[
                'id' => 'k-defisit', 'level' => 'warning', 'message' => 'Pengeluaran melebihi pendapatan.',
                'conditions' => ['operator' => 'and', 'rules' => [['question_id' => 'q-total', 'operator' => 'greater_than', 'value' => '{{q-pendapatan}}']]],
            ]]]]),
        ]);

        $this->post(route('form.store', $survey), ['answers' => ['q-makan' => '1.500.000', 'q-lain' => '700.000', 'q-total' => '1', 'q-pendapatan' => '2.000.000']])
            ->assertSessionHasErrors(['answers.q-pendapatan' => 'Peringatan: Pengeluaran melebihi pendapatan.']);

        $this->post(route('form.store', $survey), ['answers' => ['q-makan' => '1.500.000', 'q-lain' => '700.000', 'q-total' => '1', 'q-pendapatan' => '3.000.000']])
            ->assertRedirect(route('form.thanks', $survey));

        $response = SurveyResponse::query()->sole();
        $this->assertSame('2200000', $response->answers['q-total'], 'The submitted value is ignored; the server computes it.');
        $this->assertSame('Rp 2.200.000', $response->displayAnswer(collect($survey->questions())->firstWhere('id', 'q-total')));
    }

    public function test_question_texts_quote_earlier_answers(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-nama', 'short', 'Nama anggota rumah tangga'),
            $this->question('q-umur', 'number', 'Berapa umur {{q-nama|dia}}?'),
        ]);

        $this->get(route('form.create', $survey))->assertOk()
            ->assertSee('<span class="fill-pipe" data-pipe="q-nama" data-fallback="dia">dia</span>', false);

        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Budi', 'q-umur' => '30']]);
        $response = SurveyResponse::query()->sole();
        $this->get(route('surveys.responses.show', [$survey, $response]))->assertSee('Berapa umur Budi?');
        $this->get(route('surveys.print', $survey))->assertSee('Berapa umur [101]?');

        // A tag may only quote an earlier question.
        $this->post(route('surveys.store'), $this->builderPayload([
            $this->question('q-a', 'short', 'Halo {{q-b}}'),
            $this->question('q-b', 'short', 'Nama'),
        ]))->assertSessionHasErrors('blocks.0.questions.0.label');
    }

    public function test_other_option_needs_its_text_and_keeps_it(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-air', 'single', 'Sumber air', ['required' => true, 'options' => ['Leding', 'Sumur'], 'other_option' => true]),
        ]);

        $this->get(route('form.create', $survey))->assertSee('Lainnya (sebutkan)')->assertSee('name="answers[q-air__lainnya]"', false);
        $this->post(route('form.store', $survey), ['answers' => ['q-air' => 'Lainnya']])
            ->assertSessionHasErrors('answers.q-air');
        $this->post(route('form.store', $survey), ['answers' => ['q-air' => 'Lainnya', 'q-air__lainnya' => 'Air hujan']])
            ->assertRedirect(route('form.thanks', $survey));

        $response = SurveyResponse::query()->sole();
        $this->assertSame(['q-air' => 'Lainnya', 'q-air__lainnya' => 'Air hujan'], $response->answers);
        $this->assertSame('Lainnya: Air hujan', $response->displayAnswer($survey->questions()[0]));
    }

    public function test_options_can_come_from_an_earlier_multiple_choice_answer(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-hobi', 'multiple', 'Hobi', ['options' => ['Membaca', 'Olahraga', 'Memasak'], 'other_option' => true]),
            $this->question('q-favorit', 'single', 'Paling disukai', ['required' => true, 'options_from' => 'q-hobi']),
        ]);

        $this->post(route('form.store', $survey), ['answers' => ['q-hobi' => ['Membaca', 'Olahraga'], 'q-favorit' => 'Memasak']])
            ->assertSessionHasErrors('answers.q-favorit');
        $this->post(route('form.store', $survey), ['answers' => ['q-hobi' => ['Membaca', 'Lainnya'], 'q-hobi__lainnya' => 'Berkebun', 'q-favorit' => 'Berkebun']])
            ->assertRedirect(route('form.thanks', $survey));
        $this->assertSame('Berkebun', SurveyResponse::query()->sole()->answers['q-favorit']);

        // The source must be an earlier multiple-choice question.
        $this->post(route('surveys.store'), $this->builderPayload([
            $this->question('q-a', 'single', 'A', ['options' => ['Ya', 'Tidak']]),
            $this->question('q-b', 'dropdown', 'B', ['options_from' => 'q-a']),
        ]))->assertSessionHasErrors('blocks.0.questions.1.options_from');
    }

    public function test_masks_times_and_locations_are_checked(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-kode', 'short', 'Kode wilayah', ['mask' => '99.99.999']),
            $this->question('q-jam', 'time', 'Jam mulai'),
            $this->question('q-lokasi', 'location', 'Lokasi'),
        ]);

        $this->post(route('form.store', $survey), ['answers' => ['q-kode' => '3526010', 'q-jam' => '25:00', 'q-lokasi' => 'di sini']])
            ->assertSessionHasErrors(['answers.q-kode', 'answers.q-jam', 'answers.q-lokasi']);
        $this->post(route('form.store', $survey), ['answers' => ['q-kode' => '35.26.010', 'q-jam' => '08:30', 'q-lokasi' => '-7.0321,112.7464']])
            ->assertRedirect(route('form.thanks', $survey));

        $this->assertSame('-7.0321, 112.7464', SurveyResponse::query()->sole()->answers['q-lokasi']);
    }

    public function test_files_are_uploaded_into_the_survey_folder(): void
    {
        Storage::fake('public');
        $survey = $this->makeSurvey([
            $this->question('q-foto', 'file', 'Foto rumah', ['required' => true, 'file' => ['accept' => 'image', 'max_mb' => 1]]),
        ]);

        $this->post(route('form.store', $survey), ['answers' => ['q-foto' => UploadedFile::fake()->create('data.exe', 10)]])
            ->assertSessionHasErrors('answers.q-foto');
        $this->post(route('form.store', $survey), ['answers' => ['q-foto' => UploadedFile::fake()->image('rumah.jpg', 40, 40)->size(2048)]])
            ->assertSessionHasErrors('answers.q-foto');
        $this->post(route('form.store', $survey), ['answers' => ['q-foto' => UploadedFile::fake()->image('rumah.jpg', 40, 40)]])
            ->assertRedirect(route('form.thanks', $survey));

        $path = SurveyResponse::query()->sole()->answers['q-foto'];
        $this->assertTrue(Survey::isUploadPath($path));
        $this->assertStringStartsWith('uploads/'.$survey->id.'/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_builder_checks_formulas(): void
    {
        $this->post(route('surveys.store'), $this->builderPayload([
            $this->question('q-a', 'number', 'A'),
            $this->question('q-hasil', 'calc', 'Hasil', ['formula' => '{{q-a}} * (2']),
        ]))->assertSessionHasErrors('blocks.0.questions.1.formula');

        $this->post(route('surveys.store'), $this->builderPayload([
            $this->question('q-a', 'number', 'A'),
            $this->question('q-hasil', 'calc', 'Hasil', ['formula' => 'round({{q-a}} / 3, 1)', 'format' => 'percent', 'decimals' => 1]),
        ]))->assertSessionHasNoErrors();

        $saved = Survey::query()->sole()->blocks[0]['questions'][1];
        $this->assertSame(['round({{q-a}} / 3, 1)', 'percent', 1], [$saved['formula'], $saved['format'], $saved['decimals']]);
    }
}
