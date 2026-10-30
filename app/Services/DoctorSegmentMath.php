<?php
namespace App\Services;
use Carbon\Carbon;
class DoctorSegmentMath
{
    public function effectiveStart(Carbon $sessionIn, Carbon $shiftStart): Carbon { return $sessionIn->gt($shiftStart)?$sessionIn->copy():$shiftStart->copy(); }
    public function lateMinutes(Carbon $sessionIn, Carbon $shiftStart): int { return $sessionIn->gt($shiftStart)?(int)$shiftStart->diffInMinutes($sessionIn):0; }
    public function segmentClockOut(Carbon $sessionOut, Carbon $shiftEnd, Carbon $latestShiftEnd): Carbon
    {
        if($sessionOut->lte($shiftEnd)) return $sessionOut->copy();
        if($shiftEnd->lt($latestShiftEnd)) return $shiftEnd->copy();
        return $sessionOut->copy();
    }
    public function overlapSeconds(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): int
    {
        $start=$aStart->gt($bStart)?$aStart:$bStart; $end=$aEnd->lt($bEnd)?$aEnd:$bEnd;
        return $end->gt($start)?$end->timestamp-$start->timestamp:0;
    }
}
