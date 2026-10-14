<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Service.php';
require_once __DIR__ . '/../classes/Stylist.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/ImageUpload.php';

// Values come from the address (first visit) or from the form (when sent).
$input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

$serviceId = filter_var($input['service_id'] ?? null, FILTER_VALIDATE_INT);
$stylistId = filter_var($input['stylist_id'] ?? null, FILTER_VALIDATE_INT);
$startText = inputText($input, 'start', '');

$service = null;
$stylist = null;
$start   = null;
$error   = null;
$errors  = [];   // problems with individual form fields
$name = $email = $phone = $notes = $allergies = '';

// Did the whole form get thrown away because the photo was bigger than the server allows?
$uploadTooBig = ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0);

try {
    if ($serviceId !== false) {
        $service = Service::find($serviceId);
    }
    if ($stylistId !== false) {
        foreach (Stylist::getAll() as $s) {
            if ((int) $s['stylist_id'] === $stylistId) {
                $stylist = $s;
            }
        }
    }

    if ($service === null || $stylist === null) {
        http_response_code($uploadTooBig ? 413 : 404);
        $error = $uploadTooBig
            ? 'That photo was too big for the server. Please go back and choose a smaller one (up to 8 MB).'
            : 'We could not find that service or stylist. Please start again.';
    } else {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startText);
        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $startText) {
            http_response_code(400);
            $error = 'That appointment time is not valid. Please choose a time again.';
        } else {
            $start = $parsed;

            // Is this time still free right now?
            $stillFree = false;
            $free = Booking::getAvailableSlots($stylist, (int) $service['duration_minutes'], $start);
            foreach ($free as $slot) {
                if ($slot == $start) {
                    $stillFree = true;
                    break;
                }
            }
            if (!$stillFree) {
                http_response_code(409);
                $error = 'Sorry, that time is no longer available. Please choose another time.';
            }
        }
    }
} catch (PDOException $ex) {
    error_log('Could not load booking details: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}

// Where the "Back" link goes.
$backUrl = ($service !== null) ? 'book.php?service_id=' . (int) $service['service_id'] : 'index.php';

// The form was sent: check every field, then save.
if ($error === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim(inputText($_POST, 'name', ''));
    $email = trim(inputText($_POST, 'email', ''));
    $phone = trim(inputText($_POST, 'phone', ''));
    $notes = trim(inputText($_POST, 'notes', ''));
    $allergies = trim(inputText($_POST, 'allergies', ''));

    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'Please enter your name (up to 100 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        $errors['phone'] = 'Phone numbers may only contain digits, spaces and + - ( ), and be 7 to 20 characters long.';
    }
    if (mb_strlen($notes) > 1000) {
        $errors['notes'] = 'Notes can be up to 1000 characters.';
    }
    if (mb_strlen($allergies) > 500) {
        $errors['allergies'] = 'Allergies and sensitivities can be up to 500 characters.';
    }

    // The optional inspiration photo. A browser cannot keep a chosen file when a form
    // comes back with a problem, so if there is a problem we ask for the photo again.
    $imageName = null;
    $hadPhoto  = isset($_FILES['inspiration']) && ($_FILES['inspiration']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (count($errors) === 0) {
        [$imageName, $imageProblem] = ImageUpload::saveInspirationFromUpload($_FILES['inspiration'] ?? []);
        if ($imageProblem !== null) {
            $errors['inspiration'] = $imageProblem;
        }
    } elseif ($hadPhoto) {
        $errors['inspiration'] = 'Because of the problem above, please choose your photo again.';
    }

    if (count($errors) === 0) {
        try {
            $token = Booking::create(
                $stylist,
                $service,
                $start,
                $name,
                $email,
                $phone === '' ? null : $phone,
                $notes === '' ? null : $notes,
                $imageName,
                $allergies === '' ? null : $allergies
            );

            if ($token === null) {
                ImageUpload::deleteInspiration($imageName);   // no booking, so no photo left lying around
                http_response_code(409);
                $error = 'Sorry, that time was just taken by someone else. Please choose another time.';
            } else {
                // Email the confirmation. If the email fails the booking is
                // still saved, so we only log the problem.
                try {
                    $saved = Booking::findByToken($token);
                    if ($saved !== null) {
                        Mailer::sendBookingConfirmation($saved, $token);
                    }
                } catch (Throwable $mailEx) {
                    error_log('Confirmation email step failed: ' . $mailEx->getMessage());
                }

                header('Location: booking_confirmed.php?token=' . $token);
                exit;
            }
        } catch (Throwable $ex) {
            ImageUpload::deleteInspiration($imageName);
            error_log('Could not save booking: ' . $ex->getMessage());
            $error = 'Sorry, we could not save your booking. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Details - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Confirm your booking</p>
    </header>

    <main class="container">
        <p><a href="<?= e($backUrl) ?>">&larr; Back</a></p>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <section class="service-card">
                <h3>Your appointment</h3>
                <p><strong><?= e($service['name']) ?></strong> with <?= e($stylist['name']) ?></p>
                <p><?= e($start->format('l, j F Y')) ?> at <?= e($start->format('H:i')) ?>
                   (<?= (int) $service['duration_minutes'] ?> min)</p>
                <p class="meta">R<?= number_format((float) $service['price'], 2) ?></p>
            </section>

            <h2>Your details</h2>
            <form method="post" action="book_details.php" class="booking-form" enctype="multipart/form-data">
                <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">
                <input type="hidden" name="stylist_id" value="<?= (int) $stylist['stylist_id'] ?>">
                <input type="hidden" name="start" value="<?= e($start->format('Y-m-d H:i')) ?>">

                <label for="name">Full name</label>
                <input type="text" name="name" id="name" maxlength="100" required value="<?= e($name) ?>">
                <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>

                <label for="email">Email address</label>
                <input type="email" name="email" id="email" maxlength="150" required value="<?= e($email) ?>">
                <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>

                <label for="phone">Phone number (optional)</label>
                <input type="tel" name="phone" id="phone" maxlength="20" value="<?= e($phone) ?>">
                <?php if (isset($errors['phone'])): ?><p class="field-error"><?= e($errors['phone']) ?></p><?php endif; ?>

                <label for="allergies">Allergies or sensitivities (optional)</label>
                <textarea name="allergies" id="allergies" maxlength="500" rows="2"
                          placeholder="For example: allergic to ammonia, sensitive to fragrance"><?= e($allergies) ?></textarea>
                <p class="field-hint">Your stylist sees this, highlighted, before you arrive.</p>
                <?php if (isset($errors['allergies'])): ?><p class="field-error"><?= e($errors['allergies']) ?></p><?php endif; ?>

                <label for="notes">Notes for your stylist (optional)</label>
                <textarea name="notes" id="notes" maxlength="1000"
                          placeholder="The style you want, anything we should know"><?= e($notes) ?></textarea>
                <?php if (isset($errors['notes'])): ?><p class="field-error"><?= e($errors['notes']) ?></p><?php endif; ?>

                <label for="inspiration">Inspiration photo (optional)</label>
                <input type="file" name="inspiration" id="inspiration" accept="image/jpeg,image/png,image/webp">
                <p class="field-hint">A picture of the style you would like. JPG, PNG or WebP, up to 8 MB. Only your stylist and the salon can see it.</p>
                <?php if (isset($errors['inspiration'])): ?><p class="field-error"><?= e($errors['inspiration']) ?></p><?php endif; ?>

                <button type="submit" class="button">Confirm booking</button>
            </form>
            <script>
                document.getElementById('inspiration').addEventListener('change', function () {
                    var file = this.files && this.files[0];
                    if (file && file.size > 8 * 1024 * 1024) {
                        alert('That photo is larger than 8 MB. Please choose a smaller one.');
                        this.value = '';
                    }
                });
            </script>
        <?php endif; ?>
    </main>
</body>
</html>
