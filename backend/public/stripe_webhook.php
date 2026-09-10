<?php
declare(strict_types=1);

// Nimmt Stripe-Webhook-Events entgegen. Die Signatur (Header
// Stripe-Signature) ist die einzige vertrauenswuerdige Bestaetigung einer
// Zahlung - der Redirect des Kunden zurueck auf success_url ist nur fuers
// UI gedacht und darf niemals allein eine Buchung bestaetigen (koennte
// uebersprungen oder gefaelscht werden).
// In Stripe eintragen unter Developers -> Webhooks:
//   URL:    https://snapolino.de/stripe_webhook.php
//   Events: checkout.session.completed

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/stripe.php';
require_once __DIR__ . '/../includes/payments.php';

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = (string) (backend_config()['stripe_webhook_secret'] ?? '');

if ($payload === false || $sigHeader === '' || $webhookSecret === '') {
    http_response_code(400);
    exit('missing signature');
}

$event = verify_stripe_webhook($payload, $sigHeader, $webhookSecret);
if ($event === null) {
    http_response_code(400);
    exit('invalid signature');
}

if (($event['type'] ?? '') === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $bookingId = (int) ($session['metadata']['booking_id'] ?? 0);
    $paymentIntent = (string) ($session['payment_intent'] ?? '');
    $paid = ($session['payment_status'] ?? '') === 'paid';

    if ($bookingId > 0 && $paid) {
        mark_booking_paid($bookingId, $paymentIntent);
    }
}

http_response_code(200);
echo 'ok';
