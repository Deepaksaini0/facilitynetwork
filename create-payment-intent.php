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

    // Build HTML email with dynamic data
    $userMessage = '
    <body style="margin:0; padding:0; background-color:#f5f7fa; font-family: \'Instrument Sans\', serif; color:#333333;">
      <style>
    @import url(\'https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap\');
    .hotline-btn {
      display:inline-block; 
      background-color:#f29d42; 
      color:#0c2640; 
      text-decoration:none; 
      padding:12px 24px; 
      border-radius:0px; 
      font-size:18px;
      font-weight:500;
      transition: all .3s;
    }
    .hotline-btn:hover {
      background-color: #002F5D;
      color: #fff;
    }
    </style>
      <div style="max-width:600px; margin:30px auto; background-color:#ffffff; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.1); overflow:hidden;">
        <div style="background-color:#F1F5F9; padding:20px; text-align:center;">
          <img src="https://cdn.prod.website-files.com/67bdcc49ab6786335e920527/67bdcc49ab6786335e9205b4_Facility%20Network%20Logo.svg" alt="Facility Network" style="max-width:200px; height:auto; display:inline-block;" />
        </div>
        <div style="padding:25px 30px 0 30px;">
          <h1 style="margin:0; font-size:24px; font-weight:600; color:#002F5D; line-height:1.2;">
            Emergency Service Request – Please Call Our Hotline
          </h1>
        </div>
        <div style="padding:20px 30px; font-size:16px; line-height:1.6;">
          <p style="margin:0 0 15px;">
            Hi <strong style="color:#002F5D">' . htmlspecialchars($input['firstName'] ?? '') . '</strong>,
          </p>
          <p style="margin:0 0 15px;">
            We’ve received your emergency service request. <strong style="color:#002F5D; font-weight: 700;">Please call our hotline at 
            <a href="tel:+19056254401" style="color:#002F5D; text-decoration: none;">905-625-4401</a>, if you haven\'t already.</strong> This ensures our dispatch team can assist you right away.
          </p>
        </div>
        <div style="padding:0 30px 20px 30px;">
          <div style="background-color:#F8FAFC; border:1px solid #E5E7EB; border-radius:6px; padding:20px;">
            <h2 style="margin:0 0 15px; font-size:18px; font-weight:600; color:#002F5D;">Request Summary</h2>
            <p style="margin:0 0 10px; font-size:15px; color:#333;">
              <strong>Trade Type:</strong> ' . htmlspecialchars($input['tradeType'] ?? '') . '
            </p>
            <p style="margin:0 0 10px; font-size:15px; color:#333;">
              <strong>Description:</strong> ' . htmlspecialchars($input['issueDescription'] ?? '') . '
            </p>
            <p style="margin:0; font-size:15px; color:#333;">
              <strong>Site Address:</strong> ' . htmlspecialchars($input['siteAddress'] ?? '') . '
            </p>
          </div>
        </div>
        <div style="padding:0 30px 20px 30px; text-align:center;">
          <a href="tel:+19056254401" class="hotline-btn">Call Hotline Now</a>
        </div>
        <div style="padding:0 30px 20px 30px; font-size:15px; color:#333; line-height:1.5;">
          <p style="margin:0 0 15px;">
            If you were redirected or closed the webpage, don’t worry — you can still connect with us by calling the hotline number above.
          </p>
          <p style="margin:0;">Kind regards,<br>
            <strong style="color: #002F5D;">Facility Network Dispatch Team</strong>
          </p>
        </div>
        <div style="background-color:#005FA8; padding:20px 30px; font-size:13px; color:#fff; text-align:center;">
          <p style="margin:0 0 10px;">
            <a href="https://www.facilitynetwork.com" style="font-size: 18px; color: #fff; text-decoration: none;">Facility Network</a>
          </p>
          <p style="margin:0;">
            <a href="https://www.facilitynetwork.com/privacy-policy" style="color:#fff; text-decoration:none;">Privacy Policy</a> •
            <a href="https://www.facilitynetwork.com/terms-of-service" style="color:#fff; text-decoration:none;">Terms of Service</a>
          </p>
        </div>
      </div>
    </body>';

    // Send HTML email via Mailgun
    $mgClient->messages()->send($domain, [
        'from'    => 'no-reply@deepak.com',
        'to'      => $userTo,
        'subject' => $userSubject,
        'html'    => $userMessage
    ]);
}

    echo json_encode(['clientSecret' => $paymentIntent->client_secret]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
