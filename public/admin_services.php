<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_nav.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Service.php';
require_once __DIR__ . '/../classes/ImageUpload.php';

// Only a logged-in administrator may open this page.
$user = Auth::requireRole('admin');

$errors  = [];
$editing = null;   // the service being edited, if any
$values  = ['name' => '', 'description' => '', 'duration_minutes' => '30', 'price' => ''];
$problem = null;   // a problem loading the page

function backToServices(): void
{
    header('Location: admin_services.php');
    exit;
}

// ---- A form was sent: add, update or delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If the picture was bigger than the server allows for a whole form, PHP
    // throws away everything, including the form's other fields.
    if (count($_POST) === 0 && count($_FILES) === 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $_SESSION['flash'] = ['error', 'That picture is too big. The limit is 2 MB.'];
        backToServices();
    }

    if (!Auth::csrfValid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['error', 'Your session expired. Please try again.'];
        backToServices();
    }

    $action    = (string) ($_POST['action'] ?? '');
    $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($action === 'add') {
            [$clean, $errors] = Service::validate($_POST, null);

            // Only look at the picture once the typed details are fine.
            $newImage = null;
            if (count($errors) === 0) {
                [$newImage, $imageError] = ImageUpload::saveFromUpload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
                if ($imageError !== null) {
                    $errors['image'] = $imageError;
                }
            }

            if (count($errors) === 0) {
                $clean['image'] = $newImage;
                try {
                    Service::create($clean);
                } catch (PDOException $ex) {
                    ImageUpload::delete($newImage); // do not leave an unused picture behind
                    throw $ex;
                }
                $_SESSION['flash'] = ['success', 'The service "' . $clean['name'] . '" was added.'];
                backToServices();
            }
            $values = $_POST; // show the form again with what was typed

        } elseif ($action === 'update') {
            $editing = ($serviceId === false) ? null : Service::find($serviceId);
            if ($editing === null) {
                $_SESSION['flash'] = ['error', 'We could not find that service.'];
                backToServices();
            }
            [$clean, $errors] = Service::validate($_POST, $serviceId);

            $newImage = null;
            if (count($errors) === 0) {
                [$newImage, $imageError] = ImageUpload::saveFromUpload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
                if ($imageError !== null) {
                    $errors['image'] = $imageError;
                }
            }

            if (count($errors) === 0) {
                try {
                    Service::update($serviceId, $clean);
                    if ($newImage !== null) {                       // a new picture replaces the old one
                        Service::setImage($serviceId, $newImage);
                        ImageUpload::delete($editing['image']);
                    } elseif (!empty($_POST['remove_image'])) {     // "Remove the current picture" was ticked
                        Service::setImage($serviceId, null);
                        ImageUpload::delete($editing['image']);
                    }
                } catch (PDOException $ex) {
                    ImageUpload::delete($newImage);
                    throw $ex;
                }
                $_SESSION['flash'] = ['success', 'The service "' . $clean['name'] . '" was updated.'];
                backToServices();
            }
            $values = $_POST;

        } elseif ($action === 'delete') {
            $result = ($serviceId === false) ? 'We could not find that service.' : Service::delete($serviceId);
            $_SESSION['flash'] = ($result === '')
                ? ['success', 'The service was deleted.']
                : ['error', $result];
            backToServices();

        } else {
            $_SESSION['flash'] = ['error', 'That action was not recognised.'];
            backToServices();
        }
    } catch (PDOException $ex) {
        error_log('Service management failed: ' . $ex->getMessage());
        $_SESSION['flash'] = ['error', 'Sorry, something went wrong on our side. Please try again.'];
        backToServices();
    }
}

