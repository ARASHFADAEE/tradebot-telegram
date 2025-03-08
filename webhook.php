<?php
require_once 'functions.php';

// دریافت داده‌های ورودی
$update = json_decode(file_get_contents('php://input'), true);

// ثبت لاگ برای دیباگ
file_put_contents('bot_log.txt', date('Y-m-d H:i:s') . ': ' . json_encode($update) . "\n", FILE_APPEND);

// بررسی نوع پیام دریافتی
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $user = $message['from'];
    $text = $message['text'] ?? '';
    
    // دریافت یا ایجاد کاربر در دیتابیس
    $db_user = getOrCreateUser($user);
    
    if (!$db_user) {
        sendMessage($chat_id, "خطا در ثبت اطلاعات کاربر. لطفاً دوباره تلاش کنید.");
        exit;
    }
    
    // بررسی دستور /start
    if ($text === '/start') {
        // بررسی وضعیت احراز هویت
        if (isUserVerified($db_user['id'])) {
            // بررسی وضعیت اشتراک
            $subscription = checkSubscription($db_user['id']);
            
            if ($subscription) {
                // کاربر اشتراک فعال دارد
                $end_date = date('Y-m-d', strtotime($subscription['end_date']));
                $message = "✅ شما عضو فعال گروه VIP ما هستید.\n\n";
                $message .= "تاریخ پایان اشتراک: {$end_date}\n\n";
                $message .= "لینک گروه VIP:\n" . GROUP_INVITE_LINK;
                
                sendMessage($chat_id, $message);
            } else {
                // کاربر نیاز به پرداخت دارد
                $discount = calculateDiscount($db_user['id']);
                $final_price = SUBSCRIPTION_PRICE - (SUBSCRIPTION_PRICE * $discount / 100);
                
                $message = PAYMENT_MESSAGE . "\n\n";
                
                if ($discount > 0) {
                    $message .= "🎁 تبریک! شما {$discount}% تخفیف دارید!\n";
                    $message .= "💰 قیمت اصلی: " . number_format(SUBSCRIPTION_PRICE) . " تومان\n";
                    $message .= "💰 قیمت با تخفیف: " . number_format($final_price) . " تومان\n\n";
                } else {
                    $message .= "💰 قیمت اشتراک: " . number_format(SUBSCRIPTION_PRICE) . " تومان\n\n";
                }
                
                $payment_link = createPaymentLink($db_user['id'], SUBSCRIPTION_PRICE, $discount);
                
                if ($payment_link) {
                    sendMessage($chat_id, $message, getPaymentKeyboard($payment_link));
                } else {
                    sendMessage($chat_id, "متأسفانه در ایجاد لینک پرداخت مشکلی پیش آمده است. لطفاً بعداً دوباره تلاش کنید.");
                }
            }
        } else {
            // نیاز به احراز هویت
            $message = WELCOME_MESSAGE . "\n\n";
            $message .= "برای استفاده از امکانات ربات، لطفاً شماره تلفن خود را به اشتراک بگذارید:";
            
            sendMessage($chat_id, $message, getShareContactKeyboard());
        }
    }
    
    // دریافت شماره تلفن
    if (isset($message['contact'])) {
        $contact = $message['contact'];
        
        // بررسی تطابق شماره تلفن با کاربر فعلی
        if ($contact['user_id'] == $user['id']) {
            $phone_number = $contact['phone_number'];
            
            if (verifyUser($db_user['id'], $phone_number)) {
                sendMessage($chat_id, "✅ شماره تلفن شما با موفقیت ثبت شد.", removeKeyboard());
                
                // نمایش صفحه پرداخت
                $discount = calculateDiscount($db_user['id']);
                $final_price = SUBSCRIPTION_PRICE - (SUBSCRIPTION_PRICE * $discount / 100);
                
                $message = PAYMENT_MESSAGE . "\n\n";
                
                if ($discount > 0) {
                    $message .= "🎁 تبریک! شما {$discount}% تخفیف دارید!\n";
                    $message .= "💰 قیمت اصلی: " . number_format(SUBSCRIPTION_PRICE) . " تومان\n";
                    $message .= "💰 قیمت با تخفیف: " . number_format($final_price) . " تومان\n\n";
                } else {
                    $message .= "💰 قیمت اشتراک: " . number_format(SUBSCRIPTION_PRICE) . " تومان\n\n";
                }
                
                $payment_link = createPaymentLink($db_user['id'], SUBSCRIPTION_PRICE, $discount);
                
                if ($payment_link) {
                    sendMessage($chat_id, $message, getPaymentKeyboard($payment_link));
                } else {
                    sendMessage($chat_id, "متأسفانه در ایجاد لینک پرداخت مشکلی پیش آمده است. لطفاً بعداً دوباره تلاش کنید.");
                }
            } else {
                sendMessage($chat_id, "متأسفانه در ثبت شماره تلفن مشکلی پیش آمده است. لطفاً دوباره تلاش کنید.", getShareContactKeyboard());
            }
        } else {
            sendMessage($chat_id, "لطفاً شماره تلفن خود را به اشتراک بگذارید.", getShareContactKeyboard());
        }
    }
} elseif (isset($update['callback_query'])) {
    // پردازش کلیک روی دکمه‌های اینلاین
    $callback_query = $update['callback_query'];
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $data = $callback_query['data'];
    $user = $callback_query['from'];
    
    // دریافت یا ایجاد کاربر در دیتابیس
    $db_user = getOrCreateUser($user);
    
    // پردازش داده‌های دکمه اینلاین
    if (strpos($data, 'payment_') === 0) {
        $payment_id = substr($data, 8);
        // پردازش پرداخت (اگر نیاز باشد)
    }
    
    // پاسخ به کلیک دکمه
    makeRequest('answerCallbackQuery', [
        'callback_query_id' => $callback_query['id'],
        'text' => 'درخواست شما در حال پردازش است...'
    ]);
}