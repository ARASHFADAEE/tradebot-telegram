<?php
require_once 'functions.php';

/**
 * این فایل باید به صورت روزانه توسط کرون‌جاب اجرا شود
 */

// بررسی اشتراک‌های منقضی شده برای اخراج از گروه
try {
    $stmt = $db->query("
        SELECT s.*, u.telegram_id, u.first_name, u.last_name, u.username 
        FROM `subscriptions` s
        JOIN `users` u ON s.user_id = u.id
        WHERE s.end_date < NOW() 
        AND s.is_active = 1 
        AND s.kicked_from_channel = 0
    ");
    
    while ($subscription = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // اخراج کاربر از گروه
        makeRequest('kickChatMember', [
            'chat_id' => '@your_channel_username', // نام کاربری کانال یا گروه
            'user_id' => $subscription['telegram_id'],
            'until_date' => time() + 30 // اخراج موقت (30 ثانیه)
        ]);
        
        // به‌روزرسانی وضعیت اشتراک
        $update = $db->prepare("UPDATE `subscriptions` SET `kicked_from_channel` = 1, `is_active` = 0 WHERE `id` = :id");
        $update->execute(['id' => $subscription['id']]);
        
        // ارسال پیام به کاربر
        $message = "⚠️ اشتراک شما در گروه VIP به پایان رسیده است.\n\n";
        $message .= "برای تمدید اشتراک و دسترسی مجدد به گروه، لطفاً دستور /start را ارسال کنید.";
        
        sendMessage($subscription['telegram_id'], $message);
        
        // لاگ
        error_log("User kicked: " . $subscription['telegram_id'] . " - " . $subscription['first_name'] . " " . $subscription['last_name']);
    }
} catch (PDOException $e) {
    error_log("Database Error (Expired Subscriptions): " . $e->getMessage());
}

// بررسی اشتراک‌های نزدیک به انقضا برای ارسال یادآوری
try {
    $stmt = $db->query("
        SELECT s.*, u.telegram_id, u.first_name, u.last_name, u.username 
        FROM `subscriptions` s
        JOIN `users` u ON s.user_id = u.id
        WHERE s.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) 
        AND s.is_active = 1 
        AND s.reminder_sent = 0
    ");
    
    while ($subscription = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // محاسبه روزهای باقی‌مانده
        $days_left = ceil((strtotime($subscription['end_date']) - time()) / (60 * 60 * 24));
        
        // ارسال پیام یادآوری
        $message = "⚠️ یادآوری: اشتراک شما در گروه VIP تا {$days_left} روز دیگر معتبر است.\n\n";
        $message .= "برای جلوگیری از قطع دسترسی، لطفاً اشتراک خود را تمدید کنید.\n";
        $message .= "برای تمدید اشتراک، دستور /start را ارسال کنید.";
        
        sendMessage($subscription['telegram_id'], $message);
        
        // به‌روزرسانی وضعیت یادآوری
        $update = $db->prepare("UPDATE `subscriptions` SET `reminder_sent` = 1 WHERE `id` = :id");
        $update->execute(['id' => $subscription['id']]);
        
        // لاگ
        error_log("Reminder sent: " . $subscription['telegram_id'] . " - " . $subscription['first_name'] . " " . $subscription['last_name']);
    }
} catch (PDOException $e) {
    error_log("Database Error (Reminder): " . $e->getMessage());
}

echo "Cron job executed successfully at " . date('Y-m-d H:i:s');