<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Links, checkboxes, ratings, scales, sliders, matrices and signatures.
 */
class QuestionTypesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A one-pixel PNG, as a signature pad would submit it.
     */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(string $id, string $type, string $label, array $attributes = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'required' => false, 'code_length' => 0, 'options' => [], ...$attributes];
    }

    /**
     * @return array<string, mixed>
     */
    private function logic(string $questionId, string $operator, string $value, array $actions): array
    {
        return ['conditions' => ['operator' => 'and', 'rules' => [['question_id' => $questionId, 'operator' => $operator, 'value' => $value]]], 'actions' => $actions];
    }

    private function makeSurvey(): Survey
    {
        return Survey::query()->create([
            'title' => 'Survei Kepuasan Layanan',
            'activity' => 'Evaluasi 2026',
            'settings' => Survey::DEFAULT_SETTINGS,
            'published_at' => now(),
            'blocks' => [[
                'id' => 'b-1', 'title' => 'PENILAIAN', 'layout' => 'full', 'condition' => null,
                'questions' => [
                    $this->question('q-puas', 'rating', 'Kepuasan', ['required' => true, 'range' => ['min' => 1, 'max' => 5, 'step' => 1]]),
                    $this->question('q-saran', 'long', 'Apa yang perlu diperbaiki?', ['logic' => $this->logic('q-puas', 'less_than', '3', ['require'])]),
                    $this->question('q-rekomendasi', 'scale', 'Kemungkinan merekomendasikan', ['range' => ['min' => 0, 'max' => 10, 'step' => 1]]),
                    $this->question('q-usia', 'slider', 'Usia', ['range' => ['min' => 10, 'max' => 90, 'step' => 5]]),
                    $this->question('q-situs', 'url', 'Situs instansi'),
                    $this->question('q-setuju', 'checkbox', 'Bersedia dihubungi kembali'),
                    $this->question('q-kontak', 'phone', 'Nomor yang dapat dihubungi', ['required' => true, 'hidden' => true, 'logic' => $this->logic('q-setuju', 'answered', '', ['show'])]),
                    $this->question('q-aspek', 'matrix', 'Penilaian aspek layanan', ['required' => true, 'matrix' => ['rows' => ['Kecepatan', 'Keramahan'], 'columns' => ['Kurang', 'Cukup', 'Baik']]]),
                    $this->question('q-ttd', 'signature', 'Tanda tangan responden', ['required' => true]),
                ],
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, mixed>>
     */
    private function answers(array $overrides = []): array
    {
        return ['answers' => array_merge([
            'q-puas' => '4',
            'q-rekomendasi' => '10',
            'q-usia' => '35',
            'q-situs' => 'https://bangkalankab.bps.go.id',
            'q-setuju' => 'Ya',
            'q-kontak' => '0812-3456-7890',
            'q-aspek' => ['Baik', 'Cukup'],
            'q-ttd' => self::SIGNATURE,
        ], $overrides)];
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function builderPayload(array $questions): array
    {
        return [
            'title' => 'Survei',
            'form_code' => 'S-1',
            'activity' => 'Pendataan',
            'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0'],
            'header' => json_encode(['items' => []]),
            'blocks' => json_encode([['id' => 'b-1', 'title' => 'PENILAIAN', 'layout' => 'full', 'condition' => null, 'questions' => $questions]]),
        ];
    }

    public function test_the_builder_offers_and_saves_the_types_with_their_settings(): void
    {
        $this->get(route('surveys.create'))
            ->assertOk()
            ->assertSee('data-tool="signature"', false)
            ->assertSee('data-tool="matrix"', false);

        $this->post(route('surveys.store'), $this->builderPayload([
            $this->question('q-rating', 'rating', 'Kepuasan', ['range' => ['min' => 0, 'max' => 7, 'step' => 2]]),
            $this->question('q-skala', 'scale', 'Rekomendasi', ['range' => ['min' => 0, 'max' => 10]]),
            $this->question('q-geser', 'slider', 'Usia'),
            $this->question('q-matriks', 'matrix', 'Aspek layanan', ['code_length' => 2, 'matrix' => ['rows' => [' Kecepatan ', '', 'Keramahan'], 'columns' => ['Kurang', 'Baik']]]),
            $this->question('q-ttd', 'signature', 'Tanda tangan', ['required' => true, 'code_length' => 3]),
            $this->question('q-situs', 'url', 'Situs', ['placeholder' => 'https://contoh.go.id', 'validation' => ['rules' => [['type' => 'contains', 'value' => '.go.id']], 'message' => '', 'checks' => []]]),
            $this->question('q-lanjut', 'short', 'Alasan', ['hidden' => true, 'logic' => $this->logic('q-rating', 'greater_than', '3', ['show'])]),
        ]))->assertSessionHasNoErrors();

        [$rating, $scale, $slider, $matrix, $signature, $url] = Survey::query()->sole()->blocks[0]['questions'];
        // Ratings always count whole stars from one.
        $this->assertSame(['min' => 1, 'max' => 7, 'step' => 1], $rating['range']);
        $this->assertSame(['min' => 0, 'max' => 10, 'step' => 1], $scale['range']);
        $this->assertSame(Survey::RANGE_DEFAULTS['slider'], $slider['range']);
        $this->assertSame(['rows' => ['Kecepatan', 'Keramahan'], 'columns' => ['Kurang', 'Baik']], $matrix['matrix']);
        $this->assertSame(0, $matrix['code_length']);
        $this->assertSame(0, $signature['code_length']);
        $this->assertTrue($signature['required']);
        $this->assertSame('https://contoh.go.id', $url['placeholder']);
        $this->assertSame([['type' => 'contains', 'value' => '.go.id']], $url['validation']['rules']);
    }

    public function test_ranges_and_matrices_need_a_usable_configuration(): void
    {
        $this->post(route('surveys.store'), $this->builderPayload([
            $this->question('q-a', 'scale', 'Terbalik', ['range' => ['min' => 5, 'max' => 1, 'step' => 1]]),
            $this->question('q-b', 'scale', 'Terlalu panjang', ['range' => ['min' => 0, 'max' => 100, 'step' => 1]]),
            $this->question('q-c', 'slider', 'Kelipatan besar', ['range' => ['min' => 0, 'max' => 10, 'step' => 20]]),
            $this->question('q-d', 'slider', 'Rentang panjang', ['range' => ['min' => 0, 'max' => 1000, 'step' => 1]]),
            $this->question('q-e', 'matrix', 'Satu kolom', ['matrix' => ['rows' => ['A'], 'columns' => ['Ya']]]),
            $this->question('q-f', 'matrix', 'Kembar', ['matrix' => ['rows' => ['A', 'A'], 'columns' => ['Ya', 'Tidak']]]),
        ]))
            ->assertSessionHasErrors(['blocks.0.questions.0.range', 'blocks.0.questions.1.range', 'blocks.0.questions.2.range', 'blocks.0.questions.4.matrix', 'blocks.0.questions.5.matrix'])
            ->assertSessionDoesntHaveErrors('blocks.0.questions.3.range');
    }

    public function test_matrices_cannot_drive_logic(): void
    {
        $this->post(route('surveys.store'), $this->builderPayload([
            $this->question('q-m', 'matrix', 'Aspek', ['matrix' => ['rows' => ['A'], 'columns' => ['Ya', 'Tidak']]]),
            $this->question('q-lanjut', 'short', 'Alasan', ['hidden' => true, 'logic' => $this->logic('q-m', 'answered', '', ['show'])]),
        ]))->assertSessionHasErrors('blocks.0.questions.1.logic');
    }

    public function test_the_form_renders_the_inputs(): void
    {
        $this->get(route('form.create', $this->makeSurvey()))
            ->assertOk()
            ->assertSee('data-signature', false)
            ->assertSee('data-slider-range', false)
            ->assertSee('cawi-range is-rating', false)
            ->assertSee('name="answers[q-aspek][1]"', false);
    }

    public function test_answers_are_stored(): void
    {
        $survey = $this->makeSurvey();

        $this->post(route('form.store', $survey), $this->answers())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('form.thanks', $survey));

        $answers = SurveyResponse::query()->sole()->answers;
        $this->assertSame('4', $answers['q-puas']);
        $this->assertSame('35', $answers['q-usia']);
        $this->assertSame('Ya', $answers['q-setuju']);
        $this->assertSame('0812-3456-7890', $answers['q-kontak']);
        $this->assertSame(['Baik', 'Cukup'], $answers['q-aspek']);
        $this->assertSame(self::SIGNATURE, $answers['q-ttd']);
    }

    public function test_answers_are_validated(): void
    {
        $survey = $this->makeSurvey();

        $this->post(route('form.store', $survey), $this->answers([
            'q-puas' => '6',
            'q-rekomendasi' => '2.5',
            'q-usia' => '37',
            'q-situs' => 'bps.go.id',
            'q-setuju' => 'Mungkin',
            'q-aspek' => ['Baik', 'Hebat'],
            'q-ttd' => 'data:image/png;base64,'.base64_encode('bukan gambar'),
        ]))->assertSessionHasErrors(['answers.q-puas', 'answers.q-rekomendasi', 'answers.q-usia', 'answers.q-situs', 'answers.q-setuju', 'answers.q-aspek.1', 'answers.q-ttd']);

        // A required matrix needs every row; a required signature needs a drawing.
        $this->post(route('form.store', $survey), $this->answers(['q-aspek' => ['Baik'], 'q-ttd' => '']))
            ->assertSessionHasErrors(['answers.q-aspek', 'answers.q-ttd']);

        $this->assertSame(0, SurveyResponse::query()->count());
    }

    public function test_ratings_and_checkboxes_drive_field_logic(): void
    {
        $survey = $this->makeSurvey();

        // A low rating makes the otherwise optional follow-up required.
        $this->post(route('form.store', $survey), $this->answers(['q-puas' => '2']))
            ->assertSessionHasErrors('answers.q-saran');

        // Ticked: the contact number is shown, and required.
        $this->post(route('form.store', $survey), $this->answers(['q-kontak' => '']))
            ->assertSessionHasErrors('answers.q-kontak');

        // Unticked: it stays hidden, so it is neither required nor stored.
        $this->post(route('form.store', $survey), $this->answers(['q-setuju' => '']))
            ->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('q-kontak', SurveyResponse::query()->sole()->answers);
    }

    public function test_signatures_and_matrices_render_in_detail_print_and_csv(): void
    {
        $survey = $this->makeSurvey();
        $this->post(route('form.store', $survey), $this->answers())->assertSessionHasNoErrors();
        $response = SurveyResponse::query()->sole();

        $this->get(route('surveys.responses.show', [$survey, $response]))
            ->assertOk()
            ->assertSee('<img class="detail-signature" src="'.self::SIGNATURE.'"', false)
            ->assertSee('Keramahan:');

        // Ratings, scales and sliders are summarised as numbers.
        $this->get(route('surveys.responses.index', $survey))->assertOk()->assertSee('Rata-rata');

        $this->get(route('surveys.print', $survey))->assertOk()->assertSee('sheet-signature', false);
        $this->get(route('surveys.responses.print', [$survey, $response]))
            ->assertOk()
            ->assertSee('<img src="'.self::SIGNATURE.'"', false)
            ->assertSee('sheet-mark is-chosen', false);

        $csv = $this->get(route('surveys.responses.export', $survey))->streamedContent();
        $this->assertStringContainsString('"Kecepatan: Baik; Keramahan: Cukup","[tanda tangan]"', $csv);
        // The image itself stays out of the spreadsheet.
        $this->assertStringNotContainsString('base64', $csv);
    }

    public function test_the_json_export_describes_and_round_trips_the_types(): void
    {
        $survey = $this->makeSurvey();
        $this->post(route('form.store', $survey), $this->answers())->assertSessionHasNoErrors();

        $json = $this->get(route('surveys.export-json', $survey))->assertOk()->json();
        $rules = collect($json['validation_rules'])->keyBy('id');

        $this->assertContains('bilangan bulat 10–90 kelipatan 5', $rules['q-usia']['rules']);
        $this->assertContains('gambar tanda tangan PNG (data URL)', $rules['q-ttd']['rules']);
        $this->assertSame(self::SIGNATURE, $json['responses'][0]['answers']['q-ttd']);
    }
}
