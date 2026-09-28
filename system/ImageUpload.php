<?php
/**
 * Safe product image uploads.
 *  - only JPEG / PNG / WebP, detected from the file CONTENT (finfo), not the name or browser type
 *  - must decode as an image (getimagesize) and be at most 4000×4000 px, 2 MB
 *  - stored under a random name with an extension chosen by us, so the original name never matters
 *  - the uploads folder only serves image extensions and never runs scripts (.htaccess)
 */
declare(strict_types=1);

final class ImageUpload
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_SIDE  = 4000;
    public const TYPES     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function dir(): string
    {
        return ROOT_PATH . '/assets/uploads/products';
    }

    /** Public URL for a stored filename, or null. */
    public static function url(?string $filename): ?string
    {
        return self::isValidName($filename)
            ? url('assets/uploads/products/' . rawurlencode($filename))
            : null;
    }

    /** True if no file was chosen in this <input type="file">. */
    public static function isEmpty(mixed $file): bool
    {
        return !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
    }

    /**
     * Validate and store one uploaded file ($_FILES['image']). Returns the new filename.
     * @throws HttpException 422 with a message for the user
     */
    public static function store(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($error)) {
            throw new HttpException(422, 'Upload one image at a time.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(422, 'The image is too large. Maximum size is 2 MB.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The image could not be uploaded. Please try again.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new HttpException(422, 'The image could not be uploaded. Please try again.');
        }

        $size = filesize($tmp);
        if ($size === false || $size === 0) {
            throw new HttpException(422, 'The image file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new HttpException(422, 'The image is too large. Maximum size is 2 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            throw new HttpException(422, 'Only JPG, PNG or WebP images are allowed.');
        }

        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            throw new HttpException(422, 'That file is not a valid image.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new HttpException(422, 'The image must be at most ' . self::MAX_SIDE . '×' . self::MAX_SIDE . ' pixels.');
        }

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Upload folder is missing: ' . $dir);
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new RuntimeException('Could not move uploaded file to ' . $dir);
        }
        @chmod($dir . '/' . $name, 0644);

        return $name;
    }

    /**
     * Delete an uploaded image. Only random-hex names are deleted: anything else is ignored,
     * including the bundled sample-*.png files (database.sql still refers to them).
     */
    public static function delete(?string $filename): void
    {
        if (is_string($filename) && preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/', $filename) === 1) {
            $path = self::dir() . '/' . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Our filenames only: random hex names and the bundled sample-*.png images. No paths. */
    private static function isValidName(?string $filename): bool
    {
        return is_string($filename)
            && preg_match('/^(?:[a-f0-9]{32}|sample-[a-z0-9-]{1,40})\.(?:jpg|png|webp)$/', $filename) === 1;
    }
}
