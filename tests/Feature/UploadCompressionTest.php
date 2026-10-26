<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\UploadCompressor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class UploadCompressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeSurvey(): Survey
    {
        $file = fn (string $id, string $accept): array => ['id' => $id, 'type' => 'file', 'label' => $id, 'required' => false, 'code_length' => 0, 'options' => [], 'file' => ['accept' => $accept, 'max_mb' => 10]];

        return Survey::query()->create([
            'title' => 'Absensi', 'form_code' => 'ABS', 'activity' => 'Uji coba', 'settings' => Survey::DEFAULT_SETTINGS,
            'blocks' => [['id' => 'b-1', 'title' => 'BUKTI', 'layout' => 'full', 'condition' => null, 'questions' => [$file('q-foto', 'image'), $file('q-dok', 'document')]]],
            'published_at' => now(),
        ]);
    }

    /**
     * @param  array<string, UploadedFile>  $files
     * @return array<string, mixed>
     */
    private function submit(array $files): array
    {
        $survey = $this->makeSurvey();
        $this->post(route('form.store', $survey), ['answers' => $files])->assertRedirect(route('form.thanks', $survey));

        return SurveyResponse::query()->sole()->answers;
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rk-test');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * A DOCX-like package stored without compression, optionally with a digital signature part.
     */
    private function docx(bool $signed): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rk-docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', str_repeat('<w:p><w:r><w:t>Laporan kegiatan magang</w:t></w:r></w:p>', 2000));
        if ($signed) {
            $zip->addFromString('_xmlsignatures/sig1.xml', '<Signature/>');
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $zip->setCompressionIndex($index, ZipArchive::CM_STORE);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    public function test_large_photos_are_scaled_down_and_reencoded(): void
    {
        $image = imagecreatetruecolor(3000, 2000);
        for ($x = 0; $x < 3000; $x += 10) {
            imagefilledrectangle($image, $x, 0, $x + 9, 1999, imagecolorallocate($image, $x % 256, (int) ($x / 12) % 256, 120));
        }
        ob_start();
        imagejpeg($image, null, 100);
        $original = (string) ob_get_clean();

        $path = $this->submit(['q-foto' => $this->upload('kegiatan.jpg', $original)])['q-foto'];

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([UploadCompressor::MAX_IMAGE_SIDE, 1280], [$width, $height]);
        $this->assertLessThan(strlen($original), Storage::disk('public')->size($path));
        $this->assertFalse(UploadCompressor::isSignedPath($path));
    }

    public function test_office_documents_are_repacked_smaller(): void
    {
        $original = $this->docx(false);

        $path = $this->submit(['q-dok' => $this->upload('laporan.docx', $original)])['q-dok'];

        $this->assertLessThan(strlen($original) / 5, Storage::disk('public')->size($path));
        $this->assertFalse(UploadCompressor::isSignedPath($path));
    }

    public function test_electronically_signed_documents_are_stored_unchanged(): void
    {
        $pdf = "%PDF-1.7\n1 0 obj << /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 100 200 50] /Contents <00> >> endobj\n%%EOF\n";
        $docx = $this->docx(true);

        $survey = $this->makeSurvey();
        $this->post(route('form.store', $survey), ['answers' => ['q-dok' => $this->upload('surat.pdf', $pdf)]])->assertRedirect();
        $this->post(route('form.store', $survey), ['answers' => ['q-dok' => $this->upload('surat.docx', $docx)]])->assertRedirect();

        [$signedPdf, $signedDocx] = SurveyResponse::query()->orderBy('id')->pluck('answers')->pluck('q-dok')->all();
        $this->assertTrue(Survey::isUploadPath($signedPdf));
        $this->assertTrue(UploadCompressor::isSignedPath($signedPdf));
        $this->assertSame($pdf, Storage::disk('public')->get($signedPdf));
        $this->assertTrue(UploadCompressor::isSignedPath($signedDocx));
        $this->assertSame($docx, Storage::disk('public')->get($signedDocx));

        // An ordinary PDF that merely mentions a signature line is not treated as signed.
        $this->assertFalse(UploadCompressor::isSigned($this->upload('biasa.pdf', "%PDF-1.4\n(Tanda tangan) Tj\n%%EOF")->getRealPath(), 'pdf'));
    }
}
