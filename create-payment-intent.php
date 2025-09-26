<?php
use Mailgun\Mailgun;
// -------------------------------------------
// CORS Handling
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

// -------------------------------------------
// Stripe PaymentIntent
// -------------------------------------------
try {
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
            'Card Name'       => $input['cardName'] ?? '',
            'Billing Postal'  => $input['billingPostal'] ?? '',
            'Hotline File'    => $input['hotlinefile'] ?? ''
        ]
    ]);

    // -------------------------------------------
    // Send Email via Mailgun
    // -------------------------------------------
    

    $mgClient = Mailgun::create(getenv('MAILGUN_API_KEY'));
    $domain   = getenv('MAILGUN_DOMAIN');

    $to = "deepak@imarkinfotech.com";
    $subject = "Emergency Service Request – " . ($input['firstName'] ?? '') . " " . ($input['lastName'] ?? '');
    $messageBody = "New hotline request with $" . ($input['holdAmount'] ?? 0) . " hold:\n\n";
    foreach ($input as $key => $val) {
        $messageBody .= ucfirst($key) . ": " . $val . "\n";
    }

    $mgClient->messages()->send($domain, [
        'from'    => 'no-reply@facilitynetwork.com',
        'to'      => $to,
        'subject' => $subject,
        'text'    => $messageBody
    ]);

    echo json_encode(['clientSecret' => $paymentIntent->client_secret]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
