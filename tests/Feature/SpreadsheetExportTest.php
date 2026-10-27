<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Support\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class SpreadsheetExportTest extends TestCase
{
    use RefreshDatabase;

    private function survey(): Survey
    {
        $question = fn (string $id, string $type, string $label, array $extra = []): array => ['id' => $id, 'type' => $type, 'label' => $label, 'required' => false, 'code_length' => 0, 'options' => [], ...$extra];

        return Survey::query()->create([
            'title' => 'Absensi Harian Peserta Magang: Oktober', 'form_code' => 'ABS', 'activity' => 'Uji coba', 'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => [['id' => 'b-1', 'title' => 'ABSEN', 'layout' => 'full', 'condition' => null, 'questions' => [
                $question('q-nama', 'short', 'Nama'),
                $question('q-kode', 'short', 'Kode desa'),
                $question('q-jam', 'number', 'Jam kerja'),
                $question('q-lokasi', 'location', 'Lokasi'),
                $question('q-foto', 'file', 'Foto kegiatan', ['file' => ['accept' => 'image', 'max_mb' => 5]]),
            ]]],
            'published_at' => now(),
        ]);
    }

    public function test_responses_are_exported_as_an_xlsx_workbook(): void
    {
        Storage::fake('public');
        $survey = $this->survey();
        $this->post(route('form.store', $survey), ['answers' => [
            'q-nama' => 'Nor Halimah & "Rara"', 'q-kode' => '0012', 'q-jam' => '8', 'q-lokasi' => '-7.0453, 112.738', 'q-lokasi__akurasi' => '12',
            'q-foto' => UploadedFile::fake()->image('kegiatan.jpg', 40, 40),
        ]])->assertRedirect();
        $response = $survey->responses()->sole();

        $download = $this->get(route('surveys.responses.export-xlsx', $survey))->assertOk();
        $this->assertStringEndsWith('.xlsx', (string) $download->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($download->baseResponse->getFile()->getPathname()));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        // Sheet names may not hold ":" and are cut to 31 characters.
        $this->assertStringContainsString('<sheet name="Absensi Harian Peserta Magang O"', $workbook);
        $this->assertStringContainsString('<t xml:space="preserve">101. Nama</t>', $sheet);
        $this->assertStringContainsString('Nor Halimah &amp; &quot;Rara&quot;', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">0012</t>', $sheet);
        $this->assertStringContainsString('<c r="E2"><v>8</v></c>', $sheet);
        $this->assertStringContainsString('-7.0453, 112.738 (±12 m)', $sheet);
        $this->assertStringContainsString('HYPERLINK(&quot;'.asset('storage/'.$response->answers['q-foto']), $sheet);
        $this->assertStringContainsString(route('surveys.responses.show', [$survey, $response]), $sheet);
        $this->assertStringContainsString('<pane ySplit="1"', $sheet);
        $this->assertSame('AA', Xlsx::column(26));
    }

    public function test_the_responses_page_maps_every_location_with_hover_links_to_the_rows(): void
    {
        $survey = $this->survey();
        foreach ([['Alfin', '-7.0453, 112.738', '12'], ['Indah', '-7.0461, 112.7392', '35'], ['Tanpa lokasi', '', '']] as [$name, $location, $accuracy]) {
            $this->post(route('form.store', $survey), ['answers' => ['q-nama' => $name, 'q-lokasi' => $location, 'q-lokasi__akurasi' => $accuracy]])->assertRedirect();
        }
        [$alfin, $indah] = $survey->responses()->oldest('id')->get();

        $page = $this->get(route('surveys.responses.index', $survey))->assertOk()
            ->assertSee('leaflet.min.js', false)
            ->assertSee('data-point-id="'.$indah->id.'"', false);

        preg_match('/data-map-points="([^"]*)"/', $page->getContent(), $match);
        $points = json_decode(html_entity_decode($match[1]), true);
        $this->assertCount(2, $points);
        // Newest first, numbered like the table's "No"; the name is the first short answer.
        $this->assertSame(['id' => $indah->id, 'number' => 2, 'lat' => -7.0461, 'lng' => 112.7392, 'accuracy' => 35, 'label' => 'Indah'], array_intersect_key($points[0], array_flip(['id', 'number', 'lat', 'lng', 'accuracy', 'label'])));
        $this->assertSame(route('surveys.responses.show', [$survey, $alfin]), $points[1]['url']);
    }

    public function test_uploads_are_served_even_without_the_public_storage_link(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/1/foto.jpg', 'gambar');

        $this->get('/storage/uploads/1/foto.jpg')->assertOk()->assertHeader('Cache-Control', 'max-age=604800, public');
        $this->get('/storage/uploads/1/tidak-ada.jpg')->assertNotFound();
        $this->get('/storage/rahasia.txt')->assertNotFound();
    }
}
