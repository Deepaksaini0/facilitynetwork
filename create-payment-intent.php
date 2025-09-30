<?php

use SendGrid\Mail\Mail;

// -------------------------------------------
// CORS Handling (same as before)
// -------------------------------------------
$allowed_origins = [
    "https://www.facilitynetwork.com",
    "https://facility-network-v1.webflow.io"
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// -------------------------------------------
// Load Composer dependencies
// -------------------------------------------
require __DIR__ . '/vendor/autoload.php';

$stripeKey = getenv('STRIPE_SECRET_KEY');
$sendgridKey = getenv('SENDGRID_API_KEY');

if (!$stripeKey || !$sendgridKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Missing API keys']);
    exit();
}

\Stripe\Stripe::setApiKey($stripeKey);
header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);

// Validate amount
$amount = isset($input['holdAmount']) && is_numeric($input['holdAmount']) ? intval($input['holdAmount'] * 100) : null;
if (!$amount || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing amount']);
    exit();
}

try {
    // -------------------------------------------
    // Stripe PaymentIntent
    // -------------------------------------------
    $paymentIntent = \Stripe\PaymentIntent::create([
        'amount' => $amount,
        'currency' => 'cad',
        'capture_method' => 'manual',
        'description' => 'Hotline Service Hold',
        'metadata' => $input
    ]);

    // -------------------------------------------
    // Prepare email content
    // -------------------------------------------
    $messageBody = "New hotline request:\n\n";
    foreach ($input as $key => $val) {
        $messageBody .= ucfirst($key) . ": " . $val . "\n";
    }

    $htmlContent = "<h2>New Hotline Request</h2>";
    foreach ($input as $key => $val) {
        $htmlContent .= "<p><strong>" . ucfirst($key) . ":</strong> " . htmlspecialchars($val) . "</p>";
    }

    $sendgrid = new \SendGrid($sendgridKey);

    // -------------------------------------------
    // 1️⃣ Send email to Admin
    // -------------------------------------------
    $adminEmail = new Mail();
    $adminEmail->setFrom("website@mail.facilitynetwork.com", "Facility Network");
    $adminEmail->setSubject("Emergency Service Request – " . ($input['firstName'] ?? '') . " " . ($input['lastName'] ?? ''));
    $adminEmail->addTo("deepak@imarkinfotech.com", "Admin");
    $adminEmail->addContent("text/plain", $messageBody);
    $adminEmail->addContent("text/html", $htmlContent);

    $sendgrid->send($adminEmail);

    // -------------------------------------------
    // 2️⃣ Send confirmation email to User
    // -------------------------------------------
    if (!empty($input['email'])) {
        $userEmail = new Mail();
        $userEmail->setFrom("website@mail.facilitynetwork.com", "Facility Network");
        $userEmail->setSubject("Your Emergency Service Request Received");
        $userEmail->addTo($input['email'], $input['firstName'] ?? '');
        $userHtml = "<h2>Hi " . htmlspecialchars($input['firstName'] ?? '') . ",</h2>";
        $userHtml .= "<p>Thank you for your request. We have received your submission with the following details:</p>";
        foreach ($input as $key => $val) {
            $userHtml .= "<p><strong>" . ucfirst($key) . ":</strong> " . htmlspecialchars($val) . "</p>";
        }
        $userHtml .= "<p>We will contact you shortly.</p>";

        $userEmail->addContent("text/plain", "Thank you! We received your request.");
        $userEmail->addContent("text/html", $userHtml);

        $sendgrid->send($userEmail);
    }

    echo json_encode(['clientSecret' => $paymentIntent->client_secret]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
