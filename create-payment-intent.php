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

    // --- Admin email ---
    $adminTo = "deepak@imarkinfotech.com";
    $adminSubject = "New Emergency Service Request – " . ($input['firstName'] ?? '') . " " . ($input['lastName'] ?? '');
    $adminMessage = "New hotline request with $" . ($input['holdAmount'] ?? 0) . " hold:\n\n";
    foreach ($input as $key => $val) {
        $adminMessage .= ucfirst($key) . ": " . $val . "\n";
    }

    $mgClient->messages()->send($domain, [
        'from'    => 'no-reply@deepak.com',
        'to'      => $adminTo,
        'subject' => $adminSubject,
        'text'    => $adminMessage
    ]);

    // --- User confirmation email ---
    $userTo = $input['email'] ?? null;
    if ($userTo) {
        $userSubject = "Your Emergency Service Request Received";
        
        // Simple plain text message
        $userMessage = "Hi " . ($input['firstName'] ?? '') . ",\n\n";
        $userMessage .= "We have received your emergency service request with the following details:\n\n";
        $userMessage .= "Name: " . ($input['firstName'] ?? '') . " " . ($input['lastName'] ?? '') . "\n";
        $userMessage .= "Company: " . ($input['companyName'] ?? '') . "\n";
        $userMessage .= "Email: " . ($input['email'] ?? '') . "\n";
        $userMessage .= "Phone: " . ($input['phone'] ?? '') . "\n";
        $userMessage .= "Site Address: " . ($input['siteAddress'] ?? '') . "\n";
        $userMessage .= "Trade Type: " . ($input['tradeType'] ?? '') . "\n";
        $userMessage .= "Issue: " . ($input['issueDescription'] ?? '') . "\n\n";
        $userMessage .= "Please call our hotline at 905-625-4401 for immediate assistance.\n\n";
        $userMessage .= "Thank you,\nFacility Network Dispatch Team";

        $mgClient->messages()->send($domain, [
            'from'    => 'no-reply@deepak.com',
            'to'      => $userTo,
            'subject' => $userSubject,
            'text'    => $userMessage
        ]);
    }

    echo json_encode(['clientSecret' => $paymentIntent->client_secret]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
