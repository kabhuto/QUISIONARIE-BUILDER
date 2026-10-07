<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\SurveyJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldSettingsTest extends TestCase
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
            'title' => 'Survei Kesehatan',
            'form_code' => 'SK-01',
            'activity' => 'Pendataan 2026',
            'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => [['id' => 'b-1', 'title' => 'KETERANGAN ART', 'layout' => 'full', 'condition' => null, 'questions' => $questions]],
            'published_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $logic
     */
    private function logic(string $questionId, string $operator, string $value, array $actions, string $join = 'and'): array
    {
        return ['conditions' => ['operator' => $join, 'rules' => [['question_id' => $questionId, 'operator' => $operator, 'value' => $value]]], 'actions' => $actions];
    }

    private function pregnancySurvey(string $level): Survey
    {
        return $this->makeSurvey([
            $this->question('q-jk', 'single', 'Jenis kelamin', ['required' => true, 'options' => ['Laki-laki', 'Perempuan']]),
            $this->question('q-hamil', 'single', 'Sedang hamil?', ['required' => true, 'options' => ['Ya', 'Tidak'], 'validation' => [
                'rules' => [],
                'message' => '',
                'checks' => [[
                    'id' => 'k-hamil',
                    'level' => $level,
                    'message' => 'Apakah benar responden laki-laki sedang hamil?',
                    'conditions' => ['operator' => 'and', 'rules' => [
                        ['question_id' => 'q-jk', 'operator' => 'equals', 'value' => 'Laki-laki'],
                        ['question_id' => 'q-hamil', 'operator' => 'equals', 'value' => 'Ya'],
                    ]],
                ]],
            ]]),
        ]);
    }

    public function test_an_error_check_blocks_an_inconsistent_answer(): void
    {
        $survey = $this->pregnancySurvey('error');

        $this->post(route('form.store', $survey), ['answers' => ['q-jk' => 'Laki-laki', 'q-hamil' => 'Ya'], 'confirmations' => ['k-hamil' => '1']])
            ->assertSessionHasErrors(['answers.q-hamil' => 'Apakah benar responden laki-laki sedang hamil?']);

        $this->post(route('form.store', $survey), ['answers' => ['q-jk' => 'Perempuan', 'q-hamil' => 'Ya']])
            ->assertRedirect(route('form.thanks', $survey));
        $this->assertSame(1, SurveyResponse::query()->count());
    }

    public function test_a_warning_check_must_be_confirmed_and_is_kept_with_the_response(): void
    {
        $survey = $this->pregnancySurvey('warning');
        $answers = ['q-jk' => 'Laki-laki', 'q-hamil' => 'Ya'];

        $this->post(route('form.store', $survey), ['answers' => $answers])
            ->assertSessionHasErrors(['answers.q-hamil' => 'Peringatan: Apakah benar responden laki-laki sedang hamil?']);

        $this->post(route('form.store', $survey), ['answers' => $answers, 'confirmations' => ['k-hamil' => '1']])
            ->assertRedirect(route('form.thanks', $survey));

        $response = SurveyResponse::query()->sole();
        $this->assertSame('Laki-laki', $response->answers['q-jk']);
        $this->assertSame([['question_id' => 'q-hamil', 'check_id' => 'k-hamil', 'message' => 'Apakah benar responden laki-laki sedang hamil?']], $response->flags['confirmed_warnings']);

        $this->get(route('surveys.responses.show', [$survey, $response]))
            ->assertOk()
            ->assertSee('Peringatan dikonfirmasi: Apakah benar responden laki-laki sedang hamil?');
    }

    public function test_the_form_carries_checks_for_the_browser(): void
    {
        $this->get(route('form.create', $this->pregnancySurvey('warning')))
            ->assertOk()
            ->assertSee('data-check-id="k-hamil"', false)
            ->assertSee('name="confirmations[k-hamil]"', false);
    }

    public function test_logic_can_require_disable_and_show_fields(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-kerja', 'single', 'Bekerja?', ['options' => ['Ya', 'Tidak']]),
            $this->question('q-jam', 'number', 'Jam kerja', ['logic' => $this->logic('q-kerja', 'equals', 'Ya', ['require'])]),
            $this->question('q-status', 'short', 'Status', ['prefill' => 'Tidak bekerja', 'logic' => $this->logic('q-kerja', 'equals', 'Tidak', ['disable'])]),
            $this->question('q-usaha', 'short', 'Nama usaha', ['hidden' => true, 'required' => true, 'logic' => $this->logic('q-kerja', 'equals', 'Ya', ['show'])]),
        ]);

        // "Ya": hours become required and the hidden, required business name appears.
        $this->post(route('form.store', $survey), ['answers' => ['q-kerja' => 'Ya']])
            ->assertSessionHasErrors(['answers.q-jam', 'answers.q-usaha']);

        // "Tidak": the status is locked to its pre-filled value, whatever is sent.
        $this->post(route('form.store', $survey), ['answers' => ['q-kerja' => 'Tidak', 'q-status' => 'Diubah', 'q-usaha' => 'Abaikan']])
            ->assertRedirect(route('form.thanks', $survey));

        $answers = SurveyResponse::query()->sole()->answers;
        $this->assertSame('Tidak bekerja', $answers['q-status']);
        $this->assertArrayNotHasKey('q-jam', array_filter($answers));
        $this->assertNull($answers['q-usaha'] ?? null);
    }

    public function test_rule_groups_combine_with_and_or(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-umur', 'number', 'Umur'),
            $this->question('q-sekolah', 'single', 'Masih sekolah?', ['options' => ['Ya', 'Tidak']]),
            $this->question('q-alasan', 'short', 'Alasan', ['logic' => [
                'conditions' => ['operator' => 'and', 'rules' => [
                    ['question_id' => 'q-sekolah', 'operator' => 'equals', 'value' => 'Tidak'],
                    ['operator' => 'or', 'rules' => [
                        ['question_id' => 'q-umur', 'operator' => 'less_than', 'value' => '7'],
                        ['question_id' => 'q-umur', 'operator' => 'less_than', 'value' => '19'],
                    ]],
                ]],
                'actions' => ['require'],
            ]]),
        ]);

        $this->post(route('form.store', $survey), ['answers' => ['q-umur' => '15', 'q-sekolah' => 'Tidak']])
            ->assertSessionHasErrors('answers.q-alasan');
        $this->post(route('form.store', $survey), ['answers' => ['q-umur' => '30', 'q-sekolah' => 'Tidak']])
            ->assertRedirect(route('form.thanks', $survey));
    }

    public function test_answer_rules_and_character_limits_are_enforced(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-hp', 'phone', 'Nomor HP', ['validation' => ['rules' => [['type' => 'starts_with', 'value' => '08']], 'message' => 'Nomor HP harus diawali 08.', 'checks' => []]]),
            $this->question('q-umur', 'number', 'Umur', ['validation' => ['rules' => [['type' => 'max_value', 'value' => '120']], 'message' => '', 'checks' => []]]),
            $this->question('q-nama', 'short', 'Nama', ['max_length' => 5]),
        ]);

        $this->post(route('form.store', $survey), ['answers' => ['q-hp' => '6281234567', 'q-umur' => '150', 'q-nama' => 'Bambang']])
            ->assertSessionHasErrors([
                'answers.q-hp' => 'Nomor HP harus diawali 08.',
                'answers.q-umur' => 'Nilai maksimal 120.',
                'answers.q-nama',
            ]);
        $this->post(route('form.store', $survey), ['answers' => ['q-hp' => '081234567', 'q-umur' => '40', 'q-nama' => 'Budi']])
            ->assertRedirect(route('form.thanks', $survey));
    }

    public function test_the_builder_saves_field_settings_and_rejects_broken_checks(): void
    {
        $blocks = [['id' => 'b-1', 'title' => 'ART', 'layout' => 'full', 'condition' => null, 'questions' => [
            $this->question('q-jk', 'single', 'Jenis kelamin', ['options' => ['Laki-laki', 'Perempuan']]),
            $this->question('q-hamil', 'single', 'Sedang hamil?', [
                'options' => ['Ya', 'Tidak'],
                'width' => '1/2',
                'placeholder' => 'Pilih',
                'help_position' => 'above',
                'hidden' => true,
                'logic' => $this->logic('q-jk', 'equals', 'Perempuan', ['show']),
                'validation' => ['rules' => [], 'message' => '', 'checks' => [[
                    'id' => 'k-1', 'level' => 'warning', 'message' => 'Laki-laki hamil?',
                    'conditions' => ['operator' => 'and', 'rules' => [['question_id' => 'q-hamil', 'operator' => 'equals', 'value' => 'Ya']]],
                ]]],
            ]),
        ]]];
        $payload = ['title' => 'Survei', 'form_code' => 'S-1', 'activity' => 'Pendataan', 'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0'], 'header' => json_encode(['items' => []])];

        $this->post(route('surveys.store'), [...$payload, 'blocks' => json_encode($blocks)])->assertSessionHasNoErrors();
        $saved = Survey::query()->sole()->blocks[0]['questions'][1];
        $this->assertSame('1/2', $saved['width']);
        $this->assertTrue($saved['hidden']);
        $this->assertSame(['show'], $saved['logic']['actions']);
        $this->assertSame('warning', $saved['validation']['checks'][0]['level']);

        // "Hide" makes no sense on a field that is already hidden, and checks need a message.
        $blocks[0]['questions'][1]['logic']['actions'] = ['hide'];
        $blocks[0]['questions'][1]['validation']['checks'][0]['message'] = '';
        $this->post(route('surveys.store'), [...$payload, 'blocks' => json_encode($blocks)])
            ->assertSessionHasErrors(['blocks.0.questions.1.logic', 'blocks.0.questions.1.validation']);
    }

    public function test_the_json_summary_describes_logic_and_checks(): void
    {
        $survey = $this->pregnancySurvey('warning');
        $rules = SurveyJson::export($survey)['validation_rules'][1];

        $this->assertContains('peringatan jika 101 sama dengan Laki-laki dan 102 sama dengan Ya: Apakah benar responden laki-laki sedang hamil?', $rules['rules']);
    }

    public function test_a_rule_value_can_be_a_tag_for_another_answer(): void
    {
        $check = fn (string $value): array => ['rules' => [], 'message' => '', 'checks' => [[
            'id' => 'k-umur', 'level' => 'error', 'message' => 'Umur anak melebihi umur ibu.',
            'conditions' => ['operator' => 'and', 'rules' => [['question_id' => 'q-anak', 'operator' => 'greater_than', 'value' => $value]]],
        ]]];
        $survey = $this->makeSurvey([
            $this->question('q-ibu', 'number', 'Umur ibu'),
            $this->question('q-anak', 'number', 'Umur anak', ['validation' => $check('{{q-ibu|150}}')]),
        ]);

        $this->post(route('form.store', $survey), ['answers' => ['q-ibu' => '30', 'q-anak' => '35']])
            ->assertSessionHasErrors(['answers.q-anak' => 'Umur anak melebihi umur ibu.']);
        $this->post(route('form.store', $survey), ['answers' => ['q-ibu' => '30', 'q-anak' => '5']])
            ->assertRedirect(route('form.thanks', $survey));
        // Without the mother's age the fallback (150) is compared instead.
        $this->post(route('form.store', $survey), ['answers' => ['q-anak' => '35']])
            ->assertRedirect(route('form.thanks', $survey));

        $this->assertContains('galat jika 102 lebih dari jawaban 101: Umur anak melebihi umur ibu.', SurveyJson::export($survey)['validation_rules'][1]['rules']);

        // The builder refuses a tag that points at a question that does not exist.
        $blocks = [['id' => 'b-1', 'title' => 'ART', 'layout' => 'full', 'condition' => null, 'questions' => [
            $this->question('q-ibu', 'number', 'Umur ibu'),
            $this->question('q-anak', 'number', 'Umur anak', ['validation' => $check('{{q-hilang}}')]),
        ]]];
        $this->post(route('surveys.store'), [
            'title' => 'Survei', 'form_code' => 'S-1', 'activity' => 'Pendataan',
            'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0'],
            'header' => json_encode(['items' => []]), 'blocks' => json_encode($blocks),
        ])->assertSessionHasErrors('blocks.0.questions.1.validation');
    }

    public function test_blocks_can_mix_portrait_and_landscape_pages(): void
    {
        $block = fn (string $id, array $attributes = []): array => [
            'id' => $id, 'title' => 'BLOK '.$id, 'layout' => 'full', 'condition' => null,
            'questions' => [$this->question('q-'.$id, 'short', 'Isian '.$id)], ...$attributes,
        ];
        $payload = [
            'title' => 'Survei', 'form_code' => 'S-1', 'activity' => 'Pendataan',
            'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0'],
            'header' => json_encode(['items' => []]),
        ];

        $this->post(route('surveys.store'), [...$payload, 'blocks' => json_encode([$block('a'), $block('b', ['orientation' => 'landscape']), $block('c', ['orientation' => 'landscape']), $block('d')])])
            ->assertSessionHasNoErrors();

        $survey = Survey::query()->sole();
        $this->assertSame(['portrait', 'landscape', 'landscape', 'portrait'], array_map($survey->blockOrientation(...), $survey->blocks));

        // Three sheets: portrait, landscape (two blocks together), portrait again.
        $html = $this->get(route('surveys.print', $survey))->assertOk()->assertSee('potret + lanskap')->getContent();
        $this->assertSame(2, substr_count($html, '<main class="sheet is-portrait">'));
        $this->assertSame(1, substr_count($html, '<main class="sheet is-landscape">'));
        $this->assertSame(substr_count($html, '<main'), substr_count($html, '</main>'));

        $this->post(route('surveys.store'), [...$payload, 'blocks' => json_encode([$block('a', ['orientation' => 'miring'])])])
            ->assertSessionHasErrors('blocks.0.orientation');
    }

    public function test_checks_can_compare_with_a_range_of_numbers(): void
    {
        $survey = $this->makeSurvey([
            $this->question('q-umur', 'number', 'Umur', ['required' => true]),
            $this->question('q-hamil', 'single', 'Sedang hamil?', ['options' => ['Ya', 'Tidak'], 'validation' => ['rules' => [], 'message' => '', 'checks' => [[
                'id' => 'k-rentang', 'level' => 'warning', 'message' => 'Umur di luar rentang lazim kehamilan (15-49 tahun). Apakah benar?',
                'conditions' => ['operator' => 'and', 'rules' => [
                    ['question_id' => 'q-hamil', 'operator' => 'equals', 'value' => 'Ya'],
                    ['question_id' => 'q-umur', 'operator' => 'not_between', 'value' => '15;49'],
                ]],
            ]]]]),
            $this->question('q-lansia', 'short', 'Nama pendamping', ['logic' => $this->logic('q-umur', 'greater_or_equal', '60', ['require'])]),
        ]);

        // Unusual ages (10 and 90) raise the warning; the ends of the range (15 and 49) do not.
        foreach (['10', '90'] as $age) {
            $this->post(route('form.store', $survey), ['answers' => ['q-umur' => $age, 'q-hamil' => 'Ya', 'q-lansia' => 'Budi']])
                ->assertSessionHasErrors(['answers.q-hamil' => 'Peringatan: Umur di luar rentang lazim kehamilan (15-49 tahun). Apakah benar?']);
        }
        foreach (['15', '30', '49'] as $age) {
            $this->post(route('form.store', $survey), ['answers' => ['q-umur' => $age, 'q-hamil' => 'Ya']])
                ->assertRedirect(route('form.thanks', $survey));
        }

        // "Minimal 60" includes 60 itself.
        $this->post(route('form.store', $survey), ['answers' => ['q-umur' => '60']])->assertSessionHasErrors('answers.q-lansia');
        $this->post(route('form.store', $survey), ['answers' => ['q-umur' => '59']])->assertRedirect(route('form.thanks', $survey));

        $this->assertContains(
            'peringatan jika 102 sama dengan Ya dan 101 di luar rentang 15 sampai 49: Umur di luar rentang lazim kehamilan (15-49 tahun). Apakah benar?',
            SurveyJson::export($survey)['validation_rules'][1]['rules'],
        );

        // A range must be two numbers in order.
        $blocks = fn (string $value): string => json_encode([['id' => 'b-1', 'title' => 'ART', 'layout' => 'full', 'condition' => null, 'questions' => [
            $this->question('q-umur', 'number', 'Umur'),
            $this->question('q-nama', 'short', 'Nama', ['logic' => $this->logic('q-umur', 'between', $value, ['require'])]),
        ]]]);
        $payload = [
            'title' => 'Survei', 'form_code' => 'S-1', 'activity' => 'Pendataan',
            'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0'],
            'header' => json_encode(['items' => []]),
        ];
        $this->post(route('surveys.store'), [...$payload, 'blocks' => $blocks('49;15')])->assertSessionHasErrors('blocks.0.questions.1.logic');
        $this->post(route('surveys.store'), [...$payload, 'blocks' => $blocks('15')])->assertSessionHasErrors('blocks.0.questions.1.logic');
        $this->post(route('surveys.store'), [...$payload, 'blocks' => $blocks('15;49')])->assertSessionHasNoErrors();
    }
}
