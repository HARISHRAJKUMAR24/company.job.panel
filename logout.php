<?php
session_start();

// Destroy all session data
$_SESSION = array();
session_destroy();

// Redirect to home
header('Location: index.php?logged_out=1');
exit;
?>