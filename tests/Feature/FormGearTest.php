<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Survey;
use App\Support\FormGear;
use App\Support\SurveyJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class FormGearTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A small FormGear template with the features FASIH questionnaires use.
     *
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return [
            'description' => 'Survei keluarga', 'dataKey' => 'survei_keluarga', 'title' => 'Survei Keluarga', 'acronym' => 'SK-26', 'version' => '1.0.0',
            'components' => [[
                ['label' => 'Persetujuan', 'dataKey' => 'sec_1', 'type' => 1, 'components' => [
                    ['label' => 'Saya bersedia diwawancarai', 'dataKey' => 'setuju', 'type' => 16],
                ]],
                ['label' => 'Keterangan Keluarga', 'dataKey' => 'sec_2', 'type' => 1, 'description' => 'Diisi oleh kepala keluarga',
                    'enableCondition' => "getValue('setuju') == true", 'componentEnable' => ['setuju'], 'components' => [
                        ['label' => 'Nomor KK', 'dataKey' => 'no_kk', 'type' => 24, 'maskingFormat' => '9999999999999999', 'required' => true],
                        ['label' => 'Jenis kelamin KK', 'dataKey' => 'jk', 'type' => 26, 'typeOption' => 1, 'options' => [['label' => 'Laki-laki', 'value' => '1'], ['label' => 'Perempuan', 'value' => '2']]],
                        ['label' => 'Sedang hamil?', 'dataKey' => 'hamil', 'type' => 26, 'typeOption' => 1, 'options' => [['label' => 'Ya', 'value' => '1'], ['label' => 'Tidak', 'value' => '2']],
                            'enableCondition' => "(getValue('jk')[0].value == '2' || getValue('setuju') == false)", 'componentEnable' => ['jk', 'setuju']],
                        ['label' => 'Fasilitas rumah', 'dataKey' => 'fasilitas', 'type' => 29, 'options' => [['label' => 'Listrik', 'value' => '1'], ['label' => 'Air bersih', 'value' => '2'], ['label' => 'Lainnya', 'value' => '9', 'open' => true]]],
                        ['label' => 'Fasilitas utama', 'dataKey' => 'fasilitas_utama', 'type' => 26, 'typeOption' => 3, 'sourceOption' => 'fasilitas'],
                        ['label' => 'Pengeluaran makan', 'dataKey' => 'makan', 'type' => 28, 'rangeInput' => [['min' => 0, 'max' => 50000000]]],
                        ['label' => 'Pengeluaran lain', 'dataKey' => 'lain', 'type' => 28],
                        ['label' => 'Total pengeluaran', 'dataKey' => 'total', 'type' => 4, 'expression' => "Number(getValue('makan')) + Number(getValue('lain'))", 'componentVar' => ['makan', 'lain'], 'render' => true],
                        ['label' => 'Nama anggota keluarga', 'dataKey' => 'nama_art', 'type' => 21],
                        ['label' => 'Rincian ART', 'dataKey' => 'rincian', 'type' => 2, 'sourceQuestion' => 'nama_art', 'components' => [[
                            ['label' => 'Umur $NAME$', 'dataKey' => 'umur', 'type' => 28],
                            ['label' => 'Sekolah?', 'dataKey' => 'sekolah', 'type' => 26, 'options' => [['label' => 'Ya', 'value' => '1'], ['label' => 'Tidak', 'value' => '2']],
                                'enableCondition' => "Number(getValue('umur@\$ROW\$')) < 25", 'componentEnable' => ['umur@$ROW$']],
                        ]]],
                        ['label' => 'Provinsi', 'dataKey' => 'prov', 'type' => 27, 'typeOption' => 2, 'sourceAPI' => [['baseUrl' => 'https://contoh.test/provinsi', 'data' => 'data', 'value' => 'kode', 'label' => 'nama']]],
                        ['label' => 'Kabupaten', 'dataKey' => 'kab', 'type' => 27, 'typeOption' => 2, 'sourceAPI' => [['baseUrl' => 'https://contoh.test/kabupaten', 'data' => 'data', 'value' => 'kode', 'label' => 'nama', 'filterDependencies' => [['params' => 'prov', 'sourceAnswer' => 'prov']]]]],
                        ['label' => 'Nama pencacah', 'dataKey' => 'pencacah', 'type' => 25],
                    ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validation(): array
    {
        return ['dataKey' => 'survei_keluarga', 'testFunctions' => [
            ['dataKey' => 'pencacah', 'componentValidation' => ['pencacah'], 'validations' => [['test' => "getValue('pencacah').length < 3", 'message' => 'Nama pencacah minimal 3 huruf', 'type' => 2]]],
            ['dataKey' => 'hamil', 'componentValidation' => ['hamil', 'jk'], 'validations' => [['test' => "getValue('jk')[0].value == '1' && getValue('hamil')[0].value == '1'", 'message' => 'Laki-laki tidak mungkin hamil', 'type' => 2]]],
            ['dataKey' => 'makan', 'componentValidation' => ['makan'], 'validations' => [['test' => "Number(getValue('makan')) > 10000000", 'message' => 'Pengeluaran makan sangat besar, yakin?', 'type' => 1]]],
            ['dataKey' => 'no_kk', 'componentValidation' => ['no_kk'], 'validations' => [['test' => "getValue('no_kk') == ''", 'message' => 'Nomor KK wajib diisi', 'type' => 2]]],
        ]];
    }

    public function test_a_formgear_template_with_validation_and_preset_is_imported(): void
    {
        [$definition, $notes] = FormGear::import($this->template(), $this->validation(), ['predata' => [['dataKey' => 'pencacah', 'answer' => 'Andi']]]);
        $saved = SurveyJson::definition($definition);
        $questions = collect($saved['blocks'])->flatMap(fn (array $block): array => $block['questions'])->keyBy('id');

        $this->assertSame('Survei Keluarga', $saved['title']);
        $this->assertSame('SK-26', $saved['form_code']);

        // A section shown on one condition becomes the block's condition; codes in conditions become option labels.
        $this->assertSame(['question_id' => 'setuju', 'operator' => 'equals', 'value' => 'Ya'], $saved['blocks'][1]['condition']);
        $this->assertSame('or', $questions['hamil']['logic']['conditions']['operator']);
        $this->assertSame(['question_id' => 'jk', 'operator' => 'equals', 'value' => 'Perempuan'], $questions['hamil']['logic']['conditions']['rules'][0]);
        $this->assertSame(['question_id' => 'setuju', 'operator' => 'empty', 'value' => ''], $questions['hamil']['logic']['conditions']['rules'][1]);
        $this->assertTrue($questions['hamil']['hidden']);

        $this->assertSame('9999999999999999', $questions['no_kk']['mask']);
        $this->assertTrue($questions['no_kk']['required']);
        $this->assertTrue($questions['fasilitas']['other_option']);
        $this->assertSame('fasilitas', $questions['fasilitas_utama']['options_from']);
        $this->assertSame('{{makan}} + {{lain}}', $questions['total']['formula']);
        $this->assertSame([['type' => 'min_value', 'value' => '0'], ['type' => 'max_value', 'value' => '50000000']], $questions['makan']['validation']['rules']);
        $this->assertSame('warning', $questions['makan']['validation']['checks'][0]['level']);
        $this->assertSame('error', $questions['hamil']['validation']['checks'][0]['level']);
        $this->assertSame('min_length', $questions['pencacah']['validation']['rules'][0]['type']);
        $this->assertSame('Andi', $questions['pencacah']['prefill']);
        $this->assertSame(['source' => 'api', 'url' => 'https://contoh.test/kabupaten', 'data_path' => 'data', 'value_key' => 'kode', 'label_key' => 'nama', 'param' => 'prov', 'depends_on' => 'prov'], $questions['kab']['lookup']);

        // The nested input repeats per listed name; "$ROW$" reads the same row, "$NAME$" is the row's name.
        $roster = collect($saved['blocks'])->firstWhere('repeat.source', 'nama_art');
        $this->assertNotNull($roster);
        $this->assertSame('Umur {{@baris}}', $questions['umur']['label']);
        $this->assertSame(['question_id' => 'umur', 'operator' => 'less_than', 'value' => '25'], $questions['sekolah']['logic']['conditions']['rules'][0]);
        // Questions after the nested input continue in their own block.
        $this->assertStringEndsWith('(LANJUTAN)', collect($saved['blocks'])->last()['title']);
        $this->assertIsArray($notes);
    }

    public function test_formgear_files_are_imported_from_the_dashboard_and_the_api(): void
    {
        $files = [
            UploadedFile::fake()->createWithContent('template.json', json_encode($this->template())),
            UploadedFile::fake()->createWithContent('validation.json', json_encode($this->validation())),
        ];

        $this->post(route('surveys.import'), ['files' => $files])
            ->assertRedirect()
            ->assertSessionHas('status', 'Template FormGear berhasil diimpor sebagai draf baru.')
            ->assertSessionHas('importNotes');
        $this->assertSame('Survei Keluarga', Survey::query()->sole()->title);

        [, $plain] = ApiToken::issue('Uji', true);
        $this->postJson('/api/v1/surveys', ['template' => $this->template(), 'validation' => $this->validation()], ['Authorization' => 'Bearer '.$plain])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Survei Keluarga');
        $this->assertSame(2, Survey::query()->count());
    }

    public function test_a_questionnaire_is_exported_as_formgear_files_and_comes_back_whole(): void
    {
        [$definition] = FormGear::import($this->template(), $this->validation());
        $survey = Survey::query()->create(SurveyJson::definition($definition));

        $export = FormGear::export($survey);
        $components = collect($export['template']['components'][0])->flatMap(fn (array $section): array => $section['components'])->keyBy('dataKey');

        $this->assertSame('SK-26', $export['template']['acronym']);
        $this->assertSame(24, $components['no_kk']['type']);
        $this->assertSame('9999999999999999', $components['no_kk']['maskingFormat']);
        $this->assertSame(4, $components['total']['type']);
        $this->assertSame("(Number(getValue('makan')) + Number(getValue('lain')))", $components['total']['expression']);
        $this->assertSame(3, $components['fasilitas_utama']['typeOption']);
        $this->assertStringContainsString("getValue('setuju') == true", collect($export['template']['components'][0])->firstWhere('dataKey', $survey->blocks[1]['id'])['enableCondition']);
        $this->assertStringContainsString("getValue('jk')[0].value : '') == \"2\"", $components['hamil']['enableCondition']);
        $this->assertSame(2, collect($components)->firstWhere('sourceQuestion', 'nama_art')['type']);
        $this->assertContains(1, collect($export['validation']['testFunctions'])->flatMap(fn (array $test): array => array_column($test['validations'], 'type'))->all());

        // The ZIP for FormGear apps, and back into this app without loss.
        $response = $this->get(route('surveys.export-formgear', $survey))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['template.json', 'validation.json', 'preset.json', 'CATATAN.txt'], array_map(fn (int $index): string => $zip->getNameIndex($index), range(0, $zip->numFiles - 1)));
        $template = json_decode($zip->getFromName('template.json'), true);
        $zip->close();

        [$back] = FormGear::import($template);
        $this->assertEquals(SurveyJson::definition(SurveyJson::definitionData($survey))['blocks'], SurveyJson::definition($back)['blocks']);

        [, $plain] = ApiToken::issue('Uji', false);
        $this->getJson("/api/v1/surveys/{$survey->id}?format=formgear", ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('template.dataKey', 'sk_26');
    }
}
