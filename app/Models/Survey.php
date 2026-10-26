<?php

namespace App\Models;

use App\Support\Piping;
use App\Support\SurveyLogic;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['title', 'form_code', 'subtitle', 'activity', 'settings', 'header', 'intro', 'blocks', 'published_at'])]
class Survey extends Model
{
    public const QUESTION_TYPES = [
        'short' => 'Jawaban singkat',
        'long' => 'Paragraf',
        'single' => 'Pilihan tunggal',
        'multiple' => 'Pilihan lebih dari satu',
        'dropdown' => 'Daftar pilihan',
        'number' => 'Angka',
        'currency' => 'Rupiah',
        'date' => 'Tanggal',
        'time' => 'Jam',
        'email' => 'Email',
        'phone' => 'Nomor telepon',
        'url' => 'Tautan (URL)',
        'code' => 'Isian kode',
        'checkbox' => 'Kotak centang',
        'rating' => 'Rating bintang',
        'scale' => 'Skala linear',
        'slider' => 'Penggeser',
        'matrix' => 'Matriks pilihan',
        'region' => 'Identitas wilayah',
        'table' => 'Tabel / listing',
        'signature' => 'Tanda tangan',
        'list' => 'Daftar nama/isian',
        'calc' => 'Hitungan (rumus)',
        'location' => 'Lokasi (GPS)',
        'file' => 'Unggah berkas/foto',
        'info' => 'Keterangan',
    ];

    /**
     * The answer value of the automatic "Lainnya (sebutkan)" option; its typed text is stored
     * under the question id plus OTHER_SUFFIX.
     */
    public const OTHER_VALUE = 'Lainnya';

    public const OTHER_SUFFIX = '__lainnya';

    /**
     * Where the label of a value chosen from a reference list or API is kept, next to the value.
     */
    public const LABEL_SUFFIX = '__label';

    public const DEFAULT_OTHER_LABEL = 'Lainnya (sebutkan)';

