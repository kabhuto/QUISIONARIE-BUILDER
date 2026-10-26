<?php

namespace Tests\Feature;

use App\Jobs\SendResponseToGoogleDrive;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Support\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_APP = 'https://script.google.com/macros/s/AKfycbTest_123/exec';

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function survey(string $driveUrl = self::WEB_APP): Survey
    {
        $question = fn (string $id, string $type, string $label, array $extra = []): array => ['id' => $id, 'type' => $type, 'label' => $label, 'required' => false, 'code_length' => 0, 'options' => [], ...$extra];

        return Survey::query()->create([
            'title' => 'Absensi Magang', 'form_code' => 'ABS', 'activity' => 'Uji coba',
            'settings' => ['drive_url' => $driveUrl] + Survey::DEFAULT_SETTINGS,
            'blocks' => [['id' => 'b-1', 'title' => 'ABSEN', 'layout' => 'full', 'condition' => null, 'questions' => [
                $question('q-nama', 'short', 'Nama'),
                $question('q-lokasi', 'location', 'Lokasi'),
                $question('q-foto', 'file', 'Foto kegiatan', ['file' => ['accept' => 'image', 'max_mb' => 5]]),
                $question('q-ttd', 'signature', 'Tanda tangan'),
            ]]],
            'published_at' => now(),
        ]);
    }

    public function test_the_folder_link_gives_a_script_with_the_folder_and_key(): void
    {
        $id = GoogleDrive::folderId('https://drive.google.com/drive/folders/1mCT68HVd0f3Ijx1Gk9MQfbMlVMDoKOjx?usp=sharing');
        $this->assertSame('1mCT68HVd0f3Ijx1Gk9MQfbMlVMDoKOjx', $id);
        $this->assertNull(GoogleDrive::folderId('https://drive.google.com/'));

        $survey = $this->survey('');
        $this->get(route('google-drive.show', [$survey, 'folder' => 'https://drive.google.com/drive/folders/1mCT68HVd0f3Ijx1Gk9MQfbMlVMDoKOjx?usp=sharing']))
            ->assertOk()
            ->assertSee("const FOLDER_ID = '1mCT68HVd0f3Ijx1Gk9MQfbMlVMDoKOjx';")
            ->assertSee("const KUNCI = '".GoogleDrive::secret()."';");
    }

    public function test_linking_tests_the_web_app_and_keeps_its_address(): void
    {
        Http::fake([self::WEB_APP => Http::response(['ok' => true, 'folder' => 'Absen Magang', 'folder_url' => 'https://drive.google.com/drive/folders/x'])]);
        $survey = $this->survey('');

        $this->put(route('google-drive.update', $survey), ['drive_url' => 'https://contoh.test/exec'])->assertSessionHasErrors('drive_url');
        $this->put(route('google-drive.update', $survey), ['drive_url' => self::WEB_APP])
            ->assertSessionHas('status', 'Tersambung ke folder Google Drive "Absen Magang". Respons baru akan disalin otomatis.');

        $this->assertSame(self::WEB_APP, $survey->fresh()->formSettings()['drive_url']);
        Http::assertSent(fn (Request $request): bool => $request['ping'] === true && $request['key'] === GoogleDrive::secret());
    }

    public function test_a_new_response_is_queued_for_the_drive_folder(): void
    {
        Queue::fake();
        $survey = $this->survey();

        $this->post(route('form.store', $survey), ['answers' => ['q-nama' => 'Rara']])->assertRedirect(route('form.thanks', $survey));

        $response = SurveyResponse::query()->sole();
        $this->assertSame(['status' => 'pending'], $response->drive);
        Queue::assertPushed(SendResponseToGoogleDrive::class, fn (SendResponseToGoogleDrive $job): bool => $job->responseId === $response->id);

        // Without a linked folder nothing is queued.
        Queue::fake();
        $other = $this->survey('');
        $this->post(route('form.store', $other), ['answers' => ['q-nama' => 'Bima']]);
        Queue::assertNothingPushed();
        $this->assertNull($other->responses()->sole()->drive);
    }

    public function test_files_signatures_and_the_recap_row_are_sent_and_the_links_kept(): void
    {
        Queue::fake();
        $survey = $this->survey();
        $this->post(route('form.store', $survey), ['answers' => [
            'q-nama' => 'Rara', 'q-lokasi' => '-7.0453, 112.738', 'q-lokasi__akurasi' => '12',
            'q-foto' => UploadedFile::fake()->image('kegiatan.jpg', 40, 40), 'q-ttd' => self::SIGNATURE,
        ]])->assertRedirect();
        $response = SurveyResponse::query()->sole();

        Http::fake([self::WEB_APP => Http::response([
            'ok' => true, 'folder_url' => 'https://drive.google.com/drive/folders/hari', 'sheet_url' => 'https://drive.google.com/drive/folders/rekap',
            'files' => ['q-foto' => 'https://drive.google.com/file/d/foto', 'q-ttd' => 'https://drive.google.com/file/d/ttd'],
        ])]);
        (new SendResponseToGoogleDrive($response->id))->handle();

        Http::assertSent(function (Request $request) use ($response): bool {
            $files = collect($request['files'])->keyBy('key');
            $row = collect($request['row'])->mapWithKeys(fn (array $cell): array => [$cell[0] => $cell]);

            return $request['key'] === GoogleDrive::secret()
                && $request['survey']['sheet'] === 'Rekap - Absensi Magang'
                && $request['response']['id'] === $response->id
                && $files['q-foto']['mime'] === 'image/jpeg' && $files['q-foto']['name'] === "R{$response->id}_103_foto-kegiatan.jpg"
                && $files['q-ttd']['name'] === "R{$response->id}_104_tanda-tangan.png"
                && $row['101. Nama'][1] === 'Rara'
                && $row['102. Lokasi'][1] === '-7.0453, 112.738 (±12 m)'
                && $row['103. Foto kegiatan'][2] === ['q-foto'];
        });

        $drive = $response->fresh()->drive;
        $this->assertSame('sent', $drive['status']);
        $this->assertSame('https://drive.google.com/file/d/foto', $drive['files']['q-foto']);
        $this->get(route('surveys.responses.show', [$survey, $response]))->assertSee('Folder berkas');
    }

    public function test_a_failed_copy_is_shown_and_can_be_sent_again(): void
    {
        $survey = $this->survey();
        $response = $survey->responses()->create(['answers' => ['q-nama' => 'Rara']]);

        Http::fake([self::WEB_APP => Http::sequence()
            ->push(['ok' => false, 'error' => 'Kunci tidak cocok.'])
            ->push(['ok' => true, 'files' => []])]);
        $this->assertFalse(GoogleDrive::send($survey, $response));
        $this->assertSame(['failed', 'Kunci tidak cocok.'], [$response->fresh()->drive['status'], $response->fresh()->drive['error']]);
        $this->get(route('surveys.responses.show', [$survey, $response]))->assertSee('Kunci tidak cocok.')->assertSee('Kirim sekarang');

        $this->post(route('google-drive.send', [$survey, $response]))->assertSessionHas('status', 'Respons tersalin ke Google Drive.');
        $this->assertSame('sent', $response->fresh()->drive['status']);
    }

    public function test_saving_the_builder_keeps_the_drive_address(): void
    {
        $survey = $this->survey();
        $payload = [
            'title' => 'Absensi Magang', 'form_code' => 'ABS', 'activity' => 'Uji coba',
            'settings' => ['paper_size' => 'A4', 'orientation' => 'portrait', 'period_type' => 'none', 'numbering' => 'block', 'theme' => 'blue', 'confidential' => '0', 'drive_url' => self::WEB_APP],
            'header' => json_encode(['items' => []]),
            'blocks' => json_encode($survey->blocks),
        ];

        $this->put(route('surveys.update', $survey), $payload)->assertSessionHasNoErrors();
        $this->assertSame(self::WEB_APP, $survey->fresh()->formSettings()['drive_url']);
    }
}
