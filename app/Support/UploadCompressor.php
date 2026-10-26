<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Stores uploaded answers compressed, so the disk does not fill up: photos are scaled down and
 * re-encoded, Office files are re-zipped at the highest level and PDFs go through Ghostscript
 * when it is installed. A file carrying an electronic signature is stored byte for byte, since
 * any change would break the signature; its name ends in "-ttde" so the response view can say so.
 * The compressed copy is only kept when it is actually smaller.
 */
class UploadCompressor
{
    /** Longest side of a stored photo, in pixels. */
    public const MAX_IMAGE_SIDE = 1920;

    public const IMAGE_QUALITY = 75;

    public const SIGNED_SUFFIX = '-ttde';

    /**
     * Stores the upload in the folder on the public disk and returns its path.
     */
    public static function store(UploadedFile $file, string $directory): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $source = (string) $file->getRealPath();
        $signed = self::isSigned($source, $extension);
        $path = $directory.'/'.Str::lower(Str::random(32)).($signed ? self::SIGNED_SUFFIX : '').'.'.$extension;

        $compressed = $signed ? null : self::compress($source, $extension);
        $keep = $compressed !== null && filesize($compressed) < filesize($source) ? $compressed : $source;

        $stream = fopen($keep, 'rb');
        Storage::disk('public')->put($path, $stream);
        fclose($stream);

        if ($compressed !== null) {
            @unlink($compressed);
        }

        return $path;
    }

    /**
     * Whether a stored answer is a file kept unchanged because it is electronically signed.
     */
    public static function isSignedPath(string $path): bool
    {
        return str_ends_with(pathinfo($path, PATHINFO_FILENAME), self::SIGNED_SUFFIX);
    }

    /**
     * Whether the file carries an electronic signature: a PDF signature dictionary (PAdES/PKCS#7,
     * as BSrE/TTE documents use) or an Office digital signature part.
     */
    public static function isSigned(string $path, string $extension): bool
    {
        if ($extension === 'pdf') {
            $content = (string) file_get_contents($path);

            return str_contains($content, '/ByteRange')
                && preg_match('/\/Type\s*\/Sig\b|\/FT\s*\/Sig\b|adbe\.pkcs7|ETSI\.CAdES|ETSI\.RFC3161/', $content) === 1;
        }

        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                return false;
            }
            $signed = false;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_starts_with((string) $zip->getNameIndex($index), '_xmlsignatures/')) {
                    $signed = true;
                    break;
                }
            }
            $zip->close();

            return $signed;
        }

        if (in_array($extension, ['doc', 'xls'], true)) {
            // Legacy Office keeps the signature in a "_signatures" / "\x05DigitalSignature" stream.
            $content = (string) file_get_contents($path);

            return str_contains($content, mb_convert_encoding('DigitalSignature', 'UTF-16LE'))
                || str_contains($content, mb_convert_encoding('_xmlsignatures', 'UTF-16LE'));
        }

        return false;
    }

    /**
     * A compressed copy of the file in a temporary path, or null when the type cannot be compressed.
     */
    public static function compress(string $path, string $extension): ?string
    {
        try {
            return match ($extension) {
                'jpg', 'jpeg', 'png', 'webp' => self::compressImage($path, $extension),
                'docx', 'xlsx' => self::compressOffice($path),
                'pdf' => self::compressPdf($path),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function compressImage(string $path, string $extension): ?string
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return null;
        }

        // Phone photos are stored sideways with an EXIF turn; re-encoding drops EXIF, so apply it.
        if (in_array($extension, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $angle = [3 => 180, 6 => -90, 8 => 90][@exif_read_data($path)['Orientation'] ?? 1] ?? 0;
            if ($angle !== 0) {
                $image = imagerotate($image, $angle, 0);
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_IMAGE_SIDE / max($width, $height));
        if ($scale < 1) {
            $image = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);
        }

        $target = (string) tempnam(sys_get_temp_dir(), 'rk-img');
        match ($extension) {
            'png' => (function () use ($image, $target): void {
                imagesavealpha($image, true);
                imagepng($image, $target, 9);
            })(),
            'webp' => imagewebp($image, $target, self::IMAGE_QUALITY),
            default => (function () use ($image, $target): void {
                imageinterlace($image, true);
                imagejpeg($image, $target, self::IMAGE_QUALITY);
            })(),
        };
        imagedestroy($image);

        return $target;
    }

    /**
     * DOCX/XLSX are ZIP packages, often saved with light compression: repack at the highest level.
     */
    private static function compressOffice(string $path): ?string
    {
        $target = (string) tempnam(sys_get_temp_dir(), 'rk-doc');
        copy($path, $target);

        $zip = new ZipArchive;
        if ($zip->open($target) !== true) {
            @unlink($target);

            return null;
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $zip->setCompressionIndex($index, ZipArchive::CM_DEFLATE, 9);
        }
        $zip->close();

        return $target;
    }

    /**
     * Ghostscript's "ebook" profile (150 dpi images); skipped when Ghostscript is not installed.
     */
    private static function compressPdf(string $path): ?string
    {
        $ghostscript = self::ghostscript();
        if ($ghostscript === null) {
            return null;
        }

        $target = (string) tempnam(sys_get_temp_dir(), 'rk-pdf');
        $process = new Process([
            $ghostscript, '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.5', '-dPDFSETTINGS=/ebook',
            '-dNOPAUSE', '-dQUIET', '-dBATCH', '-dSAFER', '-sOutputFile='.$target, $path,
        ]);
        $process->setTimeout(60)->run();

        if (! $process->isSuccessful() || filesize($target) === 0) {
            @unlink($target);

            return null;
        }

        return $target;
    }

    /**
     * The Ghostscript program: GHOSTSCRIPT_PATH in .env, or gs / gswin64c on the PATH.
     */
    public static function ghostscript(): ?string
    {
        $configured = (string) config('services.ghostscript.path');
        if ($configured !== '') {
            return is_file($configured) ? $configured : null;
        }

        $finder = new ExecutableFinder;

        return $finder->find('gs') ?? $finder->find('gswin64c') ?? $finder->find('gswin32c');
    }
}
