<?php
// تنظیمات دیتابیس
define('DB_HOST', 'localhost');
define('DB_NAME', 'volnamus_hesambot');
define('DB_USER', 'volnamus_hesambot');
define('DB_PASS', 'l,}FcNb&V0lR');

// تنظیمات ربات
$settings = [];
try {
    $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->query("SELECT `key`, `value` FROM `settings`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده");
}

// تنظیمات ربات
define('BOT_TOKEN', $settings['bot_token'] ?? '');
define('ZIBAL_MERCHANT', $settings['zibal_merchant'] ?? '');
define('GROUP_INVITE_LINK', $settings['group_invite_link'] ?? '');
define('SUBSCRIPTION_PRICE', $settings['subscription_price'] ?? '100000');
define('WELCOME_MESSAGE', $settings['welcome_message'] ?? 'به ربات ما خوش آمدید');
define('PAYMENT_MESSAGE', $settings['payment_message'] ?? 'برای عضویت در گروه VIP باید هزینه پرداخت کنید');

// تنظیمات API تلگرام
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
define('WEBHOOK_URL', 'https://yourdomain.com/vip_bot/webhook.php');