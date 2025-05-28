<?php
    // === LINE Access Token (ถ้าใช้ LINE bot) ===
    $LINE_ACCESS_TOKEN = 'eWDMnANLgKQQe92qhypOz8Ze3Zefl2BNIlihPWXxCn6J95J9MR6tYvyEdrnGPSi3Sj7E3DQQR38j/rioJ/FNWEar7Wh06ejj5/NTJ40CKs6JEWtQ8iVo3aTmQU0eWTQ1PZ6AJdPWammiQlsdI6pFhgdB04t89/1O/w1cDnyilFU=';

    // === MySQL Connection ===
    $host = 'localhost';
    $db   = 'sjgripco_line_reports';
    $user = 'sjgripco_line_reports';
    $pass = 'K081614776k';

    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
?>