<?php
session_start();
session_destroy();
// Redirect to login page (hash will be handled by SPA)
header('Location: index.php#login');
exit;