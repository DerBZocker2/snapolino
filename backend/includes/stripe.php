<?php
declare(strict_types=1);

// Schlanke Anbindung an die Stripe-REST-API per cURL, ohne Composer/SDK -
// passt zum Rest des Projekts (kein Build-Step). Es wird nur der
// Checkout-Sessions-Endpunkt gebraucht (Kunde zahlt auf einer von Stripe
// gehosteten Seite, keine Kartendaten beruehren unseren Server).

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
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
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
    ];

    foreach (array_values($lineItems) as $i => $item) {
        $params['line_items'][$i]['quantity'] = $item['quantity'] ?? 1;
        $params['line_items'][$i]['price_data']['currency'] = 'eur';
        $params['line_items'][$i]['price_data']['unit_amount'] = $item['amount_cents'];
        $params['line_items'][$i]['price_data']['product_data']['name'] = $item['name'];
    }

    return stripe_request('POST', 'checkout/sessions', $params);
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
