<?php
// add-booking v2: stylists AND administrators can add a booking (for example when a client phones).
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Service.php';
require_once __DIR__ . '/../classes/Stylist.php';
require_once __DIR__ . '/../classes/Booking.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/ImageUpload.php';

// Any logged-in member of staff may open this page.
//  - a STYLIST always adds to THEIR OWN diary: who they are comes from the
//    login session, never from the address or the form.
//  - an ADMINISTRATOR chooses which stylist the booking is for.
$user = Auth::user();
if ($user === null) {
    header('Location: login.php');
    exit;
}
$isAdmin = ($user['role'] === 'admin');
if (!$isAdmin && $user['role'] !== 'stylist') {
    http_response_code(403);
    exit('You do not have access to this page.');
}

$input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

$error       = null;   // a problem with the page as a whole
$notice      = null;   // a problem with what was chosen (shown above the form)
$errors      = [];     // problems with individual client details
$services    = [];
$stylists    = [];     // every stylist (the administrator picks from these)
$stylistId   = null;
$stylist     = null;
$service     = null;
$date        = null;
$start       = null;
$slots       = [];
$clientName  = $clientEmail = $clientPhone = $notes = '';

try {
    $services = Service::getAll();

    if ($isAdmin) {
        $stylists = Stylist::getAll();
        $wanted   = filter_var($input['stylist_id'] ?? '', FILTER_VALIDATE_INT);
        if ($wanted !== false) {
            foreach ($stylists as $s) {
                if ((int) $s['stylist_id'] === $wanted) {
                    $stylistId = $wanted;
                }
            }
            if ($stylistId === null) {
                $notice = 'Please choose a stylist from the list.';
            }
        }
    } else {
        $stylistId = (int) $user['id'];
    }

    if ($stylistId !== null) {
        $stylist = Stylist::findForAdmin($stylistId);
    }
} catch (PDOException $ex) {
    error_log('Could not load the add-booking page: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}
if ($error === null && !$isAdmin && $stylist === null) {
    $error = 'We could not load your details. Please log in again.';
}

if ($error === null && $stylist !== null) {
    // ---- Which service? ----
    $serviceId = filter_var($input['service_id'] ?? '', FILTER_VALIDATE_INT);
    if ($serviceId !== false) {
        foreach ($services as $s) {
            if ((int) $s['service_id'] === $serviceId) {
                $service = $s;
            }
        }
        if ($service === null) {
            $notice = 'Please choose a service from the list.';
        }
    }

    // ---- Which day? ----
    $dateText = (string) ($input['date'] ?? '');
    if ($service !== null && $dateText !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
        if ($parsed === false || $parsed->format('Y-m-d') !== $dateText) {
            $notice = 'Please choose a valid date.';
        } elseif ($parsed < new DateTimeImmutable('today')) {
            $notice = 'Please choose today or a future date.';
        } else {
            $date = $parsed;
        }
    }

    // ---- The free times in THIS stylist's diary ----
    if ($service !== null && $date !== null) {
        try {
            $slots = Booking::getAvailableSlots($stylist, (int) $service['duration_minutes'], $date);
        } catch (PDOException $ex) {
            error_log('Could not load times: ' . $ex->getMessage());
            $notice = 'Sorry, we could not load the available times. Please try again.';
        }
    }

    // ---- Has a time been picked? ----
    $startText = (string) ($input['start'] ?? '');
    if ($startText !== '' && $service !== null && $date !== null && $notice === null) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startText);
        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $startText) {
            $notice = 'That time is not valid. Please choose a time from the list.';
        } else {
            $isFree = false;
            foreach ($slots as $slot) {
                if ($slot == $parsed) {
                    $isFree = true;
                }
            }
            if ($isFree) {
                $start = $parsed;
            } else {
                $notice = 'Sorry, that time is not available. Please choose another time.';
            }
        }
    }

    // ---- The client's details were sent: check them, then save ----
    if ($start !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $clientName  = trim((string) ($_POST['client_name'] ?? ''));
        $clientEmail = trim((string) ($_POST['client_email'] ?? ''));
        $clientPhone = trim((string) ($_POST['client_phone'] ?? ''));
        $notes       = trim((string) ($_POST['notes'] ?? ''));

        if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
            $errors['form'] = 'Your session expired. Please try again.';
        }
        if ($clientName === '' || mb_strlen($clientName) > 100) {
            $errors['client_name'] = "Please enter the client's name (up to 100 characters).";
        }
        if ($clientEmail !== '' && (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($clientEmail) > 150)) {
            $errors['client_email'] = 'That email address does not look right.';
        }
        if ($clientPhone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $clientPhone)) {
            $errors['client_phone'] = 'Phone numbers may only contain digits, spaces and + - ( ), and be 7 to 20 characters long.';
        }
        if ($clientEmail === '' && $clientPhone === '') {
            $errors['contact'] = 'Please give a phone number or an email address, so the client can be reached.';
        }
        if (mb_strlen($notes) > 1000) {
            $errors['notes'] = 'Notes can be up to 1000 characters.';
        }

        // The optional photo (for example one the client sent on WhatsApp).
        $imageName = null;
        $hadPhoto  = isset($_FILES['inspiration']) && ($_FILES['inspiration']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (count($errors) === 0) {
            [$imageName, $imageProblem] = ImageUpload::saveInspirationFromUpload($_FILES['inspiration'] ?? []);
            if ($imageProblem !== null) {
                $errors['inspiration'] = $imageProblem;
            }
        } elseif ($hadPhoto) {
            $errors['inspiration'] = 'Because of the problem above, please choose the photo again.';
        }

        if (count($errors) === 0) {
            try {
                $token = Booking::create(
                    $stylist,
                    $service,
                    $start,
                    $clientName,
                    $clientEmail === '' ? null : $clientEmail,
                    $clientPhone === '' ? null : $clientPhone,
                    $notes === '' ? null : $notes,
                    $imageName
                );

                if ($token === null) {
                    ImageUpload::deleteInspiration($imageName);
                    $notice = 'Sorry, that time was just taken. Please choose another time.';
                    $start  = null;
                } else {
                    // Email the confirmation if the client has an address. The booking stands either way.
                    $emailNote = ' No email address was given, so no confirmation was sent.';
                    if ($clientEmail !== '') {
                        $sent = false;
                        try {
                            $saved = Booking::findByToken($token);
                            if ($saved !== null) {
                                $sent = Mailer::sendBookingConfirmation($saved, $token);
                            }
                        } catch (Throwable $mailEx) {
                            error_log('Confirmation email step failed: ' . $mailEx->getMessage());
                        }
                        $emailNote = $sent ? ' A confirmation email was sent.' : ' The confirmation email could NOT be sent.';
                    }

                    $_SESSION['flash'] = ['success', 'Booking added for ' . $clientName
                        . ($isAdmin ? ' with ' . $stylist['name'] : '') . ' on '
                        . $start->format('D j M') . ' at ' . $start->format('H:i') . '.' . $emailNote];

                    // Take them to where the new booking can be seen.
                    $day = $start->format('Y-m-d');
                    if ($isAdmin) {
                        header('Location: admin_dashboard.php?' . http_build_query([
                            'from' => $day, 'to' => $day, 'stylist_id' => (int) $stylist['stylist_id'], 'status' => '', 'q' => '',
                        ]));
                    } else {
                        header('Location: stylist_schedule.php?' . http_build_query(['view' => 'day', 'date' => $day]));
                    }
                    exit;
                }
            } catch (Throwable $ex) {
                ImageUpload::deleteInspiration($imageName);
                error_log('Could not add booking: ' . $ex->getMessage());
                $errors['form'] = 'Sorry, we could not save the booking. Please try again.';
            }
        }
    }
}

