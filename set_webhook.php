<?php
require_once 'config.php';

// تنظیم وب‌هوک
$result = makeRequest('setWebhook', [
    'url' => WEBHOOK_URL,
    'allowed_updates' => json_encode(['message', 'callback_query'])
]);

echo '<pre>';
print_r($result);
echo '</pre>';

if ($result['ok']) {
    echo "Webhook set successfully!";
} else {
    echo "Error setting webhook: " . ($result['description'] ?? 'Unknown error');
}

function makeRequest($method, $params = []) {
    $url = API_URL . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, count($params));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}