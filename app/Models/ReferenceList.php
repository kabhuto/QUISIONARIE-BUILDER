<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A table of reference data (e.g. kode dan nama desa per kecamatan) that dropdowns can take their
 * options from, optionally filtered by an earlier answer for cascading choices.
 */
#[Fillable(['name', 'slug', 'columns', 'rows'])]
class ReferenceList extends Model
{
    public const MAX_ROWS = 20000;

    public const MAX_COLUMNS = 20;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'rows' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReferenceList $list) {
            if ($list->slug) {
                return;
            }
            $base = Str::slug($list->name) ?: 'referensi';
            $slug = $base;
            for ($index = 2; static::query()->where('slug', $slug)->exists(); $index++) {
                $slug = $base.'-'.$index;
            }
            $list->slug = $slug;
        });
    }

    /**
     * Reads a CSV (comma or semicolon separated, first line = column names).
     *
     * @return array{columns: list<string>, rows: list<array<string, string>>}
     */
    public static function parseCsv(string $contents): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\n|\r/', trim($contents)) ?: [];
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Berkas CSV membutuhkan baris judul kolom dan minimal satu baris data.');
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $columns = array_map(fn ($column): string => trim((string) $column), str_getcsv($lines[0], $delimiter, '"', ''));

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $rows[] = array_combine($columns, array_map(fn (int $index): string => trim((string) ($cells[$index] ?? '')), array_keys($columns)));
        }

        self::assertTable($columns, $rows);

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public static function assertTable(array $columns, array $rows): void
    {
        if ($columns === [] || in_array('', $columns, true) || count(array_unique($columns)) < count($columns) || count($columns) > self::MAX_COLUMNS) {
            throw new InvalidArgumentException('Judul kolom harus terisi, tidak kembar, dan maksimal '.self::MAX_COLUMNS.' kolom.');
        }
        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Data referensi membutuhkan 1 sampai '.self::MAX_ROWS.' baris.');
        }
    }

    /**
     * Options as {value, label}, optionally only the rows whose filter column equals $filterValue.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(string $valueColumn, string $labelColumn, ?string $filterColumn = null, ?string $filterValue = null): array
    {
        $options = [];
        $seen = [];

        foreach ($this->rows as $row) {
            if ($filterColumn !== null && $filterColumn !== '' && (string) ($row[$filterColumn] ?? '') !== (string) $filterValue) {
                continue;
            }
            $value = (string) ($row[$valueColumn] ?? '');
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $label = (string) ($row[$labelColumn] ?? '');
            $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
        }

        return $options;
    }
}
