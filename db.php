<?php
$link = mysqli_connect('localhost', 'username', 'password');
if (mysqli_connect_errno() > 0) {
    echo "DB Connection Error";
    exit;
}
mysqli_select_db($link, 'database_name');
mysqli_set_charset($link, 'utf8');

// PDO connection (for libraries requiring PDO)
try {
    $pdo = new PDO('mysql:host=localhost;dbname=database_name;charset=utf8', 'username', 'password');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'PDO Connection Error';
    exit;
}
