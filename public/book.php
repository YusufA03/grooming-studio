<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/account_bar.php';
require_once __DIR__ . '/../classes/Service.php';
require_once __DIR__ . '/../classes/Stylist.php';
require_once __DIR__ . '/../classes/Booking.php';

$service  = null;
$stylists = [];
$error    = null;

// 1. Which service did the client choose? (must be a whole number)
$serviceId = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);

try {
    if ($serviceId !== false && $serviceId !== null) {
        $service = Service::find($serviceId);
    }
    $stylists = ($service !== null) ? Stylist::getForService((int) $service['service_id']) : [];
} catch (PDOException $ex) {
    error_log('Could not load booking page: ' . $ex->getMessage());
    $error = 'Sorry, something went wrong on our side. Please try again later.';
}

if (!$error && $service === null) {
    http_response_code(404);
    $error = 'We could not find that service. Please go back and choose one.';
}

// 2. Which stylist and which day did the client choose?
$stylistChoice = inputText($_GET, 'stylist_id', 'any');
$dateInput     = inputText($_GET, 'date', '');
$date          = null;
$formMessage   = null;
$slots         = [];

if (!$error) {
    // Work out which stylists to check.
    $selected = [];
    if ($stylistChoice === 'any') {
        $selected = $stylists;
    } else {
        foreach ($stylists as $s) {
            if ((string) $s['stylist_id'] === $stylistChoice) {
                $selected[] = $s;
            }
        }
        if (count($selected) === 0) {
            $formMessage = 'Please choose a stylist from the list.';
        }
    }

    // Check the date is a real date, today or later.
    if ($dateInput !== '' && $formMessage === null) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
        if ($parsed === false || $parsed->format('Y-m-d') !== $dateInput) {
            $formMessage = 'Please choose a valid date.';
        } elseif ($parsed < new DateTimeImmutable('today')) {
            $formMessage = 'Please choose today or a future date.';
        } else {
            $date = $parsed;
        }
    }

    // Find the free times.
    if ($date !== null && $formMessage === null) {
        try {
            foreach ($selected as $s) {
                $free = Booking::getAvailableSlots($s, (int) $service['duration_minutes'], $date);
                foreach ($free as $start) {
                    $key = $start->format('H:i');
                    if (!isset($slots[$key])) {
                        $slots[$key] = ['stylist' => $s];
                    }
                }
            }
            ksort($slots);
        } catch (PDOException $ex) {
            error_log('Could not load available times: ' . $ex->getMessage());
            $formMessage = 'Sorry, we could not load the available times. Please try again.';
        }
    }
}

// 3. Smart scheduling (Phase 4, Section 4.6): the soonest free time, for the stylist chosen or for anyone.
$suggestion    = null;
$preferredName = null;
if (!$error && isset($selected) && count($selected) > 0) {
    try {
        $preferred     = ($stylistChoice === 'any') ? null : $selected[0];
        $preferredName = ($preferred !== null) ? $preferred['name'] : null;
        $suggestion    = Booking::suggestSlot($stylists, $preferred, (int) $service['duration_minutes']);
    } catch (PDOException $ex) {
        error_log('Could not work out the soonest time: ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book an Appointment - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Book an appointment</p>
    </header>
    <?= accountBar() ?>

    <main class="container">
        <p><a href="index.php">&larr; Back to services</a></p>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php else: ?>
            <section class="service-card">
                <?php $picture = Service::imageUrl($service['image']); ?>
                <?php if ($picture): ?>
                    <img class="service-image" src="<?= e($picture) ?>" alt="<?= e($service['name']) ?>" loading="lazy">
                <?php endif; ?>
                <h3><?= e($service['name']) ?></h3>
                <p><?= e($service['description'] ?? '') ?></p>
                <p class="meta">
                    <?= (int) $service['duration_minutes'] ?> min
                    &middot; R<?= number_format((float) $service['price'], 2) ?>
                </p>
            </section>

            <?php if (isset($selected) && count($selected) > 0): ?>
                <section class="suggestion">
                    <h3>Soonest available</h3>
                    <?php if ($suggestion === null): ?>
                        <p>There are no free times in the next 21 days<?= $preferredName !== null ? ' with ' . e($preferredName) . ' or any other stylist' : '' ?>. Please try again later or call the salon.</p>
                    <?php else: ?>
                        <?php if (!$suggestion['preferred']): ?>
                            <p><?= e((string) $preferredName) ?> has no free times in the next 21 days. The soonest time with another stylist is:</p>
                        <?php endif; ?>
                        <p><strong><?= e($suggestion['start']->format('l, j F')) ?> at <?= e($suggestion['start']->format('H:i')) ?></strong>
                            with <?= e($suggestion['stylist']['name']) ?></p>
                        <a class="button" href="book_details.php?<?= e(http_build_query([
                            'service_id' => $service['service_id'],
                            'stylist_id' => $suggestion['stylist']['stylist_id'],
                            'start'      => $suggestion['start']->format('Y-m-d H:i'),
                        ])) ?>">Book this time</a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <h2>Choose a stylist and a day</h2>
            <form method="get" action="book.php" class="booking-form">
                <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">

                <label for="stylist_id">Stylist</label>
                <select name="stylist_id" id="stylist_id">
                    <option value="any">Any available stylist</option>
                    <?php foreach ($stylists as $s): ?>
                        <option value="<?= (int) $s['stylist_id'] ?>"
                            <?= ((string) $s['stylist_id'] === $stylistChoice) ? 'selected' : '' ?>>
                            <?= e($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="date">Day</label>
                <input type="date" name="date" id="date"
                       min="<?= e((new DateTimeImmutable('today'))->format('Y-m-d')) ?>"
                       value="<?= e($dateInput) ?>" required>

                <button type="submit" class="button">Show available times</button>
            </form>

            <?php if ($formMessage): ?>
                <p class="error"><?= e($formMessage) ?></p>
            <?php elseif ($date !== null): ?>
                <h2>Available times on <?= e($date->format('l, j F Y')) ?></h2>
                <?php if (count($slots) === 0): ?>
                    <p>No times are available on this day. Please try another day or stylist.</p>
                <?php else: ?>
                    <ul class="slot-list">
                        <?php foreach ($slots as $time => $slot): ?>
                            <li class="slot">
                                <a href="book_details.php?<?= e(http_build_query([
                                    'service_id' => $service['service_id'],
                                    'stylist_id' => $slot['stylist']['stylist_id'],
                                    'start'      => $date->format('Y-m-d') . ' ' . $time,
                                ])) ?>">
                                    <strong><?= e($time) ?></strong>
                                    <small>with <?= e($slot['stylist']['name']) ?></small>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
