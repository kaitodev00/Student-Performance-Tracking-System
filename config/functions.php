<?php

function headerName(array $arr, $key): void {
    if (array_key_exists($key, $arr)) {
        echo $arr[$key];
    } else {
        echo "No element found at index “{$key}.”";
    }
}


$header = [
    0 => 'PerfoMetrics',
    1 => 'Notifications',
    2 => 'Performance',
    3 => 'Surveys',
    4 => 'Profile',
    5 => 'Account',
    6 => 'Student Info',
    7 => 'Guardian Info',
    8 => 'Edit',
    9 => 'Forgot Password',
    10 => 'New Password',
    11 => 'Change Password',
];