<?php
session_start();
// If already logged in, redirect to dashboard hash; otherwise login hash
$default_hash = isset($_SESSION['user_id']) ? '#dashboard' : '#login';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سیستم کنترل دسترسی درب‌ها</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/weekday-picker.css">
    <!-- Optional: Vazirmatn font from CDN -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
</head>
<body>
    <div id="app"></div>
    <script>
        // Pass the default hash to JavaScript
        window.DEFAULT_HASH = '<?php echo $default_hash; ?>';
    </script>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/weekday-picker.js"></script>
</body>
</html>