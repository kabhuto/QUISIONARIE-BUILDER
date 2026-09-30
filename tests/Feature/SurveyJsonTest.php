<?php

namespace Tests\Feature;

use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SurveyJsonTest extends TestCase
{
    use RefreshDatabase;

    private const LOGO = 'logos/kop123.png';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function question(string $id, string $type, string $label, array $attributes = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'description' => '', 'required' => false, 'code_length' => 0, 'options' => [], 'condition' => null, ...$attributes];
    }

    private function makeSurvey(): Survey
    {
        Storage::fake('public');
        Storage::disk('public')->put(self::LOGO, UploadedFile::fake()->image('kop.png', 40, 40)->getContent());

        $survey = Survey::query()->create([
            'title' => 'Survei Kepuasan',
            'form_code' => 'SK-01',
            'activity' => 'Survei Kepuasan 2026',
            'settings' => Survey::DEFAULT_SETTINGS,
            'header' => ['items' => [
                ['id' => 'h-1', 'type' => 'logo', 'slot' => 'left', 'text' => '', 'style' => 'bold', 'path' => self::LOGO, 'height' => 64],
                ['id' => 'h-2', 'type' => 'badge', 'slot' => 'right', 'text' => 'CAWI', 'style' => 'orange', 'path' => null, 'height' => 64],
            ]],
            'blocks' => [[
                'id' => 'b-1', 'title' => 'IDENTITAS', 'layout' => 'full', 'condition' => null,
                'questions' => [
                    $this->question('q-nama', 'short', 'Nama', ['required' => true]),
                    $this->question('q-jk', 'single', 'Jenis kelamin', ['required' => true, 'code_length' => 1, 'options' => ['Laki-laki', 'Perempuan']]),
                    $this->question('q-umur', 'number', 'Umur', ['required' => true]),
                    $this->question('q-kerja', 'single', 'Bekerja?', ['required' => true, 'options' => ['Ya', 'Tidak']]),
                    $this->question('q-jam', 'number', 'Jam kerja', ['required' => true, 'condition' => ['question_id' => 'q-kerja', 'operator' => 'equals', 'value' => 'Ya']]),
                    $this->question('q-kode', 'code', 'Kode sampel', ['code_length' => 3]),
                ],
            ]],
            'published_at' => now(),
        ]);

        $survey->responses()->create(['answers' => ['q-nama' => 'Budi', 'q-jk' => 'Laki-laki', 'q-umur' => '40', 'q-kerja' => 'Ya', 'q-jam' => '35']]);
        $survey->responses()->create(['answers' => ['q-nama' => 'Siti', 'q-jk' => 'Perempuan', 'q-umur' => '32', 'q-kerja' => 'Tidak', 'q-kode' => '007']]);

        return $survey;
    }

    private function exportFile(Survey $survey): UploadedFile
    {
        $json = $this->get(route('surveys.export-json', $survey))->getContent();

        return UploadedFile::fake()->createWithContent('template.json', $json);
    }

    public function test_survey_structure_rules_responses_and_logos_are_exported(): void
    {
        $this->freezeTime();
        $survey = $this->makeSurvey();

        $response = $this->get(route('surveys.export-json', $survey))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="survei-kepuasan-'.now()->format('Ymd-His').'.json"');

        $json = $response->json();
        $this->assertSame('ruang-kuesioner', $json['format']);
        $this->assertSame('Survei Kepuasan', $json['survey']['title']);
        $this->assertSame('q-nama', $json['survey']['blocks'][0]['questions'][0]['id']);
        $this->assertCount(6, $json['validation_rules']);
        $this->assertSame(['wajib diisi', 'salah satu dari: Laki-laki | Perempuan'], $json['validation_rules'][1]['rules']);
        $this->assertSame('104 sama dengan Ya', $json['validation_rules'][4]['shown_if']);
        $this->assertSame('tepat 3 digit angka', $json['validation_rules'][5]['rules'][1]);
        $this->assertCount(2, $json['responses']);
        $this->assertSame('Budi', $json['responses'][0]['answers']['q-nama']);
        $this->assertStringStartsWith('data:image/png;base64,', $json['assets']['logos'][self::LOGO]);
    }

    public function test_an_export_can_be_imported_as_a_new_draft_with_its_logos(): void
    {
        $survey = $this->makeSurvey();
        $file = $this->exportFile($survey);
        Storage::disk('public')->delete(self::LOGO);

        $this->post(route('surveys.import'), ['file' => $file])->assertRedirect();

        $copy = Survey::query()->latest('id')->firstOrFail();
        $this->assertNotSame($survey->id, $copy->id);
        $this->assertFalse($copy->isPublished());
        $this->assertSame(0, $copy->responses()->count());
        $this->assertSame('Survei Kepuasan', $copy->title);
        $this->assertSame(array_column($survey->questions(), 'id'), array_column($copy->questions(), 'id'));
        $this->assertSame($survey->blocks[0]['questions'][4]['condition'], $copy->blocks[0]['questions'][4]['condition']);
        $this->assertSame(self::LOGO, $copy->headerItems()[0]['path']);
        Storage::disk('public')->assertExists(self::LOGO);
    }

    public function test_import_rejects_files_that_are_not_exports_or_not_valid(): void
    {
        $this->post(route('surveys.import'), ['file' => UploadedFile::fake()->createWithContent('x.json', '{"hello": "world"}')])
            ->assertSessionHasErrors('file');

        $broken = json_encode(['format' => 'ruang-kuesioner', 'survey' => ['title' => 'Tanpa blok', 'activity' => 'X', 'blocks' => []]]);
        $this->post(route('surveys.import'), ['file' => UploadedFile::fake()->createWithContent('x.json', $broken)])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Survey::query()->count());
    }

    public function test_imported_logos_must_be_real_images(): void
    {
        $survey = $this->makeSurvey();
        $json = json_decode($this->get(route('surveys.export-json', $survey))->getContent(), true);
        Storage::disk('public')->delete(self::LOGO);
        $json['assets']['logos'][self::LOGO] = 'data:image/png;base64,'.base64_encode('<?php echo "bukan gambar";');

        $this->post(route('surveys.import'), ['file' => UploadedFile::fake()->createWithContent('x.json', json_encode($json))])->assertRedirect();

        Storage::disk('public')->assertMissing(self::LOGO);
    }

    public function test_the_template_validates_its_own_responses(): void
    {
        $file = $this->exportFile($this->makeSurvey());

        $this->post(route('validation.store'), ['template' => $file])
            ->assertOk()
            ->assertSee('respons di dalam template')
            ->assertSeeInOrder(['Total record', '2', 'Valid', '2', 'Bermasalah', '0']);
    }

    public function test_the_template_validates_data_from_another_source(): void
    {
        $template = $this->exportFile($this->makeSurvey());
        $data = UploadedFile::fake()->createWithContent('data.json', json_encode([
            ['101' => 'Ani', '102' => 'Perempuan', '103' => '28', '104' => 'Tidak'],
            ['answers' => ['q-nama' => '', 'q-jk' => 'Pria', 'q-umur' => 'dua puluh', 'q-kerja' => 'Ya', 'q-kode' => '12', 'catatan' => 'x']],
        ]));

        $this->post(route('validation.store'), ['template' => $template, 'data' => $data])
            ->assertOk()
            ->assertSee('file data')
            ->assertSeeInOrder(['Total record', '2', 'Valid', '1', 'Bermasalah', '1'])
            ->assertSee('Nama wajib diisi.')
            ->assertSee('Pilihan untuk Jenis kelamin tidak valid.')
            ->assertSee('Umur harus berupa angka.')
            ->assertSee('Jam kerja wajib diisi.')
            ->assertSee('Kode sampel harus berisi 3 digit angka.')
            ->assertSee('Kolom tidak dikenal, diabaikan: catatan');
    }

    public function test_validation_needs_an_export_file_as_template(): void
    {
        $this->post(route('validation.store'), ['template' => UploadedFile::fake()->createWithContent('t.json', '[1, 2, 3]')])
            ->assertOk()
            ->assertSee('bukan hasil ekspor JSON Ruang Kuesioner');

        $this->get(route('validation.create'))->assertOk()->assertSee('Template (JSON ekspor)');
    }
}
