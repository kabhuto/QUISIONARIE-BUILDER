<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Support\SurveyCheck;
use App\Support\SurveyVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Methodology that travels with the instrument (variable codes, units, concepts, special answers),
 * the check before publishing, instrument versions, and safe resending from the field.
 */
class InstrumentQualityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $omzet
     */
    private function survey(array $omzet = [], bool $published = true): Survey
    {
        $question = fn (string $id, string $type, string $label, array $extra = []): array => [
            'id' => $id, 'type' => $type, 'label' => $label, 'description' => '', 'required' => true, 'code_length' => 0, 'options' => [],
            'hidden' => false, 'disabled' => false, 'validation' => ['rules' => [], 'message' => '', 'checks' => []], ...$extra,
        ];

        return Survey::query()->create([
            'title' => 'Pendataan Usaha', 'activity' => 'Uji', 'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => [['id' => 'b-1', 'title' => 'USAHA', 'layout' => 'full', 'condition' => null, 'questions' => [
                $question('q-nama', 'short', 'Nama usaha', ['variable' => 'R101']),
                $question('q-omzet', 'currency', 'Omzet usaha selama Oktober 2026', [
                    'variable' => 'R102', 'unit' => 'rupiah', 'nonresponse' => true,
                    'concept' => 'Omzet adalah nilai seluruh penjualan selama periode referensi.',
                    'validation' => ['rules' => [['type' => 'max_value', 'value' => '1000000000']], 'message' => '', 'checks' => []],
                    ...$omzet,
                ]),
                $question('q-jenis', 'single', 'Jenis usaha', ['variable' => 'R103', 'options' => ['Perdagangan', 'Jasa']]),
            ]]],
            'published_at' => $published ? now() : null,
        ]);
    }

    public function test_the_form_shows_the_unit_the_concept_and_the_special_answers(): void
    {
        $survey = $this->survey();

        $this->get(route('form.create', $survey))->assertOk()
            ->assertSee('Satuan: <b>rupiah</b>', false)
            ->assertSee('Lihat konsep dan definisi')
            ->assertSee('Omzet adalah nilai seluruh penjualan')
            ->assertSee('name="answers[q-omzet__status]"', false)
            ->assertSee('Menolak menjawab')
            ->assertSee('data-submission-id', false);
    }

    public function test_a_special_answer_satisfies_a_required_question_and_is_kept(): void
    {
        $survey = $this->survey();

        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Toko Maju', 'q-omzet__status' => 'menolak', 'q-omzet' => '999', 'q-jenis' => 'Jasa']])
            ->assertSessionHasNoErrors()->assertRedirect();

        $response = $survey->responses()->sole();
        $this->assertSame('menolak', $response->answers['q-omzet__status']);
        $this->assertArrayNotHasKey('q-omzet', $response->answers);
        $this->assertSame('[Menolak menjawab]', $response->displayAnswer(collect($survey->questions())->firstWhere('id', 'q-omzet')));

        // Without a special answer the question is still required, and an unknown one does not count.
        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Toko', 'q-omzet__status' => 'entah', 'q-jenis' => 'Jasa']])
            ->assertSessionHasErrors('answers.q-omzet');
    }

    public function test_the_export_names_columns_by_variable_code_and_unit(): void
    {
        $survey = $this->survey();
        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Toko', 'q-omzet' => '1500000', 'q-jenis' => 'Jasa']])->assertRedirect();

        $csv = $this->get(route('surveys.responses.export', $survey))->assertOk()->streamedContent();
        $this->assertStringContainsString('"[R102] 102 Omzet usaha selama Oktober 2026 (rupiah)"', $csv);
        $this->assertStringContainsString('Versi instrumen', $csv);
    }

    public function test_variable_codes_must_be_valid_and_unique(): void
    {
        $survey = $this->survey(published: false);
        $blocks = $survey->blocks;
        $save = fn (array $blocks) => $this->put(route('surveys.update', $survey), [
            'title' => $survey->title, 'activity' => $survey->activity, 'settings' => Survey::DEFAULT_SETTINGS + ['period_value' => null],
            'header' => json_encode(['items' => []]), 'blocks' => json_encode($blocks),
        ]);

        $blocks[0]['questions'][1]['variable'] = '1ABC';
        $save($blocks)->assertSessionHasErrors('blocks.0.questions.1.variable');

        // Codes are compared without regard to case.
        $blocks[0]['questions'][1]['variable'] = 'R102';
        $blocks[0]['questions'][2]['variable'] = 'r101';
        $save($blocks)->assertSessionHasErrors('blocks.0.questions.2.variable');

        $blocks[0]['questions'][2]['variable'] = 'b1_r3';
        $save($blocks)->assertSessionHasNoErrors();
        $this->assertSame('B1_R3', $survey->fresh()->blocks[0]['questions'][2]['variable']);
    }

    public function test_publishing_is_blocked_until_errors_are_fixed(): void
    {
        $survey = $this->survey(['variable' => 'R101'], published: false);

        $this->post(route('published-surveys.store', $survey))->assertSessionHas('publishErrors');
        $this->assertFalse($survey->fresh()->isPublished());
        $this->get(route('surveys.edit', $survey))->assertOk()->assertSee('Pemeriksaan publikasi')->assertSee('Kode variabel R101 dipakai lebih dari satu pertanyaan.');

        $fixed = $this->survey(published: false);
        $this->assertSame([], SurveyCheck::errors($fixed));
        $this->post(route('published-surveys.store', $fixed))->assertSessionHas('status');
        $this->assertTrue($fixed->fresh()->isPublished());
        $this->assertSame(1, $fixed->versions()->sole()->number);
    }

    public function test_changes_while_published_make_a_new_version_and_answers_keep_theirs(): void
    {
        $survey = $this->survey(published: false);
        $this->post(route('published-surveys.store', $survey));
        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'A', 'q-omzet' => '10', 'q-jenis' => 'Jasa']]);

        $blocks = $survey->fresh()->blocks;
        $blocks[0]['questions'][2]['options'][] = 'Industri';
        $blocks[0]['questions'][0]['label'] = 'Nama usaha/perusahaan';
        $survey->update(['blocks' => $blocks]);
        SurveyVersions::record($survey->fresh());
        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'B', 'q-omzet' => '20', 'q-jenis' => 'Industri']]);

        $this->assertSame([1, 2], $survey->responses()->orderBy('id')->pluck('survey_version')->all());
        $changes = $survey->versions()->where('number', 2)->sole()->changes;
        $this->assertSame(['changed', 'changed'], array_column($changes, 'kind'));
        $this->assertStringContainsString('Pilihan ditambah: Industri', $changes[1]['detail']);

        $this->get(route('surveys.versions.index', $survey))->assertOk()
            ->assertSee('Versi 2')->assertSee('Terbaru')->assertSee('Pilihan ditambah: Industri');
    }

    public function test_a_resent_submission_is_stored_once(): void
    {
        $survey = $this->survey();
        $answers = ['answers' => ['q-nama' => 'Toko', 'q-omzet' => '10', 'q-jenis' => 'Jasa'], '_submission_id' => 'a1b2c3d4-e5f6-4711-8899-aabbccddeeff'];

        $this->post(route('form.store', $survey), $answers)->assertRedirect(route('form.thanks', $survey));
        $this->post(route('form.store', $survey), $answers)->assertRedirect(route('form.thanks', $survey));

        $this->assertSame(1, $survey->responses()->count());
        $this->assertSame('a1b2c3d4-e5f6-4711-8899-aabbccddeeff', $survey->responses()->sole()->submission_id);
    }

    public function test_the_builder_offers_a_phone_preview(): void
    {
        $survey = $this->survey();

        $this->get(route('surveys.preview-phone', $survey))->assertOk()
            ->assertSee('<iframe src="'.route('surveys.preview', $survey).'"', false)
            ->assertSee('HP kecil');
    }
}
