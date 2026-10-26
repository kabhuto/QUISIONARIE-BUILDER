<?php

namespace App\Support;

use ZipArchive;

/**
 * A minimal .xlsx writer (one sheet) for exports that open in Excel, Google Sheets and LibreOffice:
 * bold, frozen header row with a filter, sensible column widths, numbers as numbers and web
 * addresses as clickable links. Enough for response tables without a spreadsheet library.
 */
class Xlsx
{
    /**
     * Writes the workbook to $path.
     *
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function write(string $path, string $sheetName, array $header, iterable $rows): void
    {
        $widths = array_map(fn (string $title): int => min(60, max(10, mb_strlen($title) + 2)), $header);
        $xmlRows = [self::row(1, $header, true, $widths)];
        $number = 1;
        foreach ($rows as $row) {
            $xmlRows[] = self::row(++$number, array_values($row), false, $widths);
        }

        $lastColumn = self::column(max(1, count($header)) - 1);
        $cols = implode('', array_map(fn (int $width, int $index): string => '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>', $widths, array_keys($widths)));

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'<autoFilter ref="A1:'.$lastColumn.$number.'"/>'
            .'</worksheet>';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape(self::sheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'.self::escape("'".self::sheetName($sheetName)."'").'!$A$1:$'.$lastColumn.'$'.$number.'</definedName></definedNames>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        // Styles: 0 plain (wrapped, top), 1 header (bold, shaded), 2 link (blue, underlined).
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><u/><sz val="11"/><color rgb="FF1155CC"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2EEEA"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    /**
     * @param  list<string|int|float|null>  $values
     * @param  list<int>  $widths
     */
    private static function row(int $number, array $values, bool $header, array &$widths): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            $reference = self::column($index).$number;
            $text = self::clean((string) ($value ?? ''));
            if (! $header) {
                $longestLine = max(array_map('mb_strlen', explode("\n", $text)) ?: [0]);
                $widths[$index] = min(60, max($widths[$index] ?? 10, $longestLine + 2));
            }

            if ($text === '') {
                continue;
            }
            if ($header) {
                $cells .= '<c r="'.$reference.'" s="1" t="inlineStr"><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
            } elseif (is_int($value) || is_float($value) || preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $text) === 1) {
                // Plain numbers stay numbers; codes with leading zeros stay text.
                $cells .= '<c r="'.$reference.'"><v>'.$text.'</v></c>';
            } elseif (preg_match('~^https?://\S+$~', $text) === 1 && strlen($text) <= 255) {
                $cells .= '<c r="'.$reference.'" s="2" t="str"><f>'.self::escape('HYPERLINK("'.str_replace('"', '""', $text).'")').'</f><v>'.self::escape($text).'</v></c>';
            } else {
                $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
            }
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    /**
     * Column letters: 0 → A, 25 → Z, 26 → AA.
     */
    public static function column(int $index): string
    {
        $letters = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(65 + ($index - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function sheetName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/[\[\]:*?\/\\\\\']/', ' ', $name) ?? '') ?? '');

        return $name !== '' ? rtrim(mb_substr($name, 0, 31)) : 'Respons';
    }

    /**
     * Excel cells hold at most 32,767 characters and no control characters.
     */
    private static function clean(string $text): string
    {
        return mb_substr(preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '', 0, 32000);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
