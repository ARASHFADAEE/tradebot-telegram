<?php
require_once 'config.php';

/**
 * ارسال درخواست به API تلگرام
 */
function makeRequest($method, $params = []) {
    $url = API_URL . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, count($params));
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

/**
 * ارسال پیام به کاربر
 */
function sendMessage($chat_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($reply_markup) {
        $params['reply_markup'] = $reply_markup;
    }
    
    return makeRequest('sendMessage', $params);
}

/**
 * دریافت یا ایجاد کاربر در دیتابیس
 */
function getOrCreateUser($user_data) {
    global $db;
    
    $telegram_id = $user_data['id'];
    $first_name = $user_data['first_name'] ?? '';
    $last_name = $user_data['last_name'] ?? '';
    $username = $user_data['username'] ?? '';
    
    try {
        // بررسی وجود کاربر
        $stmt = $db->prepare("SELECT * FROM `users` WHERE `telegram_id` = :telegram_id");
        $stmt->execute(['telegram_id' => $telegram_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // به‌روزرسانی اطلاعات کاربر
            $stmt = $db->prepare("UPDATE `users` SET `first_name` = :first_name, `last_name` = :last_name, `username` = :username, `last_activity` = NOW() WHERE `telegram_id` = :telegram_id");
            $stmt->execute([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'username' => $username,
                'telegram_id' => $telegram_id
            ]);
            return $user;
        } else {
            // ایجاد کاربر جدید
            $stmt = $db->prepare("INSERT INTO `users` (`telegram_id`, `first_name`, `last_name`, `username`) VALUES (:telegram_id, :first_name, :last_name, :username)");
            $stmt->execute([
                'telegram_id' => $telegram_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'username' => $username
            ]);
            
            return [
                'id' => $db->lastInsertId(),
                'telegram_id' => $telegram_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'username' => $username,
                'is_verified' => 0
            ];
        }
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return null;
    }
}

/**
 * بررسی وضعیت احراز هویت کاربر
 */
function isUserVerified($user_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT `is_verified` FROM `users` WHERE `id` = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result && $result['is_verified'] == 1;
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return false;
    }
}

/**
 * ثبت شماره تلفن و تأیید کاربر
 */
function verifyUser($user_id, $phone_number) {
    global $db;
    
    try {
        $stmt = $db->prepare("UPDATE `users` SET `phone_number` = :phone_number, `is_verified` = 1 WHERE `id` = :user_id");
        $stmt->execute([
            'phone_number' => $phone_number,
            'user_id' => $user_id
        ]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return false;
    }
}

/**
 * بررسی وضعیت اشتراک کاربر
 */
function checkSubscription($user_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM `subscriptions` 
            WHERE `user_id` = :user_id 
            AND `is_active` = 1 
            AND `end_date` > NOW() 
            ORDER BY `end_date` DESC 
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return null;
    }
}

/**
 * محاسبه تخفیف برای کاربر
 */
function calculateDiscount($user_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as renewal_count FROM `subscriptions` WHERE `user_id` = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $renewal_count = $result['renewal_count'] ?? 0;
        
        // 10 درصد تخفیف برای هر بار تمدید (حداکثر 30 درصد)
        $discount = min(10 * $renewal_count, 30);
        
        return $discount;
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return 0;
    }
}

/**
 * ایجاد لینک پرداخت زیبال
 */
function createPaymentLink($user_id, $amount, $discount = 0) {
    global $db;
    
    try {
        // محاسبه مبلغ نهایی با تخفیف
        $final_amount = $amount - ($amount * $discount / 100);
        
        // ایجاد رکورد پرداخت در دیتابیس
        $stmt = $db->prepare("
            INSERT INTO `payments` (`user_id`, `amount`, `discount_amount`, `status`, `description`) 
            VALUES (:user_id, :amount, :discount_amount, 'pending', :description)
        ");
        $stmt->execute([
            'user_id' => $user_id,
            'amount' => $final_amount,
            'discount_amount' => $amount * $discount / 100,
            'description' => 'پرداخت اشتراک VIP - ' . ($discount > 0 ? "با {$discount}% تخفیف" : "بدون تخفیف")
        ]);
        
        $payment_id = $db->lastInsertId();
        
        // ایجاد لینک پرداخت زیبال
        $callback_url = "https://yourdomain.com/vip_bot/verify_payment.php?payment_id={$payment_id}";
        
        $data = [
            'merchant' => ZIBAL_MERCHANT,
            'amount' => $final_amount * 10, // تبدیل به ریال
            'callbackUrl' => $callback_url,
            'description' => 'پرداخت اشتراک VIP',
            'orderId' => $payment_id
        ];
        
        $jsonData = json_encode($data);
        $ch = curl_init('https://gateway.zibal.ir/v1/request');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZibalBot');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);
        
        $result = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($result, true);
        
        if ($result['result'] == 100) {
            // به‌روزرسانی شناسه تراکنش در دیتابیس
            $stmt = $db->prepare("UPDATE `payments` SET `transaction_id` = :transaction_id WHERE `id` = :payment_id");
            $stmt->execute([
                'transaction_id' => $result['trackId'],
                'payment_id' => $payment_id
            ]);
            
            return "https://gateway.zibal.ir/start/" . $result['trackId'];
        } else {
            error_log("Zibal Error: " . json_encode($result));
            return null;
        }
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return null;
    }
}

/**
 * ایجاد اشتراک جدید برای کاربر
 */
function createSubscription($user_id, $payment_amount, $discount_percent = 0) {
    global $db;
    
    try {
        // بررسی تعداد تمدیدهای قبلی
        $stmt = $db->prepare("SELECT COUNT(*) as renewal_count FROM `subscriptions` WHERE `user_id` = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $renewal_count = $result['renewal_count'] ?? 0;
        
        // ایجاد اشتراک جدید
        $stmt = $db->prepare("
            INSERT INTO `subscriptions` (`user_id`, `start_date`, `end_date`, `discount_percent`, `renewal_count`, `payment_amount`) 
            VALUES (:user_id, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), :discount_percent, :renewal_count, :payment_amount)
        ");
        $stmt->execute([
            'user_id' => $user_id,
            'discount_percent' => $discount_percent,
            'renewal_count' => $renewal_count,
            'payment_amount' => $payment_amount
        ]);
        
        return $db->lastInsertId();
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return null;
    }
}

/**
 * اضافه کردن کاربر به گروه VIP
 */
function addUserToVipGroup($chat_id, $user_id) {
    // ارسال لینک دعوت به کاربر
    $message = "🎉 تبریک! پرداخت شما با موفقیت انجام شد.\n\n";
    $message .= "شما می‌توانید از طریق لینک زیر وارد گروه VIP شوید:\n";
    $message .= GROUP_INVITE_LINK;
    
    sendMessage($chat_id, $message);
    
    return true;
}

/**
 * ایجاد دکمه شیشه‌ای برای پرداخت
 */
function getPaymentKeyboard($payment_link) {
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '💳 پرداخت آنلاین', 'url' => $payment_link]
            ]
        ]
    ]);
}

/**
 * ایجاد دکمه اشتراک شماره تلفن
 */
function getShareContactKeyboard() {
    return json_encode([
        'keyboard' => [
            [
                ['text' => '📱 ارسال شماره تلفن', 'request_contact' => true]
            ]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ]);
}

/**
 * حذف کیبورد
 */
function removeKeyboard() {
    return json_encode([
        'remove_keyboard' => true
    ]);
}