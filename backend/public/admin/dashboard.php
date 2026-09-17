<?php
declare(strict_types=1);

// Uebersicht mit Kennzahlen/Statistiken zu Buchungen, Zahlungen, Layouts,
// Extras, Gutscheinen und Boxen. Alle Diagramme sind reines CSS (Balken
// als Divs mit Breiten-/Hoehenprozent) statt einer JS-Chart-Bibliothek,
// damit das Panel ohne externe Ressourcen und ohne Build-Schritt auskommt.

$pageTitle = 'Übersicht';
require __DIR__ . '/_header.php';

$boxCount = (int) db()->query('SELECT COUNT(*) FROM boxes')->fetchColumn();
$layoutCount = (int) db()->query('SELECT COUNT(*) FROM layouts')->fetchColumn();

$statsAvailable = true;
$statsError = '';

// Deutsche Monatskuerzel, da DateTime::format('M') englische Namen liefert
// und die intl-Extension nicht vorausgesetzt werden soll.
const DASHBOARD_MONTH_LABELS = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
    7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez',
];

try {
    // ---------- Buchungen nach Status ----------
    $statusCounts = array_fill_keys(BOOKING_STATUSES, 0);
    $stmt = db()->query('SELECT status, COUNT(*) AS cnt FROM bookings GROUP BY status');
    foreach ($stmt->fetchAll() as $row) {
        $statusCounts[$row['status']] = (int) $row['cnt'];
    }
    $totalBookings = array_sum($statusCounts);

    $openBookingCount = $statusCounts['angefragt'];
    $unassignedCount = (int) db()->query(
        "SELECT COUNT(*) FROM bookings WHERE status = 'bestaetigt' AND box_id IS NULL"
    )->fetchColumn();

    // ---------- Zahlungen/Umsatz ----------
    $stmt = db()->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(total_price_cents), 0) AS sum_cents FROM bookings WHERE paid_at IS NOT NULL');
    $paidRow = $stmt->fetch();
    $paidCount = (int) $paidRow['cnt'];
    $paidRevenue = (int) $paidRow['sum_cents'];

    $confirmedRevenue = (int) db()->query(
        "SELECT COALESCE(SUM(total_price_cents), 0) FROM bookings WHERE status = 'bestaetigt'"
    )->fetchColumn();

    $revenueThisMonth = (int) db()->query(
        "SELECT COALESCE(SUM(total_price_cents), 0) FROM bookings
         WHERE paid_at IS NOT NULL AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
    )->fetchColumn();

    $revenueThisYear = (int) db()->query(
        "SELECT COALESCE(SUM(total_price_cents), 0) FROM bookings
         WHERE paid_at IS NOT NULL AND YEAR(paid_at) = YEAR(CURDATE())"
    )->fetchColumn();

    $avgBookingValue = $paidCount > 0 ? (int) round($paidRevenue / $paidCount) : 0;

    $quoteConfirmedCount = $statusCounts['bestaetigt'] - $paidCount;
    // Kann durch Zusatzzahlungen/Rundung theoretisch minimal abweichen -
    // nie negativ anzeigen.
    $quoteConfirmedCount = max(0, $quoteConfirmedCount);

    $decidedCount = $statusCounts['bestaetigt'] + $statusCounts['abgelehnt'] + $statusCounts['storniert'];
    $successRate = $decidedCount > 0 ? round($statusCounts['bestaetigt'] / $decidedCount * 100) : null;
    $onlinePaidShare = $statusCounts['bestaetigt'] > 0 ? round($paidCount / $statusCounts['bestaetigt'] * 100) : null;

    // ---------- Zusatzzahlungen ----------
    $stmt = db()->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS sum_cents FROM booking_addon_charges WHERE paid_at IS NULL');
    $addonOpenRow = $stmt->fetch();
    $stmt = db()->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS sum_cents FROM booking_addon_charges WHERE paid_at IS NOT NULL');
    $addonPaidRow = $stmt->fetch();

    // ---------- Monatsverlauf (letzte 12 Monate) ----------
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = (new DateTimeImmutable('first day of this month'))->modify("-{$i} months")->format('Y-m');
        $months[$ym] = ['bookings' => 0, 'revenue' => 0];
    }
    $firstMonth = array_key_first($months);

    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt FROM bookings
         WHERE created_at >= ? GROUP BY ym"
    );
    $stmt->execute([$firstMonth . '-01']);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($months[$row['ym']])) {
            $months[$row['ym']]['bookings'] = (int) $row['cnt'];
        }
    }

    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, COALESCE(SUM(total_price_cents), 0) AS rev FROM bookings
         WHERE paid_at IS NOT NULL AND paid_at >= ? GROUP BY ym"
    );
    $stmt->execute([$firstMonth . '-01']);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($months[$row['ym']])) {
            $months[$row['ym']]['revenue'] = (int) $row['rev'];
        }
    }

    $maxMonthlyBookings = max(1, ...array_column($months, 'bookings'));
    $maxMonthlyRevenue = max(1, ...array_column($months, 'revenue'));

    // ---------- Beliebteste Layouts ----------
    $topLayouts = db()->query(
        "SELECT l.name, l.category, COUNT(*) AS cnt
         FROM booking_layouts bl
         INNER JOIN layouts l ON l.id = bl.layout_id
         INNER JOIN bookings b ON b.id = bl.booking_id
         WHERE b.status IN ('bestaetigt', 'angefragt')
         GROUP BY l.id
         ORDER BY cnt DESC, l.name
         LIMIT 8"
    )->fetchAll();
    $maxLayoutCount = max(1, ...array_column($topLayouts, 'cnt') ?: [0]);

    // ---------- Beliebteste Extras ----------
    $topExtras = db()->query(
        "SELECT e.name, e.icon, COUNT(DISTINCT be.booking_id) AS bookings_cnt,
                SUM(be.quantity) AS qty, SUM(be.quantity * e.price_cents) AS revenue_cents
         FROM booking_extras be
         INNER JOIN extras e ON e.id = be.extra_id
         INNER JOIN bookings b ON b.id = be.booking_id
         WHERE b.status IN ('bestaetigt', 'angefragt')
         GROUP BY e.id
         ORDER BY bookings_cnt DESC, e.name
         LIMIT 8"
    )->fetchAll();
    $maxExtraCount = max(1, ...array_column($topExtras, 'bookings_cnt') ?: [0]);

    // ---------- Gutscheine ----------
    $topCoupons = db()->query(
        'SELECT * FROM coupons ORDER BY redemption_count DESC, created_at DESC LIMIT 6'
    )->fetchAll();
    $totalDiscountGiven = (int) db()->query(
        "SELECT COALESCE(SUM(discount_cents), 0) FROM bookings WHERE status = 'bestaetigt' AND discount_cents > 0"
    )->fetchColumn();

    // ---------- Boxen-Auslastung ----------
    $boxStats = db()->query(
        "SELECT b.id, b.name,
                (SELECT COUNT(*) FROM bookings WHERE box_id = b.id) AS total_bookings,
                (SELECT MIN(event_date) FROM bookings WHERE box_id = b.id AND status = 'bestaetigt' AND event_date >= CURDATE()) AS next_event,
                b.config_version
         FROM boxes b
         ORDER BY b.name"
    )->fetchAll();

    // ---------- Anstehende Events & neueste Buchungen ----------
    $upcomingEvents = db()->query(
        "SELECT * FROM bookings WHERE status = 'bestaetigt' AND event_date >= CURDATE() ORDER BY event_date LIMIT 10"
    )->fetchAll();
    $recentBookings = db()->query(
        'SELECT * FROM bookings ORDER BY created_at DESC LIMIT 8'
    )->fetchAll();
} catch (PDOException $e) {
    $statsAvailable = false;
    $statsError = $e->getMessage();
}
?>
<div class="cards">
    <a class="card" href="bookings.php">
        <span class="card-number"><?= $statsAvailable ? $openBookingCount : 0 ?></span>
        <span class="card-label">Neue Buchungsanfragen</span>
    </a>
    <a class="card" href="boxes.php">
        <span class="card-number"><?= $statsAvailable ? $unassignedCount : 0 ?></span>
        <span class="card-label">Buchungen ohne Box</span>
    </a>
    <a class="card" href="boxes.php">
        <span class="card-number"><?= $boxCount ?></span>
        <span class="card-label">Boxen</span>
    </a>
    <a class="card" href="layouts.php">
        <span class="card-number"><?= $layoutCount ?></span>
        <span class="card-label">Layouts</span>
    </a>
