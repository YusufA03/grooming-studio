<?php
// Saves uploaded pictures safely: the administrator's service pictures, and the
// inspiration photos clients attach to a booking.
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
    // Rules for the administrator's service pictures (shown publicly on the website).
    public const MAX_BYTES        = 2097152;    // 2 MB
    public const MIN_SIDE_PX      = 100;        // smaller than this is too tiny to show
    public const MAX_PIXELS       = 12500000;   // about 12 megapixels: bigger needs too much memory to process
    public const OUTPUT_MAX_WIDTH = 900;        // pictures are shrunk to at most this wide

    // Rules for a client's inspiration photo (private: never shown publicly).
    public const INSPIRATION_MAX_BYTES  = 8388608;    // 8 MB: phone photos are big
    public const INSPIRATION_MAX_PIXELS = 25000000;   // about 25 megapixels
    public const INSPIRATION_MAX_WIDTH  = 1600;       // shrunk to at most this wide

    // The only file names this class ever creates (so a bad value can never point elsewhere).
    public const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)\z/';

    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    // The folder service pictures are saved in (inside public/ so the browser can show them).
    public static function directory(): string
    {
        return __DIR__ . '/../public/uploads/services';
    }

    // The folder inspiration photos are saved in. It is OUTSIDE public/, so the web
    // server never serves these files directly: they are only reachable through
    // public/inspiration_image.php, which checks who is asking.
    public static function inspirationDirectory(): string
    {
        // Only the automated test runner sets these variables (a temporary folder for test photos).
        if (getenv('GS_TEST_MODE') === '1' && getenv('GS_STORAGE_DIR') !== false && getenv('GS_STORAGE_DIR') !== '') {
            return rtrim((string) getenv('GS_STORAGE_DIR'), '/\\');
        }
        return __DIR__ . '/../storage/inspiration';
    }

    // Saves one uploaded service picture ($file is one entry of PHP's $_FILES).
    // Returns [file name, null] when it worked, [null, null] when nothing was
    // uploaded, and [null, message] when the picture was refused.
    public static function saveFromUpload(array $file): array
    {
        return self::process($file, [
            'directory'  => self::directory(),
            'max_bytes'  => self::MAX_BYTES,
            'max_pixels' => self::MAX_PIXELS,
            'max_width'  => self::OUTPUT_MAX_WIDTH,
            'noun'       => 'picture',
            'pixel_hint' => 'Please shrink it (about 4000 x 3000 at most) and try again.',
        ]);
    }

    // The same, for a client's inspiration photo.
    public static function saveInspirationFromUpload(array $file): array
    {
        return self::process($file, [
            'directory'  => self::inspirationDirectory(),
            'max_bytes'  => self::INSPIRATION_MAX_BYTES,
            'max_pixels' => self::INSPIRATION_MAX_PIXELS,
            'max_width'  => self::INSPIRATION_MAX_WIDTH,
            'noun'       => 'photo',
            'pixel_hint' => 'Please choose a smaller photo (a normal phone photo is fine) and try again.',
        ]);
    }

    private static function process(array $file, array $rules): array
    {
        $noun     = $rules['noun'];
        $limit    = (int) round($rules['max_bytes'] / 1048576) . ' MB';
        $tooBig   = "That $noun is too big. The limit is $limit.";
        $tryAgain = "The $noun could not be uploaded. Please try again.";
        $noSave   = "The $noun could not be saved on the server.";

        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return [null, $tooBig];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [null, $tryAgain];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [null, $tryAgain];
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0) {
            return [null, 'That file is empty.'];
        }
        if ($size > $rules['max_bytes']) {
            return [null, $tooBig];
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
            return [null, "That file is not a valid $noun."];
        }
        [$width, $height] = $info;
        if ($width < self::MIN_SIDE_PX || $height < self::MIN_SIDE_PX) {
            return [null, "That $noun is too small. It must be at least " . self::MIN_SIDE_PX . ' pixels wide and high.'];
        }
        if ($width * $height > $rules['max_pixels'] || !self::enoughMemoryFor($width, $height)) {
            return [null, "That $noun has too many pixels. " . $rules['pixel_hint']];
        }

        $folder = $rules['directory'];
        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            error_log('Could not create the pictures folder: ' . $folder);
            return [null, $noSave];
        }
        if (!is_writable($folder)) {
            error_log('The pictures folder is not writable: ' . $folder);
            return [null, $noSave];
        }

        $name   = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $folder . '/' . $name;

        if (self::canProcess($mime)) {
            if (!self::reencode($tmp, $target, $mime, (int) $rules['max_width'])) {
                return [null, "That file is not a valid $noun."];
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
                return [null, $noSave];
            }
            @chmod($target, 0644);
        }

        return [$name, null];
    }

    // Is there enough memory to open a picture this size? Opening it needs roughly
    // 4 bytes per pixel, and turning it the right way up needs a second copy. Asking
    // first lets us refuse politely instead of the whole page crashing.
    private static function enoughMemoryFor(int $width, int $height): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $number = (float) $limit;
        $unit   = strtolower(substr($limit, -1));
        $bytes  = match ($unit) {
            'g'     => $number * 1073741824,
            'm'     => $number * 1048576,
            'k'     => $number * 1024,
            default => $number,
        };
        $needed = ($width * $height * 8) + 10485760;
        return $needed < ($bytes - memory_get_usage(true));
    }

    // Deletes a saved service picture. Only ever touches files with a name this
    // class would have created, so a bad value in the database cannot make it
    // delete anything else.
    public static function delete(?string $filename): void
    {
        self::removeFrom(self::directory(), $filename);
    }

    // Deletes a saved inspiration photo (the same safety rule applies).
    public static function deleteInspiration(?string $filename): void
    {
        self::removeFrom(self::inspirationDirectory(), $filename);
    }

    private static function removeFrom(string $folder, ?string $filename): void
    {
        if ($filename === null || !preg_match(self::NAME_PATTERN, $filename)) {
            return;
        }
        $path = $folder . '/' . $filename;
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
    private static function reencode(string $source, string $target, string $mime, int $maxWidth): bool
    {
        // The image library quietly "repairs" a JPEG that was cut off part-way (for example a
        // half-finished download) and hands back a mostly grey picture. Listen for that warning
        // so we can refuse such a file instead of saving a broken picture.
        $cutOff     = false;
        $oldSetting = ini_set('gd.jpeg_ignore_warning', '0');   // PHP normally hides that warning
        set_error_handler(function (int $level, string $message) use (&$cutOff): bool {
            if (stripos($message, 'Premature end of JPEG') !== false) {
                $cutOff = true;
            }
            return true;   // we have dealt with it: nothing is printed or logged
        });
        try {
            $image = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($source),
                'image/png'  => imagecreatefrompng($source),
                'image/webp' => imagecreatefromwebp($source),
                default      => false,
            };
        } finally {
            restore_error_handler();
            if ($oldSetting !== false) {
                ini_set('gd.jpeg_ignore_warning', $oldSetting);
            }
        }
        if ($image === false || $cutOff) {
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
        if ($width > $maxWidth) {
            $newHeight = max(1, (int) round($height * $maxWidth / $width));
            $small     = imagecreatetruecolor($maxWidth, $newHeight);
            if ($mime !== 'image/jpeg') {                       // keep transparent areas transparent
                imagealphablending($small, false);
                imagesavealpha($small, true);
                imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
            }
            imagecopyresampled($small, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
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
