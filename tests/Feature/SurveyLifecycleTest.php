<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(string $id, string $type, string $label, array $attributes = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'description' => '', 'required' => false, 'code_length' => 0, 'options' => [], 'condition' => null, ...$attributes];
    }

    private function makeSurvey(bool $published = true): Survey
    {
        $survey = Survey::query()->create([
            'title' => 'Survei Rumah Tangga',
            'form_code' => 'VSEN26.KP',
            'activity' => 'Pendataan 2026',
            'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => [
                [
                    'id' => 'b-1',
                    'title' => 'KETERANGAN TEMPAT',
                    'layout' => 'full',
                    'condition' => null,
                    'questions' => [
                        $this->question('q-nama', 'short', 'Nama kepala rumah tangga', ['required' => true]),
                        $this->question('q-klas', 'single', 'Klasifikasi desa', ['required' => true, 'code_length' => 1, 'options' => ['Perkotaan', 'Perdesaan']]),
                        $this->question('q-kode', 'code', 'Kode sampel', ['code_length' => 3]),
                    ],
                ],
                [
                    'id' => 'b-2',
                    'title' => 'KHUSUS PERDESAAN',
                    'layout' => 'full',
                    'condition' => ['question_id' => 'q-klas', 'operator' => 'equals', 'value' => 'Perdesaan'],
                    'questions' => [
                        $this->question('q-sawah', 'number', 'Luas sawah (m2)', ['required' => true]),
                        $this->question('q-hasil', 'multiple', 'Hasil panen', ['options' => ['Padi', 'Jagung', 'Tembakau']]),
                    ],
                ],
            ],
        ]);

        if ($published) {
            $survey->update(['published_at' => now()]);
        }

        return $survey;
    }

    /**
     * A survey using the structured and formatted question types.
     */
    private function makeRichSurvey(): Survey
    {
        return Survey::query()->create([
            'title' => 'Registrasi Usaha',
            'activity' => 'Registrasi UMKM 2025',
            'settings' => Survey::DEFAULT_SETTINGS,
            'header' => ['items' => [
                ['id' => 'h-1', 'type' => 'badge', 'slot' => 'left', 'text' => 'RAHASIA', 'style' => 'dark', 'path' => null, 'height' => 64],
                ['id' => 'h-2', 'type' => 'text', 'slot' => 'center', 'text' => 'SENSUS EKONOMI 2026', 'style' => 'bold', 'path' => null, 'height' => 64],
                ['id' => 'h-3', 'type' => 'badge', 'slot' => 'right', 'text' => 'CAWI', 'style' => 'orange', 'path' => null, 'height' => 64],
            ]],
            'intro' => ['text' => 'Terima kasih atas partisipasi Bapak/Ibu.', 'help_title' => 'Sekretariat Sensus', 'help_phone' => '0815-1126-2026', 'notice' => 'Pendataan ini tidak ada hubungannya dengan pajak.'],
            'blocks' => [
                [
                    'id' => 'b-1', 'title' => 'IDENTITAS WILAYAH', 'layout' => 'full', 'condition' => null,
                    'questions' => [
                        $this->question('q-wil', 'region', 'Identitas wilayah', ['required' => true, 'region' => ['fields' => [
                            ['key' => 'provinsi', 'label' => 'Provinsi', 'enabled' => true, 'mode' => 'fixed', 'code' => '35', 'name' => 'JAWA TIMUR'],
                            ['key' => 'kabupaten', 'label' => 'Kabupaten/Kota', 'enabled' => true, 'mode' => 'fixed', 'code' => '3526', 'name' => 'BANGKALAN'],
                            ['key' => 'kecamatan', 'label' => 'Kecamatan', 'enabled' => true, 'mode' => 'input', 'code' => '', 'name' => ''],
                            ['key' => 'klasifikasi', 'label' => 'Klasifikasi desa/kelurahan', 'enabled' => true, 'mode' => 'input', 'code' => '', 'name' => ''],
                            ['key' => 'desa', 'label' => 'Desa/Kelurahan', 'enabled' => false, 'mode' => 'input', 'code' => '', 'name' => ''],
                        ]]]),
                        $this->question('q-info', 'info', 'Petunjuk', ['description' => 'Isi sesuai kondisi usaha.']),
                    ],
                ],
                [
                    'id' => 'b-2', 'title' => 'KARAKTERISTIK USAHA', 'layout' => 'half', 'condition' => null,
                    'questions' => [
                        $this->question('q-omzet', 'currency', 'Omzet setahun', ['required' => true]),
                        $this->question('q-email', 'email', 'Email'),
                        $this->question('q-hp', 'phone', 'Nomor HP'),
                        $this->question('q-kerja', 'table', 'Banyaknya pekerja', ['table' => [
                            'mode' => 'fixed', 'max_rows' => 10, 'rows' => ['Dibayar', 'Tidak dibayar'],
                            'columns' => [
                                ['id' => 'c-l', 'label' => 'Laki-laki', 'type' => 'number', 'options' => []],
                                ['id' => 'c-p', 'label' => 'Perempuan', 'type' => 'number', 'options' => []],
                            ],
                        ]]),
                    ],
                ],
                [
                    'id' => 'b-3', 'title' => 'DAFTAR KELUARGA', 'layout' => 'full', 'condition' => null,
                    'questions' => [
                        $this->question('q-list', 'table', 'Daftar keluarga', ['required' => true, 'table' => [
                            'mode' => 'dynamic', 'max_rows' => 3, 'rows' => [],
                            'columns' => [
                                ['id' => 'c-nama', 'label' => 'Nama kepala keluarga', 'type' => 'text', 'options' => []],
                                ['id' => 'c-jk', 'label' => 'Jenis kelamin', 'type' => 'choice', 'options' => ['Laki-laki', 'Perempuan']],
                            ],
                        ]]),
                    ],
                ],
            ],
            'published_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function richAnswers(array $overrides = []): array
    {
        return ['answers' => array_replace([
            'q-wil' => ['kecamatan' => 'Geger', 'klasifikasi' => 'PERDESAAN', 'provinsi' => 'DIUBAH'],
            'q-omzet' => 'Rp 1.500.000',
            'q-email' => 'usaha@example.test',
            'q-hp' => '0812-3456-7890',
            'q-kerja' => [['c-l' => '2', 'c-p' => '1'], ['c-l' => '', 'c-p' => '']],
            'q-list' => [5 => ['c-nama' => 'Budi', 'c-jk' => 'Laki-laki'], 9 => ['c-nama' => '', 'c-jk' => ''], 12 => ['c-nama' => 'Siti', 'c-jk' => 'Perempuan']],
        ], $overrides)];
    }

    public function test_dashboard_lists_surveys_with_status_and_response_counts(): void
    {
        $survey = $this->makeSurvey();
        $survey->responses()->create(['answers' => ['q-nama' => 'Budi']]);

        $this->get(route('surveys.index'))
            ->assertOk()
            ->assertSee('Survei Rumah Tangga')
            ->assertSee('Terbit')
            ->assertSee(route('form.create', $survey));
    }

    public function test_dashboard_offers_templates_for_any_activity(): void
    {
        $this->get(route('surveys.index'))
            ->assertOk()
            ->assertSee('Registrasi usaha')
            ->assertSee('Listing')
            ->assertSee('Evaluasi / kepuasan kegiatan')
            ->assertSee(route('surveys.create', ['template' => 'registration']), false);
    }

    public function test_form_shows_the_configured_kop_and_intro(): void
    {
        $this->get(route('form.create', $this->makeRichSurvey()))
            ->assertOk()
            ->assertSeeInOrder(['RAHASIA', 'SENSUS EKONOMI 2026', 'CAWI'])
            ->assertSee('Bantuan Pengisian Kuesioner')
            ->assertSee('0815-1126-2026')
            ->assertSee('tidak ada hubungannya dengan pajak')
            ->assertSee('Undang-Undang Nomor 16 Tahun 1997')
            ->assertSee('[35] JAWA TIMUR')
            ->assertSee('[3526] BANGKALAN')
            ->assertSee('Isi sesuai kondisi usaha.')
            ->assertSee('BLOK III. DAFTAR KELUARGA');
    }

    public function test_confidentiality_statement_can_be_turned_off(): void
    {
        $survey = $this->makeSurvey();
        $survey->update(['settings' => ['confidential' => false] + Survey::DEFAULT_SETTINGS]);

        $this->get(route('form.create', $survey))
            ->assertOk()
            ->assertDontSee('UU No. 16 Tahun 1997')
            ->assertDontSee('Undang-Undang Nomor 16 Tahun 1997');
    }

    public function test_surveys_can_be_published_and_closed(): void
    {
        $survey = $this->makeSurvey(published: false);

        $this->get(route('form.create', $survey))->assertOk()->assertSee('tidak menerima jawaban');

        $this->post(route('published-surveys.store', $survey))->assertRedirect();
        $this->assertTrue($survey->fresh()->isPublished());
        $this->get(route('form.create', $survey))->assertOk()->assertSee('Kirim jawaban');

        $this->delete(route('published-surveys.destroy', $survey))->assertRedirect();
        $this->assertFalse($survey->fresh()->isPublished());
    }

    public function test_closed_surveys_reject_submissions(): void
    {
        $survey = $this->makeSurvey(published: false);

        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Budi', 'q-klas' => 'Perkotaan']])
            ->assertNotFound();

        $this->assertSame(0, SurveyResponse::query()->count());
    }

    public function test_preview_shows_the_form_without_a_submit_target(): void
    {
        $survey = $this->makeSurvey(published: false);

        $this->get(route('surveys.preview', $survey))
            ->assertOk()
            ->assertSee('Mode pratinjau')
            ->assertSee('Nama kepala rumah tangga')
            ->assertDontSee(route('form.store', $survey));
    }

    public function test_respondent_can_submit_and_hidden_blocks_are_ignored(): void
    {
        $survey = $this->makeSurvey();

        $this->post(route('form.store', $survey), [
            'answers' => [
                'q-nama' => '  Budi  ',
                'q-klas' => 'Perkotaan',
                'q-kode' => '007',
                // Block II is hidden for "Perkotaan": its required question is skipped and its answers dropped.
                'q-hasil' => ['Padi'],
            ],
        ])->assertRedirect(route('form.thanks', $survey));

        $this->assertSame(
            ['q-nama' => 'Budi', 'q-klas' => 'Perkotaan', 'q-kode' => '007'],
            SurveyResponse::query()->firstOrFail()->answers,
        );

        $this->get(route('form.thanks', $survey))->assertOk()->assertSee('Terima kasih');
    }

    public function test_questions_shown_by_logic_are_validated(): void
    {
        $survey = $this->makeSurvey();

        $this->post(route('form.store', $survey), [
            'answers' => ['q-nama' => 'Siti', 'q-klas' => 'Perdesaan', 'q-hasil' => ['Padi', 'Gandum']],
        ])->assertSessionHasErrors(['answers.q-sawah', 'answers.q-hasil.1']);

        $this->post(route('form.store', $survey), [
            'answers' => ['q-nama' => 'Siti', 'q-klas' => 'Perdesaan', 'q-sawah' => '1250', 'q-hasil' => ['Tembakau', 'Padi']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Padi', 'Tembakau'], SurveyResponse::query()->firstOrFail()->answers['q-hasil']);
    }

    public function test_follow_up_questions_depend_on_the_chosen_answer(): void
    {
        $survey = $this->makeSurvey();
        $blocks = $survey->blocks;
        array_splice($blocks[0]['questions'], 2, 0, [
            $this->question('q-alasan', 'short', 'Mengapa perkotaan?', ['required' => true, 'condition' => ['question_id' => 'q-klas', 'operator' => 'equals', 'value' => 'Perkotaan']]),
        ]);
        $survey->update(['blocks' => $blocks]);

        $this->get(route('form.create', $survey))
            ->assertOk()
            ->assertSee('fill-question cawi-question is-short is-branch', false);

        // "Perkotaan" reveals the follow-up, which is then required.
        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Budi', 'q-klas' => 'Perkotaan']])
            ->assertSessionHasErrors('answers.q-alasan');

        // "Perdesaan" keeps it hidden, so it is neither required nor stored.
        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Budi', 'q-klas' => 'Perdesaan', 'q-sawah' => '10', 'q-alasan' => 'abaikan']])
            ->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('q-alasan', SurveyResponse::query()->firstOrFail()->answers);
    }

    public function test_answers_are_validated_against_the_question_type(): void
    {
        $survey = $this->makeSurvey();

        $this->post(route('form.store', $survey), [
            'answers' => ['q-nama' => '', 'q-klas' => 'Kota besar', 'q-kode' => '12'],
        ])->assertSessionHasErrors(['answers.q-nama', 'answers.q-klas', 'answers.q-kode']);

        $this->assertSame(0, SurveyResponse::query()->count());
    }

    public function test_structured_answers_are_normalised_when_stored(): void
    {
        $survey = $this->makeRichSurvey();

        $this->post(route('form.store', $survey), $this->richAnswers())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('form.thanks', $survey));

        $answers = SurveyResponse::query()->firstOrFail()->answers;

        $this->assertSame(['provinsi' => '[35] JAWA TIMUR', 'kabupaten' => '[3526] BANGKALAN', 'kecamatan' => 'Geger', 'klasifikasi' => 'PERDESAAN'], $answers['q-wil']);
        $this->assertSame('1500000', $answers['q-omzet']);
        $this->assertSame([['c-l' => '2', 'c-p' => '1'], ['c-l' => '', 'c-p' => '']], $answers['q-kerja']);
        $this->assertSame([['c-nama' => 'Budi', 'c-jk' => 'Laki-laki'], ['c-nama' => 'Siti', 'c-jk' => 'Perempuan']], $answers['q-list']);
        $this->assertArrayNotHasKey('q-info', $answers);
    }

    public function test_structured_answers_are_validated(): void
    {
        $survey = $this->makeRichSurvey();

        $this->post(route('form.store', $survey), $this->richAnswers([
            'q-wil' => ['kecamatan' => '', 'klasifikasi' => 'KOTA'],
            'q-omzet' => 'Rp -',
            'q-email' => 'bukan-email',
            'q-hp' => 'telepon saya',
            'q-kerja' => [['c-l' => 'dua', 'c-p' => '1']],
            'q-list' => [['c-nama' => '', 'c-jk' => '']],
        ]))->assertSessionHasErrors([
            'answers.q-wil.kecamatan',
            'answers.q-wil.klasifikasi',
            'answers.q-omzet',
            'answers.q-email',
            'answers.q-hp',
            'answers.q-kerja.0.c-l',
            'answers.q-list',
        ]);

        $this->post(route('form.store', $survey), $this->richAnswers([
            'q-list' => array_fill(0, 4, ['c-nama' => 'Budi', 'c-jk' => 'Laki-laki']),
        ]))->assertSessionHasErrors('answers.q-list');

        $this->assertSame(0, SurveyResponse::query()->count());
    }

    public function test_listing_tables_start_with_the_configured_number_of_rows(): void
    {
        $survey = $this->makeRichSurvey();
        $blocks = $survey->blocks;
        $blocks[2]['questions'][0]['table']['initial_rows'] = 5;
        $blocks[2]['questions'][0]['table']['max_rows'] = 10;
        $survey->update(['blocks' => $blocks]);

        $form = $this->get(route('form.create', $survey))->assertOk()->getContent();
        $this->assertSame(5, preg_match_all('/name="answers\[q-list\]\[\d+\]\[c-nama\]"/', $form));

        $print = $this->get(route('surveys.print', $survey))->assertOk()->getContent();
        $this->assertStringContainsString('<th>5</th>', $print);
        $this->assertStringNotContainsString('<th>6</th>', $print);
    }

    public function test_responses_page_summarises_answers(): void
    {
        $survey = $this->makeSurvey();
        $survey->responses()->create(['answers' => ['q-nama' => 'Budi', 'q-klas' => 'Perkotaan']]);
        $survey->responses()->create(['answers' => ['q-nama' => 'Siti', 'q-klas' => 'Perdesaan', 'q-sawah' => '1000']]);
        $survey->responses()->create(['answers' => ['q-nama' => 'Ani', 'q-klas' => 'Perdesaan', 'q-sawah' => '3000']]);

        $this->get(route('surveys.responses.index', $survey))
            ->assertOk()
            ->assertSee('Ringkasan jawaban')
            ->assertSeeInOrder(['Perkotaan', '1', '33%', 'Perdesaan', '2', '67%'])
            ->assertSee('2000')
            ->assertSee('Budi')
            ->assertSee(route('surveys.responses.listing', $survey));
    }

    public function test_response_detail_is_scoped_to_its_survey(): void
    {
        $survey = $this->makeSurvey();
        $response = $survey->responses()->create(['answers' => ['q-nama' => 'Budi', 'q-hasil' => ['Padi', 'Jagung']]]);
        $otherSurvey = $this->makeSurvey();

        $this->get(route('surveys.responses.show', [$survey, $response]))
            ->assertOk()
            ->assertSee('Respons #1')
            ->assertSee('Padi; Jagung')
            ->assertSee('Tidak diisi / tidak tampil');

        $this->get(route('surveys.responses.show', [$otherSurvey, $response]))->assertNotFound();
        $this->get(route('surveys.responses.print', [$otherSurvey, $response]))->assertNotFound();
    }

    public function test_rich_responses_render_in_detail_summary_and_csv(): void
    {
        $survey = $this->makeRichSurvey();
        $this->post(route('form.store', $survey), $this->richAnswers())->assertSessionHasNoErrors();
        $response = SurveyResponse::query()->firstOrFail();

        $this->get(route('surveys.responses.show', [$survey, $response]))
            ->assertOk()
            ->assertSee('Rp 1.500.000')
            ->assertSee('[3526] BANGKALAN')
            ->assertSee('Siti');

        $this->get(route('surveys.responses.index', $survey))->assertOk()->assertSee('Rp 1.500.000');

        $csv = $this->get(route('surveys.responses.export', $survey))->streamedContent();
        $this->assertStringContainsString('Provinsi: [35] JAWA TIMUR', $csv);
        $this->assertStringContainsString('[1] Nama kepala keluarga=Budi, Jenis kelamin=Laki-laki | [2] Nama kepala keluarga=Siti', $csv);
        $this->assertStringContainsString('Dibayar Laki-laki=2, Perempuan=1', $csv);
    }

    public function test_blank_and_filled_forms_can_be_printed(): void
    {
        $survey = $this->makeRichSurvey();

        $this->get(route('surveys.print', $survey))
            ->assertOk()
            ->assertSee('size: 210mm 297mm', false)
            ->assertSeeInOrder(['BLOK I. IDENTITAS WILAYAH', 'BLOK II. KARAKTERISTIK USAHA', 'BLOK III. DAFTAR KELUARGA'])
            ->assertSee('sheet-block is-half', false);

        $this->post(route('form.store', $survey), $this->richAnswers())->assertSessionHasNoErrors();
        $response = SurveyResponse::query()->firstOrFail();

        $this->get(route('surveys.responses.print', [$survey, $response]))
            ->assertOk()
            ->assertSee('Rp 1.500.000')
            ->assertSee('Geger')
            ->assertSee('Siti');
    }

    public function test_responses_can_be_shown_as_a_listing(): void
    {
        $survey = $this->makeRichSurvey();
        $this->post(route('form.store', $survey), $this->richAnswers())->assertSessionHasNoErrors();
        $this->post(route('form.store', $survey), $this->richAnswers(['q-list' => [['c-nama' => 'Ani', 'c-jk' => 'Perempuan']]]))->assertSessionHasNoErrors();

        $this->get(route('surveys.responses.listing', $survey))
            ->assertOk()
            ->assertSee('LISTING')
            ->assertSeeInOrder(['Provinsi', 'Kabupaten/Kota', 'Kecamatan', 'Omzet setahun'])
            ->assertSee('Rp 1.500.000');

        $this->get(route('surveys.responses.listing', [$survey, 'source' => 'q-list']))
            ->assertOk()
            ->assertSeeInOrder(['Nama kepala keluarga', 'Jenis kelamin', 'Budi', 'Siti', 'Ani']);
    }

    public function test_responses_can_be_exported_as_csv(): void
    {
        $survey = $this->makeSurvey();
        $survey->responses()->create(['answers' => ['q-nama' => 'Budi', 'q-klas' => 'Perdesaan', 'q-hasil' => ['Padi', 'Jagung']]]);

        $response = $this->get(route('surveys.responses.export', $survey));

        $response->assertOk()->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('"101 Nama kepala rumah tangga"', $csv);
        $this->assertStringContainsString('"202 Hasil panen"', $csv);
        $this->assertStringContainsString('Budi,Perdesaan,,,"Padi; Jagung"', $csv);
    }

    public function test_deleting_a_survey_removes_its_responses(): void
    {
        $survey = $this->makeSurvey();
        $survey->responses()->create(['answers' => ['q-nama' => 'Budi']]);

        $this->delete(route('surveys.destroy', $survey))->assertRedirect(route('surveys.index'));

        $this->assertModelMissing($survey);
        $this->assertSame(0, SurveyResponse::query()->count());
    }
}
