<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use ConsultDesk\Http\Validation\ValidationFailed;
use GdImage;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Uploaded images (docs/PLAN.md §10): only real PNG, JPEG or WebP files up to 2 MB are accepted;
 * each is decoded and re-encoded with GD, which drops metadata and anything smuggled inside, then
 * stored under a random name outside the web root and served by GET /api/media/{name}.
 */
final class ImageStore
{
    public const NAME = '[0-9a-f]{32}\.(?:webp|png)';
    private const MAX_BYTES = 2 * 1024 * 1024;
    /** About 64 MB once decoded, so a small but huge-dimension file can't exhaust PHP's memory. */
    private const MAX_SOURCE_PIXELS = 16_000_000;
    private const ACCEPTED = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    public function __construct(private readonly string $directory) {}

    /**
     * A logo: scaled down (never up) to fit 1200×400.
     *
     * @throws ValidationFailed
     */
    public function storeLogo(UploadedFileInterface $file): string
    {
        $image = $this->decode($file);
        [$width, $height] = self::fit(imagesx($image), imagesy($image), 1200, 400);

        return $this->save(self::resample($image, 0, 0, imagesx($image), imagesy($image), $width, $height));
    }

    /**
     * A portrait: the centre square, at most 600×600.
     *
     * @throws ValidationFailed
     */
    public function storePhoto(UploadedFileInterface $file): string
    {
        $image = $this->decode($file);
        $side = min(imagesx($image), imagesy($image));
        $size = min($side, 600);

        return $this->save(self::resample($image, intdiv(imagesx($image) - $side, 2), intdiv(imagesy($image) - $side, 2), $side, $side, $size, $size));
    }

    /**
     * Full path of a stored image, or null for an unknown or malformed name.
     */
    public function path(string $name): ?string
    {
        if (preg_match('/^' . self::NAME . '$/', $name) !== 1) {
            return null;
        }
        $path = $this->directory . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public function delete(?string $name): void
    {
        $path = $name === null ? null : $this->path($name);
        if ($path !== null) {
            unlink($path);
        }
    }

    /**
     * @throws ValidationFailed
     */
    private function decode(UploadedFileInterface $file): GdImage
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationFailed(['file' => 'The upload did not arrive. Try again with an image under 2 MB.']);
        }
        if (($file->getSize() ?? self::MAX_BYTES + 1) > self::MAX_BYTES) {
            throw new ValidationFailed(['file' => 'Use an image under 2 MB.']);
        }
        $bytes = (string) $file->getStream();
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ValidationFailed(['file' => 'Use an image under 2 MB.']);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            throw new ValidationFailed(['file' => 'Use a PNG, JPEG or WebP image.']);
        }
        if ($info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            throw new ValidationFailed(['file' => 'That image is too large. Use one under 16 megapixels.']);
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof GdImage) {
            throw new ValidationFailed(['file' => 'That image could not be read.']);
        }

        return $info[2] === IMAGETYPE_JPEG ? self::upright($image, $bytes) : $image;
    }

    /**
     * Phones save portraits sideways with an EXIF note to rotate them; re-encoding drops the note,
     * so apply it first. Skipped quietly when PHP's exif extension is missing.
     */
    private static function upright(GdImage $image, string $jpeg): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($jpeg));
        $angle = match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        $rotated = $angle === 0 ? false : imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * @return array{int, int}
     */
    private static function fit(int $width, int $height, int $maxWidth, int $maxHeight): array
    {
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private static function resample(GdImage $source, int $x, int $y, int $width, int $height, int $toWidth, int $toHeight): GdImage
    {
        $target = imagecreatetruecolor(max(1, $toWidth), max(1, $toHeight));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, $x, $y, $toWidth, $toHeight, $width, $height);

        return $target;
    }

    private function save(GdImage $image): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the media directory.');
        }
        $webp = function_exists('imagewebp');
        $name = bin2hex(random_bytes(16)) . ($webp ? '.webp' : '.png');
        $path = $this->directory . '/' . $name;
        $ok = $webp ? imagewebp($image, $path, 85) : imagepng($image, $path, 9);
        if (!$ok) {
            throw new RuntimeException('Could not save the image.');
        }

        return $name;
    }
}
