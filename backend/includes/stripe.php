<?php
declare(strict_types=1);

// Schlanke Anbindung an die Stripe-REST-API per cURL, ohne Composer/SDK -
// passt zum Rest des Projekts (kein Build-Step). Es wird nur der
// Checkout-Sessions-Endpunkt gebraucht (Kunde zahlt auf einer von Stripe
// gehosteten Seite, keine Kartendaten beruehren unseren Server).

// http_build_query() macht aus PHP-Bool true/false die Strings "1"/"" -
// Stripes formularkodierte API erwartet aber woertlich "true"/"false" und
// lehnt sonst mit "Invalid boolean: 1" ab (siehe invoice_creation[enabled]
// weiter unten). Deshalb rekursiv vor dem Encoding umwandeln.
function stripe_encode_booleans(array $params): array
{
    foreach ($params as $key => $value) {
        if (is_bool($value)) {
            $params[$key] = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $params[$key] = stripe_encode_booleans($value);
        }
    }
    return $params;
}

function stripe_request(string $method, string $endpoint, array $params = []): ?array
{
    $cfg = backend_config();
    $secretKey = (string) ($cfg['stripe_secret_key'] ?? '');
    if ($secretKey === '') {
        error_log('Stripe: stripe_secret_key fehlt in config.php');
        return null;
    }

    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secretKey . ':',
        CURLOPT_TIMEOUT => 15,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(stripe_encode_booleans($params)));
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Stripe: cURL-Fehler: ' . $error);
        return null;
    }

    $data = json_decode((string) $response, true);
    if ($httpCode >= 400 || !is_array($data)) {
        error_log('Stripe: API-Fehler (HTTP ' . $httpCode . '): ' . $response);
        return null;
    }

    return $data;
}

// Erstellt eine Checkout Session fuer eine Buchung. $lineItems ist eine
// Liste von ['name' => string, 'amount_cents' => int, 'quantity' => int].
// Liefert die Session (u.a. 'id' und 'url') oder null bei einem Fehler.
function create_stripe_checkout_session(array $booking, array $lineItems, string $successUrl, string $cancelUrl): ?array
{
    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => $booking['customer_email'],
        'metadata' => [
            'booking_id' => (string) $booking['id'],
        ],
        // Laesst Stripe zusaetzlich zur eigenen Rechnung (invoice_number,
        // generate_invoice_pdf()) automatisch eine eigene, bei Stripe
        // gehostete Rechnung erstellen (siehe mark_booking_paid()) - unsere
        // fortlaufende Nummer bleibt die massgebliche fuer die Buchhaltung.
        'invoice_creation' => [
            'enabled' => true,
            'invoice_data' => [
                'custom_fields' => [
                    ['name' => 'Buchung', 'value' => 'Snapolino #' . $booking['id']],
                ],
            ],
        ],
    ];

    foreach (array_values($lineItems) as $i => $item) {
        $params['line_items'][$i]['quantity'] = $item['quantity'] ?? 1;
        $params['line_items'][$i]['price_data']['currency'] = 'eur';
        $params['line_items'][$i]['price_data']['unit_amount'] = $item['amount_cents'];
        $params['line_items'][$i]['price_data']['product_data']['name'] = $item['name'];
    }

    return stripe_request('POST', 'checkout/sessions', $params);
}

// Erstellt eine Checkout Session fuer eine nachtraeglich vom Admin
// hinzugefuegte Leistung, die nicht kostenlos uebernommen werden soll
// (siehe booking_addon_charges, admin/booking_detail.php) - eigene, von der
// Haupt-Buchung unabhaengige Session mit eigenen Metadaten, damit
// stripe_webhook.php beide Faelle auseinanderhalten kann.
function create_addon_charge_checkout_session(
    array $booking,
    int $addonChargeId,
    string $description,
    int $amountCents,
    string $successUrl,
    string $cancelUrl
): ?array {
    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => $booking['customer_email'],
        'metadata' => [
            'addon_charge_id' => (string) $addonChargeId,
        ],
        // Zusatzzahlungen hatten bisher gar keine Rechnung - bekommen jetzt
        // ebenfalls eine bei Stripe gehostete (siehe mark_addon_charge_paid()).
        'invoice_creation' => [
            'enabled' => true,
            'invoice_data' => [
                'custom_fields' => [
                    ['name' => 'Buchung', 'value' => 'Snapolino #' . $booking['id']],
                ],
            ],
        ],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $amountCents,
                'product_data' => ['name' => $description],
            ],
        ]],
    ];

    return stripe_request('POST', 'checkout/sessions', $params);
}

// Liest eine von Stripe erstellte Rechnung (siehe invoice_creation oben),
// u.a. fuer 'invoice_pdf' (direkter PDF-Download) und 'hosted_invoice_url'
// (Ansicht bei Stripe). Liefert null bei einem Fehler.
function fetch_stripe_invoice(string $invoiceId): ?array
{
    return stripe_request('GET', 'invoices/' . urlencode($invoiceId));
}

// Loest den Versand der Stripe-Rechnung per E-Mail direkt durch Stripe aus
// (zusaetzlich zu unserer eigenen Bestaetigungsmail). Best-effort: ein
// Fehlschlag hier darf die Zahlung/Buchung nicht beeintraechtigen, die
// Rechnung bleibt in jedem Fall bei Stripe abrufbar (hosted_invoice_url).
function send_stripe_invoice(string $invoiceId): void
{
    $result = stripe_request('POST', 'invoices/' . urlencode($invoiceId) . '/send');
    if ($result === null) {
        error_log('Stripe: Rechnung ' . $invoiceId . ' konnte nicht per Mail verschickt werden (bleibt bei Stripe abrufbar).');
    }
}

// Prueft die Signatur eines eingehenden Stripe-Webhooks. $payload ist der
// rohe Request-Body, $sigHeader der Inhalt des Headers "Stripe-Signature".
// Liefert das dekodierte Event-Array oder null, wenn die Signatur nicht
// passt oder zu alt ist (Replay-Schutz, 5 Minuten Toleranz).
function verify_stripe_webhook(string $payload, string $sigHeader, string $webhookSecret): ?array
{
    $parts = [];
    foreach (explode(',', $sigHeader) as $piece) {
        [$key, $value] = array_pad(explode('=', $piece, 2), 2, '');
        $parts[$key][] = $value;
    }

    $timestamp = $parts['t'][0] ?? '';
    $signatures = $parts['v1'] ?? [];
    if ($timestamp === '' || !$signatures) {
        return null;
    }

    if (abs(time() - (int) $timestamp) > 300) {
        return null;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $webhookSecret);
    $valid = false;
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        return null;
    }

    $event = json_decode($payload, true);
    return is_array($event) ? $event : null;
}
