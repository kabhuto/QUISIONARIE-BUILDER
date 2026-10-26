<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\SurveyJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterTest extends TestCase
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
     * @param  list<array<string, mixed>>  $blocks
     */
    private function makeSurvey(array $blocks): Survey
    {
        return Survey::query()->create([
            'title' => 'Survei Rumah Tangga',
            'form_code' => 'RT-01',
            'activity' => 'Uji roster',
            'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => $blocks,
            'published_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $attributes
     */
    private function block(string $id, array $questions, array $attributes = []): array
    {
        return ['id' => $id, 'title' => 'BLOK '.$id, 'layout' => 'full', 'condition' => null, 'questions' => $questions, ...$attributes];
    }

    /**
     * A household: names listed first, then one row of questions per name, then a total.
     */
    private function householdSurvey(): Survey
    {
        return $this->makeSurvey([
            $this->block('b-1', [
                $this->question('q-art', 'list', 'Nama anggota rumah tangga', ['required' => true, 'list' => ['max' => 5]]),
            ]),
            $this->block('b-2', [
                $this->question('q-umur', 'number', 'Umur {{@baris}}', ['required' => true]),
                $this->question('q-sekolah', 'single', 'Masih sekolah?', ['required' => true, 'options' => ['Ya', 'Tidak'], 'hidden' => true,
                    'logic' => ['conditions' => ['operator' => 'and', 'rules' => [['question_id' => 'q-umur', 'operator' => 'less_than', 'value' => '25']]], 'actions' => ['show']]]),
                $this->question('q-gaji', 'currency', 'Pendapatan sebulan', ['validation' => ['rules' => [], 'message' => '', 'checks' => [[
                    'id' => 'k-anak', 'level' => 'warning', 'message' => 'Anak di bawah 10 tahun berpendapatan.',
                    'conditions' => ['operator' => 'and', 'rules' => [
                        ['question_id' => 'q-umur', 'operator' => 'less_than', 'value' => '10'],
                        ['question_id' => 'q-gaji', 'operator' => 'greater_than', 'value' => '0'],
                    ]],
                ]]]]),
                $this->question('q-gaji-tahun', 'calc', 'Pendapatan setahun', ['formula' => '{{q-gaji}} * 12', 'format' => 'currency']),
            ], ['repeat' => ['source' => 'q-art', 'max' => 5, 'print_rows' => 4]]),
            $this->block('b-3', [
                $this->question('q-total', 'calc', 'Total pendapatan rumah tangga', ['formula' => 'sum({{q-gaji}})', 'format' => 'currency']),
                $this->question('q-jumlah', 'calc', 'Jumlah ART', ['formula' => 'count({{q-art}})']),
            ]),
        ]);
    }

    public function test_a_block_repeats_for_each_listed_name_with_logic_checks_and_formulas_per_row(): void
    {
        $survey = $this->householdSurvey();

        // Row 1 (Budi, 40) needs no school answer; row 2 (Ani, 8) must answer it, and her income is a warning.
        $answers = [
            'q-art' => ['Budi', '', 'Ani'],
            'q-umur' => ['40', '8'],
            'q-gaji' => ['3.000.000', '50.000'],
        ];

        $this->post(route('form.store', $survey), ['answers' => $answers])
            ->assertSessionHasErrors(['answers.q-sekolah.1', 'answers.q-gaji.1'])
            ->assertSessionDoesntHaveErrors(['answers.q-sekolah.0', 'answers.q-gaji.0']);

        $this->post(route('form.store', $survey), ['answers' => $answers + ['q-sekolah' => [1 => 'Ya']], 'confirmations' => ['k-anak' => [1 => '1']]])
            ->assertRedirect(route('form.thanks', $survey));

        $response = SurveyResponse::query()->sole();
        $this->assertSame(['Budi', 'Ani'], $response->answers['q-art']);
        $this->assertSame(['40', '8'], $response->answers['q-umur']);
        $this->assertSame(['', 'Ya'], $response->answers['q-sekolah']);
        $this->assertSame(['3000000', '50000'], $response->answers['q-gaji']);
        $this->assertSame(['36000000', '600000'], $response->answers['q-gaji-tahun']);
        $this->assertSame('3050000', $response->answers['q-total']);
        $this->assertSame('2', $response->answers['q-jumlah']);
        $this->assertSame([['question_id' => 'q-gaji', 'check_id' => 'k-anak', 'message' => 'Anak di bawah 10 tahun berpendapatan.', 'row' => 1]], $response->flags['confirmed_warnings']);
        $this->assertSame('1) 40 | 2) 8', $response->displayAnswer(collect($survey->questions())->firstWhere('id', 'q-umur')));

        $this->get(route('surveys.responses.show', [$survey, $response]))->assertOk()
            ->assertSee('1. Budi')->assertSee('2. Ani')->assertSee('Rp 36.000.000')
            ->assertSee('Peringatan dikonfirmasi (rincian 2)');
        $this->get(route('surveys.responses.print', [$survey, $response]))->assertOk()
            ->assertSee('sheet-roster', false)->assertSee('Budi')->assertSee('Rp 600.000');
    }

    public function test_the_form_has_a_row_template_and_keeps_rows_after_a_failed_submission(): void
    {
        $survey = $this->householdSurvey();

        $this->get(route('form.create', $survey))->assertOk()
            ->assertSee('data-repeat-source="q-art"', false)
            ->assertSee('name="answers[q-umur][__R__]"', false)
            ->assertSee('name="confirmations[k-anak][__R__]"', false)
            ->assertSee('name="answers[q-art][0]"', false);

        $this->from(route('form.create', $survey))
            ->post(route('form.store', $survey), ['answers' => ['q-art' => ['Budi', 'Ani'], 'q-umur' => ['abc', '8']]])
            ->assertRedirect(route('form.create', $survey));

        $this->get(route('form.create', $survey))
            ->assertSee('data-row-key="Budi"', false)
            ->assertSee('name="answers[q-umur][1]" type="number" step="any" inputmode="decimal" value="8"', false);

        // The blank paper form prints the block as a listing with the configured number of lines.
        $html = $this->get(route('surveys.print', $survey))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="sheet-subtable sheet-roster"'));
        $this->assertStringContainsString('Diisi untuk setiap rincian pada pertanyaan 101', $html);
    }

    public function test_rows_can_follow_a_number_or_chosen_options(): void
    {
        $survey = $this->makeSurvey([
            $this->block('b-1', [
                $this->question('q-jumlah', 'number', 'Jumlah kendaraan'),
                $this->question('q-ternak', 'multiple', 'Ternak yang dipelihara', ['options' => ['Sapi', 'Kambing'], 'other_option' => true]),
            ]),
            $this->block('b-2', [$this->question('q-tahun', 'number', 'Tahun pembuatan', ['required' => true])], ['repeat' => ['source' => 'q-jumlah', 'max' => 3]]),
            $this->block('b-3', [$this->question('q-ekor', 'number', 'Jumlah ekor {{@baris}}', ['required' => true])], ['repeat' => ['source' => 'q-ternak', 'max' => 10]]),
        ]);

        // Five vehicles are capped at three rows; "Lainnya" stands for its typed text.
        $this->post(route('form.store', $survey), ['answers' => [
            'q-jumlah' => '5', 'q-ternak' => ['Sapi', 'Lainnya'], 'q-ternak__lainnya' => 'Itik',
            'q-tahun' => ['2010', '2015', '2020', '2024'], 'q-ekor' => ['3'],
        ]])->assertSessionHasErrors('answers.q-ekor.1');

        $this->post(route('form.store', $survey), ['answers' => [
            'q-jumlah' => '5', 'q-ternak' => ['Sapi', 'Lainnya'], 'q-ternak__lainnya' => 'Itik',
            'q-tahun' => ['2010', '2015', '2020', '2024'], 'q-ekor' => ['3', '12'],
        ]])->assertRedirect(route('form.thanks', $survey));

        $answers = SurveyResponse::query()->sole()->answers;
        $this->assertSame(['2010', '2015', '2020'], $answers['q-tahun']);
        $this->assertSame(['3', '12'], $answers['q-ekor']);
        $this->assertContains('diisi per rincian (daftar jawaban per baris)', SurveyJson::export($survey->fresh())['validation_rules'][3]['rules']);
    }

    public function test_the_builder_checks_repeating_blocks(): void
    {
        $payload = fn (array $blocks): array => [
            'title' => 'Survei', 'form_code' => 'S-1', 'activity' => 'Pendataan',
            'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0'],
            'header' => json_encode(['items' => []]),
            'blocks' => json_encode($blocks),
        ];
        $source = $this->block('b-1', [
            $this->question('q-art', 'list', 'Nama ART'),
            $this->question('q-nama', 'short', 'Nama responden'),
        ]);

        // Only numbers, multiple choices and lists can drive a repeating block.
        $this->post(route('surveys.store'), $payload([$source, $this->block('b-2', [$this->question('q-x', 'short', 'X')], ['repeat' => ['source' => 'q-nama']])]))
            ->assertSessionHasErrors('blocks.1.repeat');

        // Tables cannot sit inside a repeating block.
        $this->post(route('surveys.store'), $payload([$source, $this->block('b-2', [$this->question('q-t', 'table', 'Tabel', ['table' => ['mode' => 'dynamic', 'max_rows' => 3, 'initial_rows' => 1, 'columns' => [['id' => 'c-1', 'label' => 'A', 'type' => 'text']]]])], ['repeat' => ['source' => 'q-art']])]))
            ->assertSessionHasErrors('blocks.1.questions.0.type');

        // Rules outside the block cannot use its per-row answers, but formulas can total them.
        $roster = $this->block('b-2', [$this->question('q-umur', 'number', 'Umur')], ['repeat' => ['source' => 'q-art', 'max' => 10]]);
        $this->post(route('surveys.store'), $payload([$source, $roster, $this->block('b-3', [
            $this->question('q-catatan', 'short', 'Catatan', ['logic' => ['conditions' => ['operator' => 'and', 'rules' => [['question_id' => 'q-umur', 'operator' => 'answered', 'value' => '']]], 'actions' => ['require']]]),
        ])]))->assertSessionHasErrors('blocks.2.questions.0.logic');

        $this->post(route('surveys.store'), $payload([$source, $roster, $this->block('b-3', [
            $this->question('q-rata', 'calc', 'Rata-rata umur', ['formula' => 'round(avg({{q-umur}}), 1)', 'decimals' => 1]),
        ])]))->assertSessionHasNoErrors();

        $saved = Survey::query()->sole()->blocks[1];
        $this->assertSame(['source' => 'q-art', 'max' => 10, 'print_rows' => 3], $saved['repeat']);
    }
}
