<?php
declare(strict_types=1);

$pageTitle = 'Bewertung';
require __DIR__ . '/_header.php';

$testimonialId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$testimonial = null;

if ($testimonialId > 0) {
    $stmt = db()->prepare('SELECT * FROM testimonials WHERE id = ?');
    $stmt->execute([$testimonialId]);
    $testimonial = $stmt->fetch();
    if (!$testimonial) {
        http_response_code(404);
        echo '<p>Bewertung nicht gefunden.</p>';
        require __DIR__ . '/_footer.php';
        exit;
    }
}

$errors = [];

$customerName = (string) ($_POST['customer_name'] ?? $testimonial['customer_name'] ?? '');
$eventType    = (string) ($_POST['event_type'] ?? $testimonial['event_type'] ?? '');
$rating       = (int) ($_POST['rating'] ?? $testimonial['rating'] ?? 5);
$quote        = (string) ($_POST['quote'] ?? $testimonial['quote'] ?? '');
$sortOrder    = (int) ($_POST['sort_order'] ?? $testimonial['sort_order'] ?? 0);
$isActive     = isset($_POST['is_active']) ? true : (bool) ($testimonial['is_active'] ?? true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (trim($customerName) === '') {
        $errors[] = 'Bitte einen Namen angeben.';
    }
    if (trim($quote) === '') {
        $errors[] = 'Bitte das Zitat angeben.';
    }
    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Sterne müssen zwischen 1 und 5 liegen.';
    }

    if (!$errors) {
        if ($testimonialId > 0) {
            $stmt = db()->prepare(
                'UPDATE testimonials SET customer_name = ?, event_type = ?, rating = ?, quote = ?,
                 is_active = ?, sort_order = ? WHERE id = ?'
            );
            $stmt->execute([
                trim($customerName), trim($eventType) ?: null, $rating, trim($quote),
                $isActive ? 1 : 0, $sortOrder, $testimonialId,
            ]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO testimonials (customer_name, event_type, rating, quote, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                trim($customerName), trim($eventType) ?: null, $rating, trim($quote),
                $isActive ? 1 : 0, $sortOrder,
            ]);
        }

        header('Location: testimonials.php');
        exit;
    }
}
?>

<section class="panel">
    <?php foreach ($errors as $err): ?>
        <p class="error"><?= htmlspecialchars($err, ENT_QUOTES) ?></p>
    <?php endforeach; ?>

    <form method="post" action="testimonial_form.php">
        <?= csrf_field() ?>
        <?php if ($testimonialId > 0): ?>
            <input type="hidden" name="id" value="<?= $testimonialId ?>">
        <?php endif; ?>

        <div class="grid3">
            <label>Name *
                <input type="text" name="customer_name" required value="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>" placeholder="z.B. Julia M.">
            </label>
            <label>Anlass (optional)
                <input type="text" name="event_type" value="<?= htmlspecialchars($eventType, ENT_QUOTES) ?>" placeholder="z.B. Hochzeit">
            </label>
            <label>Sterne
                <select name="rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <option value="<?= $i ?>" <?= $rating === $i ? 'selected' : '' ?>><?= str_repeat('★', $i) ?> (<?= $i ?>)</option>
                    <?php endfor; ?>
                </select>
            </label>
        </div>

        <label>Zitat *
            <textarea name="quote" rows="4" required><?= htmlspecialchars($quote, ENT_QUOTES) ?></textarea>
        </label>

        <div class="grid3">
            <label>Sortierung
                <input type="number" name="sort_order" value="<?= $sortOrder ?>">
            </label>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="is_active" <?= $isActive ? 'checked' : '' ?>>
            Aktiv (auf der Startseite sichtbar)
        </label>

        <button type="submit">Speichern</button>
        <a href="testimonials.php" class="button-secondary">Abbrechen</a>
    </form>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
