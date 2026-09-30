<?php

namespace App\Support;

use App\Models\Survey;
use Illuminate\Support\Str;

/**
 * Starting points for a new questionnaire, covering the common kinds of BPS activities.
 */
class SurveyTemplates
{
    public const DEFAULT = 'blank';

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function all(): array
    {
        return [
            'blank' => ['label' => 'Kosong', 'description' => 'Mulai dari satu blok kosong dan susun sendiri.'],
            'household' => ['label' => 'Survei rumah tangga / wilayah', 'description' => 'Blok identitas wilayah berkode dan keterangan pendataan.'],
            'business' => ['label' => 'Registrasi usaha', 'description' => 'Karakteristik tempat, pengusaha, dan usaha; tabel pekerja; blok petugas dan catatan.'],
            'listing' => ['label' => 'Listing', 'description' => 'Tabel daftar keluarga/usaha yang diisi baris demi baris.'],
            'evaluation' => ['label' => 'Evaluasi / kepuasan kegiatan', 'description' => 'Identitas responden, skala penilaian 1–5, dan saran.'],
            'registration' => ['label' => 'Pendaftaran / daftar hadir', 'description' => 'Nama, NIP, unit kerja, dan kontak peserta.'],
        ];
    }

    public static function exists(?string $template): bool
    {
        return $template !== null && array_key_exists($template, self::all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function blocks(string $template): array
    {
        $satisfaction = ['Sangat tidak puas', 'Tidak puas', 'Cukup', 'Puas', 'Sangat puas'];

        return match ($template) {
            'household' => [
                self::block('IDENTITAS WILAYAH', [
                    self::region('Identitas wilayah', ['provinsi', 'kabupaten', 'kecamatan', 'desa', 'klasifikasi', 'sls_kode', 'sls_nama']),
                    self::question('single', 'Apakah terdapat perubahan wilayah?', required: true, options: ['Ya', 'Tidak']),
                    self::question('short', 'Nama kepala rumah tangga', required: true),
                    self::question('long', 'Alamat'),
                ]),
                self::block('KETERANGAN PENDATAAN', [
                    self::question('short', 'Nama pendata', required: true),
                    self::question('date', 'Tanggal pendataan', required: true),
                ]),
            ],
            'business' => [
                self::block('KARAKTERISTIK TEMPAT USAHA', [
                    self::question('short', 'Nama usaha/perusahaan', required: true),
                    self::question('long', 'Alamat tempat usaha', required: true),
                    self::question('short', 'Koordinat lokasi (lintang, bujur)', description: 'Contoh: -7.0455, 112.7351'),
                ]),
                self::block('KARAKTERISTIK PENGUSAHA', [
                    self::question('short', 'Nama', required: true),
                    self::question('code', 'NIK', codeLength: 16),
                    self::question('single', 'Jenis kelamin', required: true, codeLength: 1, options: ['Laki-laki', 'Perempuan']),
                    self::question('date', 'Tanggal lahir'),
                    self::question('phone', 'Nomor WhatsApp'),
                    self::question('single', 'Pendidikan terakhir', codeLength: 1, options: ['SD/sederajat', 'SMP/sederajat', 'SMA/sederajat', 'Perguruan tinggi']),
                ]),
                self::block('KARAKTERISTIK USAHA', [
                    self::question('long', 'Kegiatan utama usaha', required: true, description: 'Tuliskan selengkap-lengkapnya, dimulai dengan kata kerja, meliputi input, proses, output.'),
                    self::question('single', 'Skala usaha', required: true, codeLength: 1, options: ['Usaha mikro', 'Usaha kecil', 'Usaha menengah']),
                    self::table('Banyaknya pekerja', 'fixed', [
                        ['label' => 'Laki-laki', 'type' => 'number'],
                        ['label' => 'Perempuan', 'type' => 'number'],
                        ['label' => 'Total', 'type' => 'number'],
                    ], rows: ['Dibayar', 'Tidak dibayar', 'Total pekerja']),
                    self::question('currency', 'Modal usaha', description: 'Dalam rupiah.'),
                    self::question('currency', 'Omzet setahun terakhir', description: 'Dalam rupiah.'),
                ]),
                self::block('KETERANGAN PETUGAS', [
                    self::question('date', 'Tanggal pendataan'),
                    self::question('short', 'Nama PPL'),
                    self::question('date', 'Tanggal pemeriksaan'),
                    self::question('short', 'Nama PML'),
                ], layout: 'half'),
                self::block('CATATAN', [
                    self::question('long', 'Catatan'),
                ], layout: 'half'),
            ],
            'listing' => [
                self::block('KETERANGAN WILAYAH', [
                    self::region('Wilayah pencacahan', ['kecamatan', 'desa', 'sls_nama']),
                ]),
                self::block('DAFTAR KELUARGA', [
                    self::table('Daftar keluarga', 'dynamic', [
                        ['label' => 'Dusun', 'type' => 'text'],
                        ['label' => 'Alamat', 'type' => 'text'],
                        ['label' => 'No urut keluarga', 'type' => 'number'],
                        ['label' => 'Nama kepala keluarga', 'type' => 'text'],
                        ['label' => 'Jenis kelamin', 'type' => 'choice', 'options' => ['Laki-laki', 'Perempuan']],
                        ['label' => 'No KK', 'type' => 'text'],
                        ['label' => 'Jumlah anggota keluarga', 'type' => 'number'],
                        ['label' => 'Tag lokasi (lintang, bujur)', 'type' => 'text'],
                        ['label' => 'Ada anggota keluarga yang mengelola UMKM?', 'type' => 'choice', 'options' => ['Ya', 'Tidak']],
                    ], required: true),
                ]),
            ],
            'evaluation' => [
                self::block('IDENTITAS RESPONDEN', [
                    self::question('short', 'Nama'),
                    self::question('short', 'Instansi/unit kerja', required: true),
                ]),
                self::block('PENILAIAN', [
                    self::question('info', 'Petunjuk penilaian', description: 'Pilih satu jawaban yang paling sesuai dengan pengalaman Anda.'),
                    self::question('single', 'Kepuasan terhadap materi', required: true, codeLength: 1, options: $satisfaction),
                    self::question('single', 'Kepuasan terhadap pelaksanaan', required: true, codeLength: 1, options: $satisfaction),
                    self::question('long', 'Saran dan masukan'),
                ]),
            ],
            'registration' => [
                self::block('DATA PESERTA', [
                    self::question('short', 'Nama lengkap', required: true),
                    self::question('short', 'NIP/NIK'),
                    self::question('short', 'Unit kerja/instansi', required: true),
                    self::question('phone', 'Nomor HP'),
                    self::question('email', 'Email'),
                ]),
            ],
            default => [self::block('KETERANGAN UMUM', [])],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private static function block(string $title, array $questions, string $layout = 'full'): array
    {
        return ['id' => self::id('b'), 'title' => $title, 'layout' => $layout, 'condition' => null, 'questions' => $questions];
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private static function question(string $type, string $label, bool $required = false, int $codeLength = 0, array $options = [], string $description = ''): array
    {
        return [
            'id' => self::id('q'),
            'type' => $type,
            'label' => $label,
            'description' => $description,
            'required' => $required,
            'code_length' => $codeLength,
            'options' => $options,
            'condition' => null,
        ];
    }

    /**
     * @param  list<string>  $enabledFields
     * @return array<string, mixed>
     */
    private static function region(string $label, array $enabledFields): array
    {
        return self::question('region', $label, required: true) + [
            'region' => [
                'fields' => collect(Survey::REGION_FIELDS)->map(fn (string $fieldLabel, string $key): array => [
                    'key' => $key,
                    'label' => $fieldLabel,
                    'enabled' => in_array($key, $enabledFields, true),
                    'mode' => 'input',
                    'code' => '',
                    'name' => '',
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @param  list<array{label: string, type: string, options?: list<string>}>  $columns
     * @param  list<string>  $rows
     * @return array<string, mixed>
     */
    private static function table(string $label, string $mode, array $columns, array $rows = [], bool $required = false): array
    {
        return self::question('table', $label, required: $required) + [
            'table' => [
                'mode' => $mode,
                'max_rows' => 50,
                'initial_rows' => $mode === 'dynamic' ? 10 : 1,
                'rows' => $rows,
                'columns' => array_map(fn (array $column): array => [
                    'id' => self::id('c'),
                    'label' => $column['label'],
                    'type' => $column['type'],
                    'options' => $column['options'] ?? [],
                ], $columns),
            ],
        ];
    }

    private static function id(string $prefix): string
    {
        return $prefix.'-'.Str::lower(Str::random(8));
    }
}
