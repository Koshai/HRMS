<?php

return [
    'display_timezone' => env('HRMS_DISPLAY_TIMEZONE', 'Asia/Dhaka'),
    'attendance_manual_past_days' => (int) env('HRMS_ATTENDANCE_MANUAL_PAST_DAYS', 90),
    'attendance_future_minutes' => (int) env('HRMS_ATTENDANCE_FUTURE_MINUTES', 5),
];
