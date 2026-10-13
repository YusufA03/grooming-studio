<?php
// Saves pictures uploaded by the administrator (service photos) safely.
//
// What it guarantees:
//  - only JPEG, PNG and WebP pictures are accepted, judged by the file's real
//    contents, never by its name or by what the browser claims
//  - the picture is re-saved (and shrunk to a web-friendly size) so anything
//    hidden inside the original file is thrown away
//  - the file on disk gets a random name that we choose, so a client-supplied
//    name can never be used (no ../ tricks, no .php names)
class ImageUpload
{
    public const MAX_BYTES        = 2097152;    // 2 MB
    public const MIN_SIDE_PX      = 100;        // smaller than this is too tiny to show
    public const MAX_PIXELS       = 12500000;   // about 12 megapixels: bigger needs too much memory to process
    public const OUTPUT_MAX_WIDTH = 900;        // pictures are shrunk to at most this wide

    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    // The folder pictures are saved in (inside public/ so the browser can show them).
    public static function directory(): string
    {
        return __DIR__ . '/../public/uploads/services';
    }

    // Saves one uploaded picture ($file is one entry of PHP's $_FILES).
    // Returns [file name, null] when it worked, [null, null] when nothing was
    // uploaded, and [null, message] when the picture was refused.
    public static function saveFromUpload(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return [null, 'That picture is too big. The limit is 2 MB.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [null, 'The picture could not be uploaded. Please try again.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [null, 'The picture could not be uploaded. Please try again.'];
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0) {
            return [null, 'That file is empty.'];
        }
        if ($size > self::MAX_BYTES) {
            return [null, 'That picture is too big. The limit is 2 MB.'];
        }

        if (!class_exists('finfo')) {
            return [null, 'Picture uploads are not available on this server.'];
        }

        // What is it REALLY? (looked at from the inside, not from its name)
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            return [null, 'Please upload a JPG, PNG or WebP picture.'];
        }
        $extension = self::TYPES[$mime];

        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            return [null, 'That file is not a valid picture.'];
        }
        [$width, $height] = $info;
        if ($width < self::MIN_SIDE_PX || $height < self::MIN_SIDE_PX) {
            return [null, 'That picture is too small. It must be at least ' . self::MIN_SIDE_PX . ' pixels wide and high.'];
        }
        if ($width * $height > self::MAX_PIXELS) {
            return [null, 'That picture has too many pixels. Please shrink it (about 4000 x 3000 at most) and try again.'];
        }

        $folder = self::directory();
        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            error_log('Could not create the pictures folder: ' . $folder);
            return [null, 'The picture could not be saved on the server.'];
        }
        if (!is_writable($folder)) {
            error_log('The pictures folder is not writable: ' . $folder);
            return [null, 'The picture could not be saved on the server.'];
        }

        $name   = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $folder . '/' . $name;

        if (self::canProcess($mime)) {
            if (!self::reencode($tmp, $target, $mime)) {
                return [null, 'That file is not a valid picture.'];
            }
        } else {
            // No image library on this server, so the picture cannot be re-saved
            // clean. Refuse anything with PHP code tucked inside it, then keep
            // the original, which has already passed the checks above.
            $contents = file_get_contents($tmp);
            if ($contents === false || stripos($contents, '<?php') !== false || strpos($contents, '<?=') !== false) {
                return [null, 'That file contains unexpected content, so it was not accepted.'];
            }
            if (!move_uploaded_file($tmp, $target)) {
                return [null, 'The picture could not be saved on the server.'];
            }
            @chmod($target, 0644);
        }

        return [$name, null];
    }

    // Deletes a saved picture. Only ever touches files with a name this class
    // would have created, so a bad value in the database cannot make it delete
    // anything else.
    public static function delete(?string $filename): void
    {
        if ($filename === null || !preg_match(Service::IMAGE_NAME_PATTERN, $filename)) {
            return;
        }
        $path = self::directory() . '/' . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function canProcess(string $mime): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'image/png'  => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default      => false,
        };
    }

    // Loads the picture, turns it the right way up, shrinks it if it is wide,
    // and saves a fresh copy. Returns false if it is not a readable picture.
    private static function reencode(string $source, string $target, string $mime): bool
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png'  => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
            default      => false,
        };
        if ($image === false) {
            return false;
        }

        // Phones store photos sideways and add a note saying how to turn them.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $turn = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($turn !== 0) {
                $rotated = imagerotate($image, $turn, 0);
                if ($rotated !== false) {
                    $image = $rotated;
                }
            }
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        if ($width > self::OUTPUT_MAX_WIDTH) {
            $newHeight = max(1, (int) round($height * self::OUTPUT_MAX_WIDTH / $width));
            $small     = imagecreatetruecolor(self::OUTPUT_MAX_WIDTH, $newHeight);
            if ($mime !== 'image/jpeg') {                       // keep transparent areas transparent
                imagealphablending($small, false);
                imagesavealpha($small, true);
                imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
            }
            imagecopyresampled($small, $image, 0, 0, 0, 0, self::OUTPUT_MAX_WIDTH, $newHeight, $width, $height);
            $image = $small;
        } elseif ($mime !== 'image/jpeg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($image, $target, 85),
            'image/png'  => imagepng($image, $target, 6),
            'image/webp' => imagewebp($image, $target, 85),
            default      => false,
        };
        if ($saved) {
            @chmod($target, 0644);
        }
        return $saved;
    }
}
