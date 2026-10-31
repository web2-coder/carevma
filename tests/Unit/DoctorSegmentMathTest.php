<?php

namespace Tests\Unit;

use App\Services\DoctorSegmentMath;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DoctorSegmentMathTest extends TestCase
{
    private DoctorSegmentMath $math;

    protected function setUp(): void
    {
        parent::setUp();
        $this->math = new DoctorSegmentMath();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse('2026-10-30 '.$time, 'UTC');
    }

    public function test_late_minutes_are_doctor_specific(): void
    {
        $this->assertSame(0, $this->math->lateMinutes($this->at('09:00'), $this->at('09:00')));
        $this->assertSame(0, $this->math->lateMinutes($this->at('09:00'), $this->at('10:00')));

        $this->assertSame(20, $this->math->lateMinutes($this->at('09:20'), $this->at('09:00')));
        $this->assertSame(0, $this->math->lateMinutes($this->at('09:20'), $this->at('10:00')));

        $this->assertSame(75, $this->math->lateMinutes($this->at('10:15'), $this->at('09:00')));
        $this->assertSame(15, $this->math->lateMinutes($this->at('10:15'), $this->at('10:00')));
    }

    public function test_earlier_doctor_stops_at_own_shift_end_but_latest_doctor_can_carry_overtime(): void
    {
        $sessionOut = $this->at('17:30');
        $latestEnd = $this->at('17:00');

        $emmaOut = $this->math->segmentClockOut($sessionOut, $this->at('13:00'), $latestEnd);
        $harramOut = $this->math->segmentClockOut($sessionOut, $this->at('17:00'), $latestEnd);

        $this->assertSame('13:00', $emmaOut->format('H:i'));
        $this->assertSame('17:30', $harramOut->format('H:i'));
    }

    public function test_break_overlap_is_allocated_only_to_doctors_active_during_the_break(): void
    {
        $harramStart = $this->at('09:00');
        $harramEnd = $this->at('17:00');
        $emmaStart = $this->at('10:00');
        $emmaEnd = $this->at('13:00');

        $this->assertSame(1800, $this->math->overlapSeconds($this->at('11:00'), $this->at('11:30'), $harramStart, $harramEnd));
        $this->assertSame(1800, $this->math->overlapSeconds($this->at('11:00'), $this->at('11:30'), $emmaStart, $emmaEnd));

        $this->assertSame(1800, $this->math->overlapSeconds($this->at('13:15'), $this->at('13:45'), $harramStart, $harramEnd));
        $this->assertSame(0, $this->math->overlapSeconds($this->at('13:15'), $this->at('13:45'), $emmaStart, $emmaEnd));
    }
}
