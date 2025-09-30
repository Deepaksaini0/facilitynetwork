<?php

// Allow your Webflow domain to access
header("Access-Control-Allow-Origin: https://www.facilitynetwork.com/");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// -------------------------------------------
// Load Composer dependencies
// -------------------------------------------
require __DIR__ . '/vendor/autoload.php';

\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));
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
        'metadata' => [
            'First Name'      => $input['firstName'] ?? '',
            'Last Name'       => $input['lastName'] ?? '',
            'Company Name'    => $input['companyName'] ?? '',
            'Email'           => $input['email'] ?? '',
            'Phone'           => $input['phone'] ?? '',
            'Site Address'    => $input['siteAddress'] ?? '',
            'Trade Type'      => $input['tradeType'] ?? '',
            'Issue'           => $input['issueDescription'] ?? '',
        ]
    ]);

    // -------------------------------------------
    // Mailgun setup
    // -------------------------------------------
    $mgClient = Mailgun::create(getenv('MAILGUN_API_KEY'));
    $domain   = getenv('MAILGUN_DOMAIN');

    // Optional: Send email notification
    $to = "deepak@imarkinfotech.com";
    $subject = "Emergency Service Request – " . ($input['firstName'] ?? '') . " " . ($input['lastName'] ?? '');
    $message = "New hotline request with $" . ($input['holdAmount'] ?? 0) . " hold:\n\n";
    foreach ($input as $key => $val) {
        $message .= ucfirst($key) . ": " . $val . "\n";
    }
    $headers = "From: no-reply@clientsdevsite.com\r\n";
    mail($to, $subject, $message, $headers);

    echo json_encode(['clientSecret' => $paymentIntent->client_secret]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
