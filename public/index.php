<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/account_bar.php';
require_once __DIR__ . '/../classes/Service.php';
require_once __DIR__ . '/../classes/Stylist.php';

// "90" becomes "1 h 30 min", "45" becomes "45 min", "180" becomes "3 h".
function durationText(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $rest  = $minutes % 60;
    if ($hours === 0) {
        return $rest . ' min';
    }
    return $hours . ' h' . ($rest > 0 ? ' ' . $rest . ' min' : '');
}

$services = [];
$stylists = [];
$error    = null;

$teamServices = [];
$teamHours    = [];

try {
    $services = Service::getAll();
    $stylists   = Stylist::getAll();
    $teamServices = Stylist::serviceNamesByStylist();
    $teamHours    = [];
    foreach ($stylists as $st) {
        $teamHours[(int) $st['stylist_id']] = Stylist::describeBlocks(Stylist::blocks((int) $st['stylist_id']));
    }
} catch (PDOException $ex) {
    error_log('Could not load the home page: ' . $ex->getMessage());
    $error = 'Sorry, we could not load our services right now. Please try again later.';
}

// The big photo beside the heading. Put your own picture in public/assets/img/
// and call it hero.jpg (or hero.webp / hero.png). Without one, the page simply
// lays out without a photo.
$heroPhoto = null;
foreach (['hero.jpg', 'hero.jpeg', 'hero.webp', 'hero.png'] as $candidate) {
    if (is_file(__DIR__ . '/assets/img/' . $candidate)) {
        $heroPhoto = 'assets/img/' . $candidate;
        break;
    }
}

// Stylists who are currently taking bookings.
$working = array_filter($stylists, function ($s) use ($teamHours) {
    return (int) $s['accepting_bookings'] === 1 && ($teamHours[(int) $s['stylist_id']] ?? '') !== 'No working hours set';
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="Book your appointment at Executive Grooming Studio online.">
    <title>Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming</h1>
        <p>Studio</p>
    </header>
    <?= accountBar() ?>

    <main class="container">
        <section class="hero<?= $heroPhoto ? '' : ' no-photo' ?>">
            <div>
                <p class="eyebrow">Executive Grooming Studio</p>
                <h2 class="hero-title">Book your next appointment online</h2>
                <p class="lead">Choose a service, pick a stylist and a time that is genuinely free, and get your confirmation by email. No account needed, and no waiting on hold.</p>
                <div class="hero-actions">
                    <a class="button" href="#services">Book a service</a>
                    <a class="button button-outline" href="login.php">Staff login</a>
                </div>
                <?php if (!$error): ?>
                    <div class="stats">
                        <div class="stat"><strong><?= count($services) ?></strong><span>services</span></div>
                        <div class="stat"><strong><?= count($working) ?></strong><span>stylists</span></div>
                        <div class="stat"><strong>24/7</strong><span>online booking</span></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($heroPhoto): ?>
                <div class="hero-photo">
                    <img src="<?= e($heroPhoto) ?>" alt="A stylist at work in the studio">
                </div>
            <?php endif; ?>
        </section>

        <section class="section" id="services">
            <div class="section-head">
                <div>
                    <p class="eyebrow">What we do</p>
                    <h2>Our services</h2>
                </div>
            </div>

            <?php if ($error): ?>
                <p class="error"><?= e($error) ?></p>
            <?php elseif (count($services) === 0): ?>
                <p>No services are available at the moment.</p>
            <?php else: ?>
                <div class="service-grid">
                    <?php foreach ($services as $service): ?>
                        <article class="service-card">
                            <?php $picture = Service::imageUrl($service['image']); ?>
                            <?php if ($picture): ?>
                                <img class="service-image" src="<?= e($picture) ?>" alt="<?= e($service['name']) ?>" loading="lazy">
                            <?php endif; ?>
                            <div class="card-body">
                                <h3><?= e($service['name']) ?></h3>
                                <p class="card-text"><?= e($service['description'] ?? '') ?></p>
                                <div class="card-foot">
                                    <span class="chip"><?= e(durationText((int) $service['duration_minutes'])) ?></span>
                                    <span class="price">R<?= number_format((float) $service['price'], 2, '.', ' ') ?></span>
                                    <a class="button" href="book.php?service_id=<?= (int) $service['service_id'] ?>">Book</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$error && count($working) > 0): ?>
            <section class="section">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">The team</p>
                        <h2>Meet your stylists</h2>
                    </div>
                </div>
                <div class="team-grid">
                    <?php foreach ($working as $s): ?>
                        <?php
                            $initials = '';
                            foreach (preg_split('/\s+/', trim($s['name'])) as $word) {
                                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
                            }
                            $initials = mb_substr($initials, 0, 2);
                        ?>
                        <div class="team-card">
                            <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
                            <div>
                                <h3><?= e($s['name']) ?></h3>
                                <p><?= e(implode(', ', $teamServices[(int) $s['stylist_id']] ?? [])) ?><br><?= e($teamHours[(int) $s['stylist_id']] ?? '') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <span><strong>Executive Grooming Studio</strong> &middot; Group 4 &ldquo;Royal&rdquo; &middot; IT Project 2026</span>
            <a href="login.php">Staff login</a>
        </div>
    </footer>
</body>
</html>
