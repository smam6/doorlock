<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

use DoorLock\Security;

Security::applyHtmlHeaders();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>درب‌یاب — سیستم کنترل دسترسی</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div id="app">
        <noscript>
            <div style="padding:2rem;text-align:center;font-family:sans-serif">
                برای استفاده از پنل مدیریت، جاوااسکریپت را در مرورگر فعال کنید.
            </div>
        </noscript>
    </div>
    <script type="module" src="assets/js/app.js"></script>
</body>
</html>