    /**
     * What an upload question accepts: label, file extensions.
     */
    public const FILE_KINDS = [
        'image' => ['label' => 'Foto/gambar', 'extensions' => ['jpg', 'jpeg', 'png', 'webp']],
        'document' => ['label' => 'Dokumen (PDF/Office)', 'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv']],
        'any' => ['label' => 'Foto atau dokumen', 'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv']],
    ];

    public const MAX_FILE_MB = 10;

    /**
     * A block can repeat once per answer of an earlier question (FormGear's nested input / roster):
     * per number given, per option chosen, or per name in a list.
     */
    public const ROSTER_SOURCE_TYPES = ['number', 'multiple', 'list'];

    /**
     * Question types that can sit inside a repeating block.
     */
    public const ROSTER_TYPES = ['short', 'long', 'single', 'multiple', 'dropdown', 'number', 'currency', 'date', 'time', 'email', 'phone', 'url', 'code', 'checkbox', 'rating', 'scale', 'slider', 'calc', 'location', 'info'];

    public const MAX_ROSTER_ROWS = 50;

    /**
     * Stands for the current row's name or number in the texts of a repeating block.
     */
    public const ROW_TOKEN = '{{@baris}}';

    public const MAX_LIST_ITEMS = 50;

    /**
     * Input mask characters: 9 a digit, a a letter, * a letter or digit; anything else is typed as is.
     */
    public const MAX_MASK_LENGTH = 40;

    public const CHOICE_TYPES = ['single', 'multiple', 'dropdown'];

    /**
     * Types answered with a whole number picked from a configured range.
     */
    public const RANGE_TYPES = ['rating', 'scale', 'slider'];

    public const NUMERIC_TYPES = ['number', 'currency', 'calc', ...self::RANGE_TYPES];

    /**
     * Types that hold structured answers, or none at all; they cannot drive display logic.
     */
    public const NON_LOGIC_TYPES = ['region', 'table', 'matrix', 'list', 'info'];

    /**
     * Types without code boxes on the printed sheet.
     */
    public const NO_CODE_TYPES = [...self::NON_LOGIC_TYPES, 'signature', 'location', 'file'];

    /**
     * Types the respondent never types into: their answer is worked out from other answers.
     */
    public const COMPUTED_TYPES = ['calc'];

    public const MAX_CODE_LENGTH = 20;

    public const RANGE_DEFAULTS = [
        'rating' => ['min' => 1, 'max' => 5, 'step' => 1],
        'scale' => ['min' => 1, 'max' => 5, 'step' => 1],
        'slider' => ['min' => 0, 'max' => 100, 'step' => 1],
    ];

    public const RANGE_LIMIT = 1000000;

    /**
     * Ratings and scales show one button per value, so they stay short.
     */
    public const MAX_RANGE_POINTS = 11;

    public const MAX_MATRIX_ROWS = 20;

    public const MAX_MATRIX_COLUMNS = 7;

    public const CHECKBOX_VALUE = 'Ya';

    /**
     * Signatures are stored as PNG data URLs; this caps their length in characters.
     */
    public const MAX_SIGNATURE_LENGTH = 400000;

    public const CONDITION_OPERATORS = [
        'equals' => 'sama dengan',
        'not_equals' => 'tidak sama dengan',
        'contains' => 'berisi',
        'not_contains' => 'tidak berisi',
        'answered' => 'sudah diisi',
        'empty' => 'kosong',
        'greater_than' => 'lebih dari',
        'less_than' => 'kurang dari',
        'greater_or_equal' => 'minimal (≥)',
        'less_or_equal' => 'maksimal (≤)',
        'between' => 'di antara',
        'not_between' => 'di luar rentang',
    ];

    /**
     * Width of a question on the respondent form, as a fraction of the row.
     */
    public const FIELD_WIDTHS = ['full' => 'Penuh', '1/2' => '1/2', '1/3' => '1/3', '2/3' => '2/3', '1/4' => '1/4', '3/4' => '3/4'];

    /**
     * Question types whose answer can be typed or chosen up front (the "Nilai awal" option).
     */
    public const PREFILL_TYPES = ['short', 'long', 'number', 'currency', 'date', 'time', 'email', 'phone', 'code', 'single', 'dropdown', 'location'];

    public const PLACEHOLDER_TYPES = ['short', 'long', 'number', 'currency', 'email', 'phone', 'url', 'dropdown'];

    public const REGION_FIELDS = [
        'provinsi' => 'Provinsi',
        'kabupaten' => 'Kabupaten/Kota',
        'kecamatan' => 'Kecamatan',
        'desa' => 'Desa/Kelurahan',
        'klasifikasi' => 'Klasifikasi desa/kelurahan',
        'sls_kode' => 'Kode SLS/Non-SLS/Sub-SLS',
        'sls_nama' => 'Nama SLS/Non-SLS',
    ];

    public const REGION_CLASSIFICATIONS = ['PERKOTAAN', 'PERDESAAN'];

    public const TABLE_COLUMN_TYPES = [
        'text' => 'Teks',
        'number' => 'Angka',
        'choice' => 'Pilihan',
        'date' => 'Tanggal',
    ];

    public const MAX_TABLE_COLUMNS = 12;

    public const MAX_TABLE_ROWS = 200;

    public const HEADER_SLOTS = ['left' => 'Kiri', 'center' => 'Tengah', 'right' => 'Kanan'];

    public const HEADER_ALIGNMENTS = ['top' => 'Atas', 'center' => 'Tengah', 'bottom' => 'Bawah'];

    public const HEADER_ITEM_TYPES = ['logo' => 'Logo', 'text' => 'Teks', 'badge' => 'Label'];

    public const BADGE_STYLES = [
        'dark' => ['label' => 'Hitam', 'color' => '#111111'],
        'orange' => ['label' => 'Oranye', 'color' => '#f26b1d'],
        'green' => ['label' => 'Hijau', 'color' => '#1f8a4c'],
        'blue' => ['label' => 'Biru', 'color' => '#1f63b0'],
        'outline' => ['label' => 'Garis', 'color' => '#111111'],
    ];

    public const NUMBERING_STYLES = [
        'block' => 'Per blok (101, 102, 201)',
        'sequential' => 'Berurutan (1, 2, 3)',
    ];

    /**
     * Paper sizes in millimetres, portrait orientation (width, height).
     */
    public const PAPER_SIZES = [
        'A4' => [210, 297],
        'F4' => [215, 330],
        'Letter' => [216, 279],
        'Legal' => [216, 356],
    ];

    public const PERIOD_TYPES = [
        'triwulan' => ['label' => 'Triwulan', 'values' => ['1', '2', '3', '4']],
        'semester' => ['label' => 'Semester', 'values' => ['1', '2']],
        'bulan' => ['label' => 'Bulan', 'values' => [
            'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI',
            'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER',
        ]],
        'none' => ['label' => 'Tanpa periode', 'values' => []],
    ];

    /**
     * Accent colours for the respondent form.
     */
    public const THEMES = [
        'orange' => ['label' => 'Oranye', 'color' => '#f26b1d'],
        'teal' => ['label' => 'Hijau', 'color' => '#087e78'],
        'blue' => ['label' => 'Biru', 'color' => '#1f63b0'],
        'purple' => ['label' => 'Ungu', 'color' => '#6a4bb0'],
        'slate' => ['label' => 'Abu', 'color' => '#394a55'],
    ];

    public const DEFAULT_SETTINGS = [
        'paper_size' => 'A4',
        'orientation' => 'portrait',
        'period_type' => 'none',
        'period_value' => null,
        'theme' => 'orange',
        'numbering' => 'block',
        'thank_you_message' => 'Terima kasih, jawaban Anda sudah kami terima.',
        'confidential' => true,
        'sheet_label' => 'RAHASIA',
        'sheet_label_position' => 'left',
        'topline_label' => 'FORMULIR PENDATAAN',
        'topline_label_position' => 'left',
        'form_code_position' => 'right',
        'webhook_url' => '',
    ];

    public const DEFAULT_INTRO = [
        'text' => "Terima kasih atas partisipasi Bapak/Ibu dalam kegiatan ini.\nMohon isi setiap pertanyaan dengan lengkap dan benar.",
        'help_title' => '',
        'help_org' => 'Badan Pusat Statistik',
        'help_phone' => '',
        'help_email' => '',
        'notice' => '',
        'guide' => "Isi blok secara berurutan, atau klik nama blok di panel sebelah kiri untuk langsung menuju blok tertentu.\nJawaban tersimpan sementara di perangkat ini sehingga pengisian dapat dilanjutkan kembali.",
    ];

    protected static function booted(): void
    {
        static::creating(function (Survey $survey) {
            $survey->slug ??= static::uniqueSlug();
        });
    }

    public static function uniqueSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(10));
        } while (static::query()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'header' => 'array',
            'intro' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Blocks with every question's field settings filled in; older "show when" conditions are
     * read as the equivalent hidden-plus-logic settings.
     *
     * @return Attribute<array<int, array<string, mixed>>|null, array<int, array<string, mixed>>>
     */
    /**
     * The page orientation a block is printed in: its own, or the form's when it has none.
     *
     * @param  array<string, mixed>  $block
     */
    public function blockOrientation(array $block): string
    {
        $own = $block['orientation'] ?? '';

        return in_array($own, ['portrait', 'landscape'], true) ? $own : ($this->settings['orientation'] ?? 'portrait');
    }

    protected function blocks(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?array => $value === null ? null : SurveyLogic::upgradeBlocks(json_decode($value, true) ?? []),
            set: fn (array $value): string => json_encode($value),
        );
    }

    /**
     * @return HasMany<SurveyResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @param  Builder<Survey>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function formSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    /**
     * @return array<string, string>
     */
    public function formIntro(): array
    {
        return array_merge(self::DEFAULT_INTRO, $this->intro ?? []);
    }

    /**
     * Kop items (logos, text lines and labels) in their left/centre/right slots.
     *
     * @return list<array<string, mixed>>
     */
    public function headerItems(): array
    {
        return $this->header['items'] ?? self::defaultHeaderItems();
    }

    /**
     * How the left, centre and right kop columns line up vertically.
     */
    public function headerAlign(): string
    {
        return array_key_exists($this->header['align'] ?? '', self::HEADER_ALIGNMENTS) ? $this->header['align'] : 'center';
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function headerSlots(): array
    {
        $slots = array_fill_keys(array_keys(self::HEADER_SLOTS), []);

        foreach ($this->headerItems() as $item) {
            $slots[$item['slot']][] = $item;
        }

        return $slots;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultHeaderItems(): array
    {
        $item = fn (string $type, string $slot, array $attributes = []): array => [
            'id' => 'h-'.Str::lower(Str::random(8)),
            'type' => $type,
            'slot' => $slot,
            'text' => '',
            'style' => $type === 'badge' ? 'dark' : 'bold',
            'path' => null,
            'height' => 64,
            'offset' => 0,
            ...$attributes,
        ];

        return [
            $item('logo', 'left'),
            $item('badge', 'left', ['text' => 'RAHASIA']),
            $item('logo', 'center', ['height' => 72]),
            $item('text', 'center', ['text' => 'REPUBLIK INDONESIA']),
            $item('text', 'center', ['text' => 'BADAN PUSAT STATISTIK']),
            $item('logo', 'right'),
            $item('badge', 'right', ['text' => 'CAWI', 'style' => 'orange']),
        ];
    }

    public function themeColor(): string
    {
        return self::THEMES[$this->formSettings()['theme']]['color'] ?? self::THEMES['orange']['color'];
    }

    public function periodLabel(): ?string
    {
        $settings = $this->formSettings();
        $period = self::PERIOD_TYPES[$settings['period_type']] ?? null;
        $value = $period['values'][((int) $settings['period_value']) - 1] ?? null;

        return $period && $value !== null ? $period['label'].' '.$value : null;
    }

    /**
     * Printed numbers keyed by question id. Information items are not numbered.
     *
     * @return array<string, string>
     */
    public function questionNumbers(): array
    {
        return self::numberQuestions($this->blocks ?? [], $this->formSettings()['numbering']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, string>
     */
    public static function numberQuestions(array $blocks, string $style): array
    {
        $numbers = [];
        $sequence = 0;

        foreach ($blocks as $blockIndex => $block) {
            $withinBlock = 0;

            foreach ($block['questions'] as $question) {
                if ($question['type'] === 'info') {
                    continue;
                }

                $withinBlock++;
                $sequence++;
                $numbers[$question['id']] = $style === 'sequential'
                    ? (string) $sequence
                    : ($blockIndex + 1).str_pad((string) $withinBlock, 2, '0', STR_PAD_LEFT);
            }
        }

        return $numbers;
    }

    /**
     * Every answerable question in form order, with its printed number and block position.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(): array
    {
        $numbers = $this->questionNumbers();
        $questions = [];

        foreach ($this->blocks ?? [] as $blockIndex => $block) {
            $inRoster = SurveyLogic::repeatSource($block) !== null;

            foreach ($block['questions'] as $question) {
                if ($question['type'] === 'info') {
                    continue;
                }

                $questions[] = $question + [
                    'number' => $numbers[$question['id']],
                    'block_index' => $blockIndex,
                    // Questions of a repeating block hold one answer per row.
                    'in_roster' => $inRoster,
                    // The label with answer tags shown as question numbers, for admin pages and messages.
                    'text' => Piping::text($question['label'], $numbers),
                ];
            }
        }

        return $questions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tableQuestions(): array
    {
        return array_values(array_filter($this->questions(), fn (array $question): bool => $question['type'] === 'table'));
    }

    /**
     * The regular expression an input mask such as "99.99.999" or "aa-9999" stands for.
     */
    public static function maskPattern(string $mask): string
    {
        $pattern = '';

        foreach (mb_str_split($mask) as $character) {
            $pattern .= match ($character) {
                '9' => '[0-9]',
                'a' => '[A-Za-z]',
                '*' => '[A-Za-z0-9]',
                default => preg_quote($character, '/'),
            };
        }

        return '/^'.$pattern.'$/u';
    }

    /**
     * The options a choice question offers for the given answers: its own, or the answers chosen
     * on the question it takes them from ("Lainnya" there stands for the text typed with it).
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function optionsFor(array $question, array $answers): array
    {
        $source = $question['options_from'] ?? '';

        if ($source === '') {
            return $question['options'];
        }

        $chosen = array_values(array_filter((array) ($answers[$source] ?? []), fn ($value): bool => is_string($value) && trim($value) !== ''));
        $other = trim((string) ($answers[$source.self::OTHER_SUFFIX] ?? ''));

        return array_values(array_unique(array_map(fn (string $value): string => $value === self::OTHER_VALUE && $other !== '' ? $other : $value, $chosen)));
    }

    /**
     * @param  array<string, mixed>  $question
     */
    public static function hasOtherOption(array $question): bool
    {
        return in_array($question['type'], self::CHOICE_TYPES, true) && ! empty($question['other_option']) && ($question['options_from'] ?? '') === '';
    }

    /**
     * The extensions an upload question accepts.
     *
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    public static function fileExtensions(array $question): array
    {
        return self::FILE_KINDS[$question['file']['accept'] ?? 'image']['extensions'] ?? self::FILE_KINDS['image']['extensions'];
    }

    /**
     * Whether a stored answer is a file uploaded to this survey.
     */
    public static function isUploadPath(mixed $value): bool
    {
        return is_string($value) && preg_match('/^uploads\/\d+\/[A-Za-z0-9_-]+\.[a-z0-9]{2,5}$/', $value) === 1;
    }

    /**
     * The values a rating or scale question offers, lowest first.
     *
     * @param  array<string, mixed>  $question
     * @return list<int>
     */
    public static function rangeValues(array $question): array
    {
        $range = $question['range'] ?? self::RANGE_DEFAULTS[$question['type']];

        return range($range['min'], $range['max'], $range['step']);
    }

    /**
     * Whether a value is a signature as drawn on the respondent form: a PNG data URL.
     */
    public static function isSignatureImage(mixed $value): bool
    {
        $prefix = 'data:image/png;base64,';

        if (! is_string($value) || ! str_starts_with($value, $prefix)) {
            return false;
        }

        $contents = base64_decode(substr($value, strlen($prefix)), true);
        $image = $contents === false ? false : @getimagesizefromstring($contents);

        return $image !== false && $image[2] === IMAGETYPE_PNG;
    }

    /**
     * The enabled fields of a region question.
     *
     * @param  array<string, mixed>  $question
     * @return list<array<string, mixed>>
     */
    public static function regionFields(array $question): array
    {
        return array_values(array_filter($question['region']['fields'] ?? [], fn (array $field): bool => $field['enabled']));
    }

    /**
     * "[35] JAWA TIMUR" for a region field fixed by the survey's author.
     *
     * @param  array<string, mixed>  $field
     */
    public static function fixedRegionValue(array $field): string
    {
        return trim(($field['code'] !== '' ? '['.$field['code'].'] ' : '').$field['name']);
    }

    public static function roman(int $number): string
    {
        $numerals = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
        $result = '';

        foreach ($numerals as $numeral => $value) {
            while ($number >= $value) {
                $result .= $numeral;
                $number -= $value;
            }
        }

        return $result;
    }
}
