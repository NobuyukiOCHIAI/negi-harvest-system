<?php
$link = mysqli_connect('mysql470.db.sakura.ne.jp', 'love-media', 'EuvaMLe8');
if (mysqli_connect_errno() > 0) { echo "DB Connection Error"; exit; }
mysqli_select_db($link, 'love-media_dp');
mysqli_set_charset($link, 'utf8');