// The link back to the "choose a time" step, keeping what was already chosen.
$pickerParams = [];
if ($isAdmin && $stylistId !== null) {
    $pickerParams['stylist_id'] = $stylistId;
}
if ($service !== null && $date !== null) {
    $pickerParams['service_id'] = $service['service_id'];
    $pickerParams['date']       = $date->format('Y-m-d');
}
$backToPicker = 'stylist_add_booking.php' . ($pickerParams ? '?' . http_build_query($pickerParams) : '');
$backLink     = $isAdmin ? ['admin_dashboard.php', 'Back to the bookings'] : ['stylist_schedule.php', 'Back to my schedule'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add a Booking - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Add a booking</p>
    </header>

    <main class="container">
        <div class="topbar">
            <span>Logged in as <strong><?= e($user['name']) ?></strong> (<?= $isAdmin ? 'administrator' : 'stylist' ?>)</span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button type="submit" class="button">Log out</button>
            </form>
        </div>

        <?php if ($isAdmin): ?>
            <?= adminNav('addbooking') ?>
        <?php else: ?>
            <p><a href="<?= e($backLink[0]) ?>">&larr; <?= e($backLink[1]) ?></a></p>
        <?php endif; ?>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>

            <?php if ($notice): ?><p class="error"><?= e($notice) ?></p><?php endif; ?>

            <?php if ($start === null): ?>
                <h2><?= $isAdmin ? 'Choose a stylist, a service and a day' : 'Choose a service and a day' ?></h2>
                <p><?= $isAdmin
                    ? 'Use this when a client phones or walks in. The booking goes into the diary of the stylist you choose.'
                    : 'This adds a booking to <strong>your own</strong> diary, for example when a client phones the salon.' ?></p>
                <form method="get" action="stylist_add_booking.php" class="booking-form">
                    <?php if ($isAdmin): ?>
                        <label for="stylist_id">Stylist</label>
                        <select name="stylist_id" id="stylist_id" required>
                            <option value="">Choose a stylist...</option>
                            <?php foreach ($stylists as $s): ?>
                                <option value="<?= (int) $s['stylist_id'] ?>"<?= ($stylistId === (int) $s['stylist_id']) ? ' selected' : '' ?>>
                                    <?= e($s['name']) ?><?= $s['specialty'] ? ' - ' . e($s['specialty']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <label for="service_id">Service</label>
                    <select name="service_id" id="service_id" required>
                        <option value="">Choose a service...</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= (int) $s['service_id'] ?>"<?= ($service !== null && (int) $service['service_id'] === (int) $s['service_id']) ? ' selected' : '' ?>>
                                <?= e($s['name']) ?> (<?= (int) $s['duration_minutes'] ?> min)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="date">Day</label>
                    <input type="date" name="date" id="date" required
                           min="<?= e((new DateTimeImmutable('today'))->format('Y-m-d')) ?>"
                           value="<?= e($date ? $date->format('Y-m-d') : '') ?>">

                    <button type="submit" class="button"><?= $isAdmin ? 'Show free times' : 'Show my free times' ?></button>
                </form>

                <?php if ($stylist !== null && $service !== null && $date !== null && $notice === null): ?>
                    <h2><?= $isAdmin ? e($stylist['name']) . "'s free" : 'Your free' ?> times on <?= e($date->format('l, j F Y')) ?></h2>
                    <?php if (count($slots) === 0): ?>
                        <p><?= $isAdmin ? e($stylist['name']) . ' has' : 'You have' ?> no free times on this day for this service. Try another day.</p>
                    <?php else: ?>
                        <ul class="slot-list">
                            <?php foreach ($slots as $slot): ?>
                                <?php
                                    $linkParams = ['service_id' => $service['service_id'], 'date' => $date->format('Y-m-d'), 'start' => $slot->format('Y-m-d H:i')];
                                    if ($isAdmin) {
                                        $linkParams = ['stylist_id' => $stylistId] + $linkParams;
                                    }
                                ?>
                                <li class="slot">
                                    <a href="stylist_add_booking.php?<?= e(http_build_query($linkParams)) ?>"><strong><?= e($slot->format('H:i')) ?></strong></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>

            <?php else: ?>
                <section class="service-card">
                    <h3>New booking</h3>
                    <p><strong><?= e($service['name']) ?></strong> with <?= $isAdmin ? e($stylist['name']) : 'you' ?></p>
                    <p><?= e($start->format('l, j F Y')) ?>, <?= e($start->format('H:i')) ?>
                       (<?= (int) $service['duration_minutes'] ?> min)</p>
                    <p><a href="<?= e($backToPicker) ?>">Choose a different time</a></p>
                </section>

                <h2>Client details</h2>
                <?php if (isset($errors['form'])): ?><p class="error"><?= e($errors['form']) ?></p><?php endif; ?>
                <form method="post" action="stylist_add_booking.php" class="booking-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <?php if ($isAdmin): ?>
                        <input type="hidden" name="stylist_id" value="<?= (int) $stylist['stylist_id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">
                    <input type="hidden" name="date" value="<?= e($date->format('Y-m-d')) ?>">
                    <input type="hidden" name="start" value="<?= e($start->format('Y-m-d H:i')) ?>">

                    <label for="client_name">Client's name</label>
                    <input type="text" name="client_name" id="client_name" maxlength="100" required value="<?= e($clientName) ?>">
                    <?php if (isset($errors['client_name'])): ?><p class="field-error"><?= e($errors['client_name']) ?></p><?php endif; ?>

                    <label for="client_phone">Phone number</label>
                    <input type="tel" name="client_phone" id="client_phone" maxlength="20" value="<?= e($clientPhone) ?>">
                    <?php if (isset($errors['client_phone'])): ?><p class="field-error"><?= e($errors['client_phone']) ?></p><?php endif; ?>

                    <label for="client_email">Email address (optional: a confirmation is emailed if given)</label>
                    <input type="email" name="client_email" id="client_email" maxlength="150" value="<?= e($clientEmail) ?>">
                    <?php if (isset($errors['client_email'])): ?><p class="field-error"><?= e($errors['client_email']) ?></p><?php endif; ?>
                    <?php if (isset($errors['contact'])): ?><p class="field-error"><?= e($errors['contact']) ?></p><?php endif; ?>

                    <label for="notes">Notes (optional)</label>
                    <textarea name="notes" id="notes" maxlength="1000"
                              placeholder="Allergies, the style wanted, anything to remember"><?= e($notes) ?></textarea>
                    <?php if (isset($errors['notes'])): ?><p class="field-error"><?= e($errors['notes']) ?></p><?php endif; ?>

                    <label for="inspiration">Inspiration photo (optional)</label>
                    <input type="file" name="inspiration" id="inspiration" accept="image/jpeg,image/png,image/webp">
                    <p class="field-hint">For example a photo the client sent you. JPG, PNG or WebP, up to 8 MB.</p>
                    <?php if (isset($errors['inspiration'])): ?><p class="field-error"><?= e($errors['inspiration']) ?></p><?php endif; ?>

                    <button type="submit" class="button">Add booking</button>
                </form>
            <?php endif; ?>

        <?php endif; ?>
    </main>
</body>
</html>
