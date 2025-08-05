<?php
include '../db.php';
$result = mysqli_query($link, "SELECT id, name FROM beds ORDER BY name");
echo json_encode(mysqli_fetch_all($result, MYSQLI_ASSOC));
?>