</div>

<?php if (!$statsAvailable): ?>
    <p class="error">
        Die Statistiken konnten nicht geladen werden - vermutlich fehlt eine Migration.
        Alle Migrationen der Reihe nach einspielen (siehe <code>backend/README.md</code>,
        Abschnitt "Bestehende Installation aktualisieren").
        <?php if ($statsError !== ''): ?><br><span class="muted-text"><?= htmlspecialchars($statsError, ENT_QUOTES) ?></span><?php endif; ?>
    </p>
<?php else: ?>

    <section class="panel">
        <h2>Zahlungen &amp; Umsatz</h2>
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-card-label">Umsatz gesamt (bezahlt)</div>
                <div class="stat-card-value"><?= money_from_cents($paidRevenue) ?></div>
                <div class="stat-card-sub"><?= $paidCount ?> bezahlte Buchung(en)</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Umsatz diesen Monat</div>
                <div class="stat-card-value"><?= money_from_cents($revenueThisMonth) ?></div>
                <div class="stat-card-sub">bezahlt, aktueller Kalendermonat</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Umsatz dieses Jahr</div>
                <div class="stat-card-value"><?= money_from_cents($revenueThisYear) ?></div>
                <div class="stat-card-sub">bezahlt, aktuelles Kalenderjahr</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Ø Buchungswert</div>
                <div class="stat-card-value"><?= money_from_cents($avgBookingValue) ?></div>
                <div class="stat-card-sub">pro bezahlter Buchung</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Bestätigt, noch nicht (online) bezahlt</div>
                <div class="stat-card-value"><?= $quoteConfirmedCount ?></div>
                <div class="stat-card-sub">z.B. manuell bestätigte Angebote</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Offene Zusatzzahlungen</div>
                <div class="stat-card-value"><?= money_from_cents((int) $addonOpenRow['sum_cents']) ?></div>
                <div class="stat-card-sub"><?= (int) $addonOpenRow['cnt'] ?> wartet auf Zahlungseingang</div>
            </div>
        </div>
        <div class="kpi-row">
            <?php if ($successRate !== null): ?>
                <span class="kpi-pill">Erfolgsquote abgeschlossener Anfragen: <?= $successRate ?>%</span>
            <?php endif; ?>
            <?php if ($onlinePaidShare !== null): ?>
                <span class="kpi-pill">Anteil online bezahlt (von bestätigt): <?= $onlinePaidShare ?>%</span>
            <?php endif; ?>
            <span class="kpi-pill">Gutscheine eingelöst: <?= money_from_cents($totalDiscountGiven) ?> Rabatt</span>
            <span class="kpi-pill">Zusatzzahlungen bezahlt: <?= money_from_cents((int) $addonPaidRow['sum_cents']) ?> (<?= (int) $addonPaidRow['cnt'] ?>)</span>
        </div>
    </section>

    <div class="dash-grid">
        <section class="panel">
            <h2>Umsatz der letzten 12 Monate</h2>
            <div class="month-chart">
                <?php foreach ($months as $ym => $data): ?>
                    <?php
                    $height = max(2, (int) round($data['revenue'] / $maxMonthlyRevenue * 100));
                    [$y, $m] = explode('-', $ym);
                    ?>
                    <div class="month-chart-col" title="<?= DASHBOARD_MONTH_LABELS[(int) $m] ?> <?= $y ?>: <?= money_from_cents($data['revenue']) ?>">
                        <span class="month-chart-value"><?= $data['revenue'] > 0 ? number_format($data['revenue'] / 100, 0, ',', '.') : '' ?></span>
                        <div class="month-chart-bar" style="height:<?= $height ?>%;"></div>
                        <span class="month-chart-label"><?= DASHBOARD_MONTH_LABELS[(int) $m] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <h2>Neue Buchungsanfragen pro Monat</h2>
            <div class="month-chart">
                <?php foreach ($months as $ym => $data): ?>
                    <?php
                    $height = max(2, (int) round($data['bookings'] / $maxMonthlyBookings * 100));
                    [$y, $m] = explode('-', $ym);
                    ?>
                    <div class="month-chart-col" title="<?= DASHBOARD_MONTH_LABELS[(int) $m] ?> <?= $y ?>: <?= $data['bookings'] ?>">
                        <span class="month-chart-value"><?= $data['bookings'] > 0 ? $data['bookings'] : '' ?></span>
                        <div class="month-chart-bar" style="height:<?= $height ?>%;"></div>
                        <span class="month-chart-label"><?= DASHBOARD_MONTH_LABELS[(int) $m] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <div class="dash-grid">
        <section class="panel">
            <h2>Buchungen nach Status</h2>
            <div class="bar-list">
                <?php foreach ($statusCounts as $status => $cnt): ?>
                    <?php $pct = $totalBookings > 0 ? round($cnt / $totalBookings * 100) : 0; ?>
                    <div class="bar-list-row">
                        <span class="bar-list-name"><?= htmlspecialchars(booking_status_label($status), ENT_QUOTES) ?></span>
                        <div class="bar-list-track"><div class="bar-list-fill" style="width:<?= $pct ?>%;"></div></div>
                        <span class="bar-list-value"><?= $cnt ?> (<?= $pct ?>%)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <h2>Beliebteste Layouts</h2>
            <?php if (!$topLayouts): ?>
                <p class="muted-text">Noch keine Buchungen mit Layout-Auswahl.</p>
            <?php else: ?>
                <div class="bar-list">
                    <?php foreach ($topLayouts as $row): ?>
                        <?php $pct = round((int) $row['cnt'] / $maxLayoutCount * 100); ?>
                        <div class="bar-list-row">
                            <span class="bar-list-name" title="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($row['name'], ENT_QUOTES) ?>
                            </span>
                            <div class="bar-list-track"><div class="bar-list-fill" style="width:<?= $pct ?>%;"></div></div>
                            <span class="bar-list-value"><?= (int) $row['cnt'] ?>×</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel">
        <h2>Beliebteste Extras</h2>
        <?php if (!$topExtras): ?>
            <p class="muted-text">Noch keine gebuchten Extras.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Extra</th><th>Buchungen</th><th>Menge gesamt</th><th>Umsatz (Extra allein)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($topExtras as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['icon'], ENT_QUOTES) ?> <?= htmlspecialchars($row['name'], ENT_QUOTES) ?></td>
                            <td><?= (int) $row['bookings_cnt'] ?></td>
                            <td><?= (int) $row['qty'] ?></td>
                            <td><?= money_from_cents((int) $row['revenue_cents']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <div class="dash-grid">
        <section class="panel">
            <h2>Gutscheine</h2>
            <?php if (!$topCoupons): ?>
                <p class="muted-text">Noch keine Gutscheine angelegt.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Code</th><th>Wert</th><th>Eingelöst</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($topCoupons as $coupon): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($coupon['code'], ENT_QUOTES) ?></code></td>
                                <td><?= $coupon['discount_type'] === 'percent' ? (int) $coupon['discount_value'] . ' %' : money_from_cents((int) $coupon['discount_value']) ?></td>
                                <td><?= (int) $coupon['redemption_count'] ?><?= $coupon['max_redemptions'] ? ' / ' . (int) $coupon['max_redemptions'] : '' ?></td>
                                <td><?= $coupon['is_active'] ? 'Aktiv' : 'Inaktiv' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="muted-text" style="margin-top:8px;">Alle Codes und Details unter <a href="coupons.php">Gutscheine</a>.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Boxen-Auslastung</h2>
            <table>
                <thead><tr><th>Box</th><th>Buchungen gesamt</th><th>Nächstes Event</th><th>Sync-Version</th></tr></thead>
                <tbody>
                    <?php foreach ($boxStats as $box): ?>
                        <tr>
                            <td><a href="boxes.php"><?= htmlspecialchars($box['name'], ENT_QUOTES) ?></a></td>
                            <td><?= (int) $box['total_bookings'] ?></td>
                            <td><?= $box['next_event'] ? htmlspecialchars($box['next_event'], ENT_QUOTES) : '—' ?></td>
                            <td><?= (int) $box['config_version'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$boxStats): ?>
                        <tr><td colspan="4" class="muted-text">Noch keine Box angelegt.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <div class="dash-grid">
        <section class="panel">
            <h2>Anstehende Events</h2>
            <?php if (!$upcomingEvents): ?>
                <p class="muted-text">Keine bestätigten Events in der Zukunft.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Datum</th><th>Kunde</th><th>Box</th></tr></thead>
                    <tbody>
                        <?php foreach ($upcomingEvents as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['event_date'], ENT_QUOTES) ?></td>
                                <td><a href="booking_detail.php?id=<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['customer_name'], ENT_QUOTES) ?></a></td>
                                <td><?= $b['box_id'] ? 'zugeordnet' : '<span class="error" style="padding:2px 8px;">keine Box</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Neueste Buchungen</h2>
            <table>
                <thead><tr><th>Angefragt am</th><th>Kunde</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($recentBookings as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['created_at'], ENT_QUOTES) ?></td>
                            <td><a href="booking_detail.php?id=<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['customer_name'], ENT_QUOTES) ?></a></td>
                            <td><span class="status-pill status-<?= htmlspecialchars($b['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(booking_status_label($b['status']), ENT_QUOTES) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentBookings): ?>
                        <tr><td colspan="3" class="muted-text">Noch keine Buchungen.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
