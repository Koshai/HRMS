<?php

namespace App\Scheduling;

class DailyAttendanceSummary
{
    public function __construct(
        public readonly ScheduleResolution $schedule,
        public readonly string $status,
        public readonly int $actualMinutes,
        public readonly int $lateMinutes,
        public readonly int $earlyMinutes,
        public readonly bool $hasApprovedLeave,
        public readonly bool $hasPartialLeave,
    ) {}
}
