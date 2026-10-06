<?php

namespace Tests\Feature;

use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SurveyBuilderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<array<string, mixed>>|null  $blocks
     * @param  list<array<string, mixed>>|null  $headerItems
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = [], ?array $blocks = null, ?array $headerItems = null): array
    {
        return array_replace_recursive([
            'title' => 'Survei Rumah Tangga',
            'form_code' => 'VSEN26.KP',
            'subtitle' => 'Keterangan konsumsi rumah tangga',
            'activity' => 'Pendataan 2026',
            'settings' => [
                'paper_size' => 'F4',
                'orientation' => 'landscape',
                'period_type' => 'semester',
                'period_value' => 2,
                'theme' => 'blue',
                'numbering' => 'block',
                'thank_you_message' => 'Terima kasih!',
                'confidential' => '0',
            ],
            'intro' => [
                'text' => 'Terima kasih atas partisipasi Anda.',
                'help_title' => 'Sekretariat Survei',
                'help_org' => 'BPS Kabupaten Bangkalan',
                'help_phone' => '0815-1126-2026',
                'help_email' => 'survei@example.test',
                'notice' => 'Pendataan ini tidak dipungut biaya.',
                'guide' => "Isi berurutan.\nJawaban tersimpan sementara.",
            ],
            'header' => json_encode(['items' => $headerItems ?? [
                ['id' => 'h-1', 'type' => 'logo', 'slot' => 'left', 'text' => '', 'style' => 'bold', 'path' => 'logos/bps.png', 'height' => 64],
                ['id' => 'h-2', 'type' => 'badge', 'slot' => 'left', 'text' => 'RAHASIA', 'style' => 'dark', 'path' => null, 'height' => 64],
                ['id' => 'h-3', 'type' => 'text', 'slot' => 'center', 'text' => 'SENSUS EKONOMI 2026', 'style' => 'bold', 'path' => null, 'height' => 64],
                ['id' => 'h-4', 'type' => 'badge', 'slot' => 'right', 'text' => 'CAWI', 'style' => 'orange', 'path' => null, 'height' => 64],
            ]]),
            'blocks' => json_encode($blocks ?? $this->validBlocks()),
        ], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validBlocks(): array
    {
        return [
            [
                'id' => 'b-1',
                'title' => 'IDENTITAS WILAYAH',
                'layout' => 'full',
                'condition' => null,
                'questions' => [
                    ['id' => 'q-wil', 'type' => 'region', 'label' => 'Identitas wilayah', 'required' => true, 'region' => ['fields' => [
                        ['key' => 'provinsi', 'label' => 'Provinsi', 'enabled' => true, 'mode' => 'fixed', 'code' => '35', 'name' => 'JAWA TIMUR'],
                        ['key' => 'kecamatan', 'label' => 'Kecamatan', 'enabled' => true, 'mode' => 'input', 'code' => '', 'name' => ''],
                    ]]],
                    ['id' => 'q-1', 'type' => 'short', 'label' => 'Provinsi', 'description' => 'Tulis nama lengkap.', 'required' => true, 'code_length' => 2, 'options' => []],
                    ['id' => 'q-2', 'type' => 'single', 'label' => 'Klasifikasi', 'required' => true, 'code_length' => 1, 'options' => ['Perkotaan', 'Perdesaan']],
                ],
            ],
            [
                'id' => 'b-2',
                'title' => 'KHUSUS PERDESAAN',
                'layout' => 'half',
                'condition' => ['question_id' => 'q-2', 'operator' => 'equals', 'value' => 'Perdesaan'],
                'questions' => [
                    ['id' => 'q-info', 'type' => 'info', 'label' => 'Petunjuk', 'description' => 'Isi sesuai kondisi.', 'required' => true, 'code_length' => 3],
                    ['id' => 'q-3', 'type' => 'code', 'label' => 'Nomor kode sampel', 'required' => false, 'code_length' => 5, 'options' => ['ignored'],
                        'condition' => ['question_id' => 'q-1', 'operator' => 'answered', 'value' => 'ignored']],
                    ['id' => 'q-tab', 'type' => 'table', 'label' => 'Banyaknya pekerja', 'required' => false, 'table' => [
                        'mode' => 'fixed', 'max_rows' => 10, 'rows' => ['Dibayar', '', 'Tidak dibayar'],
                        'columns' => [
                            ['id' => 'c-l', 'label' => 'Laki-laki', 'type' => 'number', 'options' => ['x']],
                            ['id' => 'c-s', 'label' => 'Status', 'type' => 'choice', 'options' => ['Tetap', ' ', 'Lepas']],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public function test_create_page_shows_the_builder(): void
    {
        $this->get(route('surveys.create'))
            ->assertOk()
            ->assertSee('KETERANGAN KEGIATAN')
            ->assertSee('Blok baru')
            ->assertSee('Atur kop & pembuka', false)
            ->assertSee('Identitas wilayah')
            ->assertSee('Tabel / listing')
            ->assertSee('Tampilan responden');
    }

    public function test_new_surveys_start_blank_or_from_a_template(): void
    {
        $this->get(route('surveys.create'))
            ->assertOk()
            ->assertSee('KETERANGAN UMUM')
            ->assertDontSee('Survei Sosial Ekonomi Nasional');

        $this->get(route('surveys.create', ['template' => 'household']))
            ->assertOk()
            ->assertSee('IDENTITAS WILAYAH')
            ->assertSee('Apakah terdapat perubahan wilayah?');

        $this->get(route('surveys.create', ['template' => 'listing']))
            ->assertOk()
            ->assertSee('Nama kepala keluarga');

        $this->get(route('surveys.create', ['template' => 'unknown']))->assertOk()->assertSee('KETERANGAN UMUM');
    }

    public function test_new_surveys_reuse_the_last_kop(): void
    {
        Survey::query()->create([
            'title' => 'Lama',
            'activity' => 'Pelatihan',
            'header' => ['items' => [['id' => 'h-1', 'type' => 'text', 'slot' => 'center', 'text' => 'BPS KABUPATEN BANGKALAN', 'style' => 'bold', 'path' => null, 'height' => 64]]],
            'intro' => ['help_phone' => '0815-0000-1111', 'text' => 'Khusus survei lama'],
            'blocks' => [],
        ]);

        $this->get(route('surveys.create'))
            ->assertSee('BPS KABUPATEN BANGKALAN')
            ->assertSee('0815-0000-1111')
            ->assertDontSee('Khusus survei lama');
    }

    public function test_builder_offers_activities_from_previous_surveys(): void
    {
        Survey::query()->create(['title' => 'Lama', 'activity' => 'Pendataan Rumah Tangga', 'blocks' => []]);

        $this->get(route('surveys.create'))->assertSee('Pendataan Rumah Tangga');
    }

    public function test_survey_is_created_with_kop_intro_blocks_and_logic(): void
    {
        $this->post(route('surveys.store'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $survey = Survey::query()->firstOrFail();

        $this->assertNotEmpty($survey->slug);
        $this->assertSame('blue', $survey->settings['theme']);
        $this->assertFalse($survey->settings['confidential']);
        $this->assertSame(['left', 'left', 'center', 'right'], array_column($survey->header['items'], 'slot'));
        $this->assertSame('logos/bps.png', $survey->header['items'][0]['path']);
        $this->assertSame('0815-1126-2026', $survey->intro['help_phone']);
        $this->assertSame(['IDENTITAS WILAYAH', 'KHUSUS PERDESAAN'], array_column($survey->blocks, 'title'));
        $this->assertSame('half', $survey->blocks[1]['layout']);
        $this->assertSame(['question_id' => 'q-2', 'operator' => 'equals', 'value' => 'Perdesaan'], $survey->blocks[1]['condition']);
        $this->assertSame('Tulis nama lengkap.', $survey->blocks[0]['questions'][1]['description']);

        $region = $survey->blocks[0]['questions'][0]['region']['fields'];
        $this->assertCount(count(Survey::REGION_FIELDS), $region);
        $this->assertSame(['provinsi', 'kecamatan'], array_column(array_filter($region, fn (array $field): bool => $field['enabled']), 'key'));

        [$info, $code, $table] = $survey->blocks[1]['questions'];
        $this->assertFalse($info['required']);
        $this->assertSame(0, $info['code_length']);
        $this->assertSame([], $code['options']);
        // A legacy "show when" condition becomes a hidden field with logic that shows it.
        $this->assertTrue($code['hidden']);
        $this->assertSame(['show'], $code['logic']['actions']);
        $this->assertSame('', $code['logic']['conditions']['rules'][0]['value']);
        $this->assertSame(['Dibayar', 'Tidak dibayar'], $table['table']['rows']);
        $this->assertSame(3, $table['table']['initial_rows']);
        $this->assertSame([], $table['table']['columns'][0]['options']);
        $this->assertSame(['Tetap', 'Lepas'], $table['table']['columns'][1]['options']);
    }

    public function test_sheet_label_can_be_renamed_or_removed(): void
    {
        $this->post(route('surveys.store'), $this->validPayload(['settings' => ['sheet_label' => '  TERBATAS  ', 'sheet_label_position' => 'right']]))->assertSessionHasNoErrors();
        $survey = Survey::query()->firstOrFail();

        $this->assertSame('TERBATAS', $survey->settings['sheet_label']);
        $this->assertSame('right', $survey->settings['sheet_label_position']);
        $this->get(route('surveys.edit', $survey))
            ->assertSee('value="TERBATAS"', false)
            ->assertSee('id="sheet-label-position" value="right"', false);
        $this->get(route('surveys.print', $survey))->assertSee('<div class="sheet-rule is-right"><span class="sheet-tag">TERBATAS</span></div>', false);

        $this->put(route('surveys.update', $survey), $this->validPayload(['settings' => ['sheet_label_position' => 'top']]))
            ->assertSessionHasErrors('settings.sheet_label_position');

        $this->put(route('surveys.update', $survey), $this->validPayload(['settings' => ['sheet_label' => '']]))->assertSessionHasNoErrors();

        $this->assertSame('', $survey->fresh()->settings['sheet_label']);
        $this->get(route('surveys.print', $survey))->assertDontSee('class="sheet-tag"', false);
    }

    public function test_top_line_texts_can_be_renamed_moved_or_removed(): void
    {
        $this->post(route('surveys.store'), $this->validPayload(['settings' => [
            'topline_label' => 'KUESIONER USAHA',
            'topline_label_position' => 'center',
            'form_code_position' => 'left',
        ]]))->assertSessionHasNoErrors();
        $survey = Survey::query()->firstOrFail();

        $this->assertSame(['KUESIONER USAHA', 'center', 'left'], [
            $survey->settings['topline_label'], $survey->settings['topline_label_position'], $survey->settings['form_code_position'],
        ]);
        $this->get(route('surveys.edit', $survey))
            ->assertSee('value="KUESIONER USAHA"', false)
            ->assertSee('id="topline-label-position" value="center"', false)
            ->assertSee('id="form-code-position" value="left"', false);
        $this->get(route('surveys.print', $survey))->assertSeeInOrder([
            '<div class="is-left"><span class="sheet-form-code">VSEN26.KP</span></div>',
            '<div class="is-center"><span class="sheet-topline-text">KUESIONER USAHA</span></div>',
        ], false);

        $this->put(route('surveys.update', $survey), $this->validPayload(['form_code' => '', 'settings' => ['topline_label' => '']]))->assertSessionHasNoErrors();
        $this->get(route('surveys.print', $survey))->assertDontSee('class="sheet-topline"', false);

        $this->put(route('surveys.update', $survey), $this->validPayload(['settings' => ['form_code_position' => 'bottom']]))
            ->assertSessionHasErrors('settings.form_code_position');
    }

    public function test_information_items_are_not_numbered(): void
    {
        $this->post(route('surveys.store'), $this->validPayload())->assertSessionHasNoErrors();
        $survey = Survey::query()->firstOrFail();

        $this->assertSame(['q-wil' => '101', 'q-1' => '102', 'q-2' => '103', 'q-3' => '201', 'q-tab' => '202'], $survey->questionNumbers());

        $survey->update(['settings' => ['numbering' => 'sequential'] + $survey->settings]);
        $this->assertSame(['1', '2', '3', '4', '5'], array_values($survey->fresh()->questionNumbers()));
    }

    public function test_saving_redirects_to_the_editor_preview_or_print_view(): void
    {
        $this->post(route('surveys.store'), $this->validPayload())
            ->assertRedirect(route('surveys.edit', Survey::query()->firstOrFail()));

        $survey = Survey::query()->firstOrFail();

        $this->put(route('surveys.update', $survey), $this->validPayload(['title' => 'Judul baru', 'intent' => 'preview']))
            ->assertRedirect(route('surveys.preview', $survey));
        $this->put(route('surveys.update', $survey), $this->validPayload(['intent' => 'print']))
            ->assertRedirect(route('surveys.print', $survey));

        $this->get(route('surveys.edit', $survey))->assertOk()->assertSee('Survei Rumah Tangga');
    }

    public function test_logic_must_point_to_an_earlier_simple_question(): void
    {
        $blocks = $this->validBlocks();
        $blocks[0]['condition'] = ['question_id' => 'q-2', 'operator' => 'equals', 'value' => 'Perkotaan'];
        $this->post(route('surveys.store'), $this->validPayload(blocks: $blocks))
            ->assertSessionHasErrors('blocks.0.condition');

        $blocks = $this->validBlocks();
        $blocks[0]['questions'][1]['condition'] = ['question_id' => 'q-wil', 'operator' => 'answered', 'value' => ''];
        $this->post(route('surveys.store'), $this->validPayload(blocks: $blocks))
            ->assertSessionHasErrors('blocks.0.questions.1.logic');
    }

    public function test_logic_needs_a_value_unless_it_checks_for_any_answer(): void
    {
        $blocks = $this->validBlocks();
        $blocks[1]['condition']['value'] = '';

        $this->post(route('surveys.store'), $this->validPayload(blocks: $blocks))
            ->assertSessionHasErrors('blocks.1.condition');
    }

    public function test_tables_and_regions_need_a_usable_configuration(): void
    {
        $blocks = $this->validBlocks();
        $blocks[0]['questions'][0]['region']['fields'] = [];
        $blocks[1]['questions'][2]['table']['rows'] = [''];

        $this->post(route('surveys.store'), $this->validPayload(blocks: $blocks))
            ->assertSessionHasErrors(['blocks.0.questions.0.region', 'blocks.1.questions.2.table']);

        $blocks = $this->validBlocks();
        $blocks[1]['questions'][2]['table']['columns'][1]['id'] = 'c-l';
        $this->post(route('surveys.store'), $this->validPayload(blocks: $blocks))
            ->assertSessionHasErrors('blocks.1.questions.2.table');
    }

    public function test_kop_items_can_be_nudged_up_or_down(): void
    {
        $this->post(route('surveys.store'), $this->validPayload(headerItems: [
            ['id' => 'h-1', 'type' => 'badge', 'slot' => 'left', 'text' => 'RAHASIA', 'style' => 'dark'],
            ['id' => 'h-2', 'type' => 'badge', 'slot' => 'right', 'text' => 'CAWI', 'style' => 'orange', 'offset' => -36],
        ]))->assertSessionHasNoErrors();
        $survey = Survey::query()->firstOrFail();
        $survey->update(['published_at' => now()]);

        $this->assertSame([0, -36], array_column($survey->header['items'], 'offset'));
        $html = $this->get(route('form.create', $survey))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<span class="kop-badge is-dark"\s*>RAHASIA<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="kop-badge is-orange"\s*style="transform: translateY\(-36px\);"\s*>CAWI<\/span>/', $html);

        $this->post(route('surveys.store'), $this->validPayload(headerItems: [
            ['id' => 'h-1', 'type' => 'badge', 'slot' => 'left', 'text' => 'RAHASIA', 'offset' => 200],
        ]))->assertSessionHasErrors('header.items.0.offset');
    }

    public function test_kop_columns_can_be_aligned_to_the_bottom(): void
    {
        $header = ['align' => 'bottom', 'items' => [
            ['id' => 'h-1', 'type' => 'badge', 'slot' => 'left', 'text' => 'RAHASIA', 'style' => 'dark'],
            ['id' => 'h-2', 'type' => 'badge', 'slot' => 'right', 'text' => 'CAWI', 'style' => 'orange'],
        ]];
        $this->post(route('surveys.store'), $this->validPayload(['header' => json_encode($header)]))->assertSessionHasNoErrors();
        $survey = Survey::query()->firstOrFail();
        $survey->update(['published_at' => now()]);

        $this->assertSame('bottom', $survey->headerAlign());
        $this->get(route('form.create', $survey))->assertSee('kop kop-align-bottom', false);
        $this->get(route('surveys.print', $survey))->assertSee('kop kop-align-bottom', false);
        $this->get(route('surveys.edit', $survey))->assertSee('data-kop-align="bottom"', false);

        $header['align'] = 'diagonal';
        $this->post(route('surveys.store'), $this->validPayload(['header' => json_encode($header)]))
            ->assertSessionHasErrors('header.align');
    }

    public function test_kop_items_are_validated(): void
    {
        $this->post(route('surveys.store'), $this->validPayload(headerItems: [
            ['id' => 'h-1', 'type' => 'logo', 'slot' => 'left', 'path' => '../.env', 'height' => 64],
            ['id' => 'h-2', 'type' => 'badge', 'slot' => 'top', 'text' => 'X'],
        ]))->assertSessionHasErrors(['header.items.0.path', 'header.items.1.slot']);
    }

    public function test_period_value_must_fit_the_period_type(): void
    {
        $this->post(route('surveys.store'), $this->validPayload([
            'settings' => ['period_type' => 'semester', 'period_value' => 3],
        ]))->assertSessionHasErrors('settings.period_value');
    }

    public function test_period_value_is_cleared_when_no_period_is_used(): void
    {
        $this->post(route('surveys.store'), $this->validPayload([
            'settings' => ['period_type' => 'none', 'period_value' => null],
        ]))->assertSessionHasNoErrors();

        $this->assertNull(Survey::query()->firstOrFail()->settings['period_value']);
    }

    public function test_invalid_settings_and_code_lengths_are_rejected(): void
    {
        $blocks = $this->validBlocks();
        $blocks[0]['questions'][1]['code_length'] = 21;

        $this->post(route('surveys.store'), $this->validPayload(['settings' => ['paper_size' => 'A0', 'theme' => 'pink', 'numbering' => 'roman']], $blocks))
            ->assertSessionHasErrors(['settings.paper_size', 'settings.theme', 'settings.numbering', 'blocks.0.questions.1.code_length']);
    }

    public function test_question_ids_must_be_unique_and_safe(): void
    {
        $blocks = $this->validBlocks();
        $blocks[0]['questions'][2]['id'] = 'q-1';
        $blocks[1]['questions'][1]['id'] = 'q.3';

        $this->post(route('surveys.store'), $this->validPayload(blocks: $blocks))
            ->assertSessionHasErrors(['blocks.0.questions.2.id', 'blocks.1.questions.1.id']);
    }

    public function test_forms_with_only_information_items_are_rejected(): void
    {
        $this->from(route('surveys.create'))
            ->post(route('surveys.store'), $this->validPayload(blocks: [['id' => 'b-1', 'title' => 'INFO', 'questions' => [
                ['id' => 'q-info', 'type' => 'info', 'label' => 'Petunjuk'],
            ]]]))
            ->assertRedirect(route('surveys.create'))
            ->assertSessionHasErrors('blocks');
    }

    public function test_invalid_blocks_json_keeps_the_builder_usable(): void
    {
        $this->from(route('surveys.create'))
            ->post(route('surveys.store'), $this->validPayload(['blocks' => 'not-json', 'header' => 'not-json']))
            ->assertSessionHasErrors(['blocks', 'header']);

        $this->get(route('surveys.create'))->assertOk();
    }

    public function test_logos_can_be_uploaded_and_listed(): void
    {
        Storage::fake('public');

        $response = $this->postJson(route('logos.store'), ['logo' => UploadedFile::fake()->image('bps.png', 120, 120)])
            ->assertCreated()
            ->assertJsonStructure(['path', 'url']);

        $this->assertMatchesRegularExpression('/^logos\/[A-Za-z0-9]+\.png$/', $response->json('path'));
        Storage::disk('public')->assertExists($response->json('path'));

        $this->getJson(route('logos.index'))
            ->assertOk()
            ->assertJsonPath('logos.0.path', $response->json('path'));
    }

    public function test_only_images_can_be_uploaded_as_logos(): void
    {
        Storage::fake('public');

        $this->postJson(route('logos.store'), ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
    }
}
