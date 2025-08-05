<?php
include '../db.php';
$result = mysqli_query($link, "SELECT id, name FROM loss_types ORDER BY id");
echo json_encode(mysqli_fetch_all($result, MYSQLI_ASSOC));
?>
