<?php

    $conn = mysqli_connect('localhost', 'root', 'password', 'dbname');
    if(!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }
?>