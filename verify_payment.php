<?php
require_once 'functions.php';

// دریافت شناسه پرداخت
$payment_id = $_GET['payment_id'] ?? null;
$success = false;
$message = '';

if (!$payment_id) {
    $message = 'خطا: شناسه پرداخت نامعتبر است.';
} else {
    try {
        // دریافت اطلاعات پرداخت
        $stmt = $db->prepare("SELECT * FROM `payments` WHERE `id` = :payment_id");
        $stmt->execute(['payment_id' => $payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            $message = 'خطا: پرداخت مورد نظر یافت نشد.';
        } else if ($payment['status'] == 'completed') {
            $success = true;
            $message = 'این پرداخت قبلاً تأیید شده است.';
        } else {
            // دریافت پارامترهای بازگشتی از درگاه زیبال
            $trackId = $_GET['trackId'] ?? null;
            $success_code = $_GET['success'] ?? 0;
            $orderId = $_GET['orderId'] ?? null;
            
            if ($success_code == 1 && $trackId && $orderId == $payment_id) {
                // استعلام وضعیت پرداخت از زیبال
                $data = [
                    'merchant' => ZIBAL_MERCHANT,
                    'trackId' => $trackId
                ];
                
                $jsonData = json_encode($data);
                $ch = curl_init('https://gateway.zibal.ir/v1/verify');
                curl_setopt($ch, CURLOPT_USERAGENT, 'ZibalBot');
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);
                
                $result = curl_exec($ch);
                curl_close($ch);
                $result = json_decode($result, true);
                
                if ($result['result'] == 100) {
                    // پرداخت موفق
                    $stmt = $db->prepare("UPDATE `payments` SET `status` = 'completed', `zibal_track_id` = :track_id WHERE `id` = :payment_id");
                    $stmt->execute([
                        'track_id' => $trackId,
                        'payment_id' => $payment_id
                    ]);
                    
                    // ایجاد اشتراک برای کاربر
                    $subscription_id = createSubscription(
                        $payment['user_id'],
                        $payment['amount'],
                        $payment['discount_amount'] > 0 ? ($payment['discount_amount'] / $payment['amount'] * 100) : 0
                    );
                    
                    if ($subscription_id) {
                        // دریافت اطلاعات کاربر
                        $stmt = $db->prepare("SELECT `telegram_id` FROM `users` WHERE `id` = :user_id");
                        $stmt->execute(['user_id' => $payment['user_id']]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            // ارسال پیام موفقیت به کاربر
                            addUserToVipGroup($user['telegram_id'], $payment['user_id']);
                        }
                        
                        $success = true;
                        $message = 'پرداخت با موفقیت انجام شد. اشتراک شما فعال شد.';
                    } else {
                        $message = 'پرداخت انجام شد اما در فعال‌سازی اشتراک مشکلی پیش آمده است.';
                    }
                } else {
                    $message = 'خطا در تأیید پرداخت: ' . ($result['message'] ?? 'خطای نامشخص');
                }
            } else {
                $message = 'پرداخت ناموفق بود یا توسط کاربر لغو شده است.';
            }
        }
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        $message = 'خطا در پردازش پرداخت. لطفاً با پشتیبانی تماس بگیرید.';
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتیجه پرداخت</title>
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
            text-align: center;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background-color: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        .button {
            display: inline-block;
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            margin-top: 20px;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= $success ? 'پرداخت موفق' : 'خطا در پرداخت' ?></h1>
        <p class="<?= $success ? 'success' : 'error' ?>"><?= $message ?></p>
        <a href="https://t.me/<?= str_replace('bot', '', BOT_TOKEN) ?>" class="button">بازگشت به ربات</a>
    </div>
    <script>
        setTimeout(function() {
            window.location.href = "https://t.me/<?= str_replace('bot', '', BOT_TOKEN) ?>";
        }, 5000);
    </script>
</body>
</html>