// ---- Show the page ----
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$services = [];
try {
    $services = Service::getAllWithUsage();

    // ?edit=3 opens the edit form for service 3
    if ($editing === null && isset($_GET['edit'])) {
        $wanted  = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        $editing = ($wanted === false) ? null : Service::find($wanted);
        if ($editing === null) {
            $flash = ['error', 'We could not find that service.'];
        } else {
            $values = [
                'name'             => $editing['name'],
                'description'      => (string) $editing['description'],
                'duration_minutes' => (string) $editing['duration_minutes'],
                'price'            => number_format((float) $editing['price'], 2, '.', ''),
            ];
        }
    }
} catch (PDOException $ex) {
    error_log('Could not load services: ' . $ex->getMessage());
    $problem = 'Sorry, something went wrong on our side. Please try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Services - Executive Grooming Studio</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</head>
<body>
    <header class="site-header">
        <h1>Executive Grooming Studio</h1>
        <p>Administrator - Services</p>
    </header>

    <main class="container">
        <div class="topbar">
            <span>Logged in as <strong><?= e($user['name']) ?></strong> (administrator)</span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button type="submit" class="button">Log out</button>
            </form>
        </div>

        <?= adminNav('services') ?>

        <?php if ($flash): ?>
            <p class="<?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= e($flash[1]) ?></p>
        <?php endif; ?>
        <?php if ($problem): ?>
            <p class="error"><?= e($problem) ?></p>
        <?php else: ?>

        <h2>Services</h2>
        <?php if (count($services) === 0): ?>
            <p>There are no services yet. Add the first one below.</p>
        <?php else: ?>
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th>Picture</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Duration</th>
                        <th>Price</th>
                        <th>Bookings</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $s): ?>
                        <tr>
                            <td>
                                <?php $thumb = Service::imageUrl($s['image']); ?>
                                <?php if ($thumb): ?><img class="service-thumb" src="<?= e($thumb) ?>" alt=""><?php else: ?>-<?php endif; ?>
                            </td>
                            <td><?= e($s['name']) ?></td>
                            <td><?= $s['description'] ? e($s['description']) : '-' ?></td>
                            <td><?= (int) $s['duration_minutes'] ?> min</td>
                            <td>R<?= number_format((float) $s['price'], 2) ?></td>
                            <td><?= (int) $s['bookings'] ?></td>
                            <td>
                                <a href="admin_services.php?edit=<?= (int) $s['service_id'] ?>">Edit</a>
                                <?php if ((int) $s['bookings'] === 0): ?>
                                    <form method="post" action="admin_services.php" class="inline-form"
                                          onsubmit="return confirm('Delete this service? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="service_id" value="<?= (int) $s['service_id'] ?>">
                                        <button type="submit" class="button">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?= $editing ? 'Edit service' : 'Add a service' ?></h2>
        <form method="post" action="admin_services.php" class="booking-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="service_id" value="<?= (int) $editing['service_id'] ?>">
            <?php endif; ?>

            <label for="name">Name</label>
            <input type="text" name="name" id="name" maxlength="100" required value="<?= e((string) ($values['name'] ?? '')) ?>">
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>

            <label for="description">Description (optional)</label>
            <textarea name="description" id="description" maxlength="1000"><?= e((string) ($values['description'] ?? '')) ?></textarea>
            <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>

            <label for="duration_minutes">Duration (minutes)</label>
            <input type="number" name="duration_minutes" id="duration_minutes" min="5" max="480" step="1" required
                   value="<?= e((string) ($values['duration_minutes'] ?? '')) ?>">
            <?php if (isset($errors['duration_minutes'])): ?><p class="field-error"><?= e($errors['duration_minutes']) ?></p><?php endif; ?>

            <label for="price">Price (R)</label>
            <input type="text" name="price" id="price" inputmode="decimal" maxlength="9" required
                   value="<?= e((string) ($values['price'] ?? '')) ?>">
            <?php if (isset($errors['price'])): ?><p class="field-error"><?= e($errors['price']) ?></p><?php endif; ?>

            <label for="image">Picture (optional: JPG, PNG or WebP, up to 2 MB)</label>
            <?php $currentThumb = $editing ? Service::imageUrl($editing['image']) : null; ?>
            <?php if ($currentThumb): ?>
                <p><img class="service-thumb" src="<?= e($currentThumb) ?>" alt="Current picture"></p>
                <p><label><input type="checkbox" name="remove_image" value="1"> Remove the current picture</label></p>
                <p>Or choose a new one to replace it:</p>
            <?php endif; ?>
            <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp">
            <?php if (isset($errors['image'])): ?><p class="field-error"><?= e($errors['image']) ?></p><?php endif; ?>

            <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Add service' ?></button>
            <?php if ($editing): ?><a href="admin_services.php">Cancel</a><?php endif; ?>
        </form>

        <?php endif; ?>
    </main>
</body>
</html>
