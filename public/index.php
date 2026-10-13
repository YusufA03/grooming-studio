<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/Service.php';

$services = [];
$error    = null;

try {
    $services = Service::getAll();
} catch (PDOException $ex) {
    error_log('Could not load services: ' . $ex->getMessage());
    $error = 'Sorry, we could not load our services right now. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Professional grooming, booked in minutes.</p>
    </header>

    <main class="container">
        <h2>Our Services</h2>

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
                        <h3><?= e($service['name']) ?></h3>
                        <p><?= e($service['description'] ?? '') ?></p>
                        <p class="meta">
                            <?= (int) $service['duration_minutes'] ?> min
                            &middot; R<?= number_format((float) $service['price'], 2) ?>
                        </p>
                        <a class="button" href="book.php?service_id=<?= (int) $service['service_id'] ?>">Book this service</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <a href="login.php">Staff login</a>
    </footer>
</body>
</html>
