<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeBreak;
use App\Models\EmployeeClientSchedule;
use App\Models\WorkSession;
use App\Models\WorkSessionBreak;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkSessionService
{
    public function __construct(
        private TrustedTimeService $clock,
        private ClientTimezoneService $timezones,
        private AttendanceBillingService $billing,
        private DoctorSegmentMath $math,
    ) {}

    public function supported(): bool
    {
        return Schema::hasTable('work_sessions')
            && Schema::hasTable('work_session_breaks')
            && Schema::hasColumn('attendances', 'work_session_id');
    }

    public function openForEmployee(Employee|int $employee): ?WorkSession
    {
        if (!$this->supported()) return null;
        $employeeId = $employee instanceof Employee ? $employee->id : $employee;
        return WorkSession::query()->where('employee_id', $employeeId)->whereNull('clock_out')->latest('id')->first();
    }

    public function start(Employee $employee, EmployeeClientSchedule $selectedSchedule, Carbon $shiftStart, Carbon $shiftEnd, string $timezone, ?string $comment = null): WorkSession
    {
        $nowUtc = $this->clock->now('UTC')->utc();
        return DB::transaction(function () use ($employee, $selectedSchedule, $shiftStart, $shiftEnd, $timezone, $comment, $nowUtc) {
            $existing = WorkSession::query()->where('employee_id', $employee->id)->whereNull('clock_out')->lockForUpdate()->first();
            if ($existing) throw new \RuntimeException('You already have an active work session.');
            $legacyOpen = Attendance::query()->where('employee_id', $employee->id)->whereNull('clock_out')->whereNull('work_session_id')->lockForUpdate()->exists();
            if ($legacyOpen) throw new \RuntimeException('You already have an active legacy attendance session. Clock out before starting a new work session.');

            $session = WorkSession::create([
                'employee_id' => $employee->id,
                'primary_schedule_id' => $selectedSchedule->id,
                'clock_in' => $nowUtc,
                'status' => 'active',
                'check_in_comment' => $comment,
            ]);

            // The selected Doctor is explicitly eligible up to 15 minutes before shift start.
            $this->ensureAttendance($session, $selectedSchedule, $shiftStart->copy()->utc(), $shiftEnd->copy()->utc(), $timezone, $comment);
            return $this->reconcile($session, $nowUtc);
        });
    }

    public function reconcileForEmployee(Employee $employee, ?Carbon $nowUtc = null): ?WorkSession
    {
        $session = $this->openForEmployee($employee);
        return $session ? $this->reconcile($session, $nowUtc) : null;
    }

    public function reconcile(WorkSession $session, ?Carbon $nowUtc = null): WorkSession
    {
        if (!$this->supported()) return $session;
        $nowUtc = ($nowUtc ?: $this->clock->now('UTC'))->copy()->utc();

        return DB::transaction(function () use ($session, $nowUtc) {
            $locked = WorkSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $employee = Employee::findOrFail($locked->employee_id);
            $sessionEnd = $locked->clock_out?->copy()->utc() ?: $nowUtc;

            $schedules = EmployeeClientSchedule::query()
                ->with('client:id,name,business_name,timezone')
                ->where('employee_id', $employee->id)
                ->where(function ($q) { $q->where('enabled', true)->orWhereNull('enabled'); })
                ->get();

            foreach ($schedules as $schedule) {
                foreach ($this->occurrences($schedule, $locked->clock_in->copy()->utc(), $sessionEnd) as [$startUtc, $endUtc, $zone]) {
                    // Later assigned Doctors automatically join once their shift has begun.
                    if ($startUtc->gt($sessionEnd) || $endUtc->lte($locked->clock_in)) continue;
                    if ($startUtc->gt($nowUtc) && !$locked->clock_out) continue;
                    $this->ensureAttendance($locked, $schedule, $startUtc, $endUtc, $zone, $locked->check_in_comment);
                }
            }

            $locked->load(['attendances.client','attendances.schedule','attendances.breaks','breaks','employee']);
            $segments = $locked->attendances;
            if ($segments->isNotEmpty()) {
                $latestShiftEnd = $segments->map(fn(Attendance $a) => $a->shift_end_snapshot?->copy()->utc())->filter()->sortByDesc(fn(Carbon $c) => $c->timestamp)->first();

                foreach ($segments as $segment) {
                    $this->syncBreaksForAttendance($locked, $segment, $sessionEnd);
                    if ($segment->clock_out || !$segment->shift_end_snapshot || !$latestShiftEnd) continue;
                    $shiftEnd = $segment->shift_end_snapshot->copy()->utc();

                    if ($locked->clock_out) {
                        $out = $this->math->segmentClockOut($locked->clock_out->copy()->utc(), $shiftEnd, $latestShiftEnd);
                        $this->billing->finalize($segment, $out);
                    } elseif ($nowUtc->gte($shiftEnd) && $shiftEnd->lt($latestShiftEnd)) {
                        // An earlier Doctor ends while the VA stays active for another Doctor.
                        $this->billing->finalize($segment, $shiftEnd);
                    }
                }
            }

            return $locked->fresh(['employee','attendances.client','attendances.schedule','attendances.breaks','breaks']);
        });
    }

    public function close(WorkSession $session, ?Carbon $clockOutUtc = null): WorkSession
    {
        $clockOutUtc = ($clockOutUtc ?: $this->clock->now('UTC'))->copy()->utc();
        return DB::transaction(function () use ($session, $clockOutUtc) {
            $locked = WorkSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (!$locked->clock_out) {
                $openBreak = $locked->breaks()->whereNull('end_time')->lockForUpdate()->first();
                if ($openBreak) $openBreak->update(['end_time' => $clockOutUtc]);
                $locked->update(['clock_out' => $clockOutUtc, 'status' => 'completed']);
            }
            return $this->reconcile($locked->fresh(), $clockOutUtc);
        });
    }

    public function startBreak(WorkSession $session, ?string $notes = null): WorkSessionBreak
    {
        return DB::transaction(function () use ($session, $notes) {
            $locked = WorkSession::query()->whereKey($session->id)->whereNull('clock_out')->lockForUpdate()->firstOrFail();
            if ($locked->breaks()->whereNull('end_time')->exists()) throw new \RuntimeException('A break is already active.');
            $locked = $this->reconcile($locked);
            $allowed = $locked->attendances->whereNull('clock_out')->map(fn(Attendance $a) => $this->hmsToSeconds($a->break_limit))->max() ?? 0;
            $used = (int)$locked->breaks->whereNotNull('end_time')->sum(fn($b) => max(0,$b->end_time->timestamp-$b->start_time->timestamp));
            if ($allowed <= 0) throw new \RuntimeException('No break time is allowed for the active Doctor shift(s).');
            if ($used >= $allowed) throw new \RuntimeException('Your allowed break time has already been used.');
            return $locked->breaks()->create(['start_time'=>$this->clock->now('UTC'),'notes'=>$notes]);
        });
    }

    public function endBreak(WorkSession $session): void
    {
        DB::transaction(function () use ($session) {
            $locked = WorkSession::query()->whereKey($session->id)->whereNull('clock_out')->lockForUpdate()->firstOrFail();
            $break = $locked->breaks()->whereNull('end_time')->lockForUpdate()->latest('id')->first();
            if (!$break) throw new \RuntimeException('No active break.');
            $end = $this->clock->now('UTC')->utc();
            $break->update(['end_time'=>$end]);
            $this->reconcile($locked->fresh(), $end);
        });
    }

    public function autoCheckoutDueSessions(): int
    {
        if (!$this->supported()) return 0;
        $now = $this->clock->now('UTC')->utc(); $closed=0;
        WorkSession::query()->whereNull('clock_out')->chunkById(100, function($sessions) use($now,&$closed){
            foreach($sessions as $session){
                $session=$this->reconcile($session,$now);
                $latest=$session->attendances->map(fn($a)=>$a->shift_end_snapshot?->copy()->utc())->filter()->sortByDesc(fn($c)=>$c->timestamp)->first();
                if($latest && $now->gte($latest->copy()->addHours(2))){$this->close($session,$latest->copy()->addHours(2));$closed++;}
            }
        });
        return $closed;
    }

    private function ensureAttendance(WorkSession $session, EmployeeClientSchedule $schedule, Carbon $startUtc, Carbon $endUtc, string $zone, ?string $comment): Attendance
    {
        $existing = Attendance::query()->where('work_session_id',$session->id)->where('schedule_id',$schedule->id)->first();
        if($existing) return $existing;

        $sessionIn=$session->clock_in->copy()->utc();
        $late=$this->math->lateMinutes($sessionIn,$startUtc);
        $date=$startUtc->copy()->setTimezone($zone)->toDateString();
        // Keep the real VA check-in on the primary Doctor. Later Doctor segments begin at
        // their own shift start (or the VA session start if the VA joined after it).
        $segmentClockIn = ((int)$session->primary_schedule_id === (int)$schedule->id)
            ? $sessionIn->copy()
            : $this->math->effectiveStart($sessionIn, $startUtc);
        $payload=[
            'work_session_id'=>$session->id,'employee_id'=>$session->employee_id,'client_id'=>$schedule->client_id,'schedule_id'=>$schedule->id,
            'date'=>$date,'timezone_snapshot'=>$zone,'shift_start_snapshot'=>$startUtc,'shift_end_snapshot'=>$endUtc,
            'clock_in'=>$segmentClockIn,'check_in_comment'=>$comment,'break_limit'=>$this->breakCodeToHms($schedule->break_allowed_hours ?? 0),
            'break_taken'=>0,'total_minutes'=>0,'overtime'=>0,'is_late'=>$late>0,'late_minutes'=>$late,
        ];
        if(Schema::hasColumn('attendances','overtime_status'))$payload['overtime_status']='none';
        try { return Attendance::create($payload); }
        catch(\Illuminate\Database\QueryException $e){
            $row=Attendance::query()->where('work_session_id',$session->id)->where('schedule_id',$schedule->id)->first();
            if($row)return $row; throw $e;
        }
    }

    private function syncBreaksForAttendance(WorkSession $session, Attendance $attendance, Carbon $throughUtc): void
    {
        if (!Schema::hasColumn('employee_breaks', 'work_session_break_id')) return;
        $segmentStart = $attendance->shift_start_snapshot ? $attendance->shift_start_snapshot->copy()->utc() : $attendance->clock_in->copy()->utc();
        $segmentEnd = $attendance->clock_out ? $attendance->clock_out->copy()->utc() : ($attendance->shift_end_snapshot ? $attendance->shift_end_snapshot->copy()->utc() : $throughUtc->copy());

        foreach ($session->breaks as $sessionBreak) {
            $breakStart = $sessionBreak->start_time->copy()->utc();
            $naturalBreakEnd = $sessionBreak->end_time ? $sessionBreak->end_time->copy()->utc() : null;
            $comparisonEnd = $naturalBreakEnd ?: $throughUtc->copy();
            $overlapStart = $breakStart->gt($segmentStart) ? $breakStart->copy() : $segmentStart->copy();
            $overlapEnd = $comparisonEnd->lt($segmentEnd) ? $comparisonEnd->copy() : $segmentEnd->copy();
            if ($overlapEnd->lte($overlapStart)) continue;

            $mirror = EmployeeBreak::firstOrNew(['attendance_id' => $attendance->id, 'work_session_break_id' => $sessionBreak->id]);
            $mirror->start_time = $overlapStart;
            // Keep an active session-level break open on still-active Doctor segments.
            // If the Doctor segment ended during the break, close the mirror at that segment end.
            $segmentEndedDuringOpenBreak = !$naturalBreakEnd && ($attendance->clock_out || $throughUtc->gte($segmentEnd));
            $mirror->end_time = $naturalBreakEnd || $segmentEndedDuringOpenBreak ? $overlapEnd : null;
            $mirror->notes = $sessionBreak->notes;
            $mirror->save();
        }

        $seconds = (int) $attendance->breaks()->whereNotNull('end_time')->get()->sum(
            fn ($b) => max(0, $b->end_time->timestamp - $b->start_time->timestamp)
        );
        if ((int) $attendance->break_taken !== $seconds) {
            $attendance->break_taken = $seconds;
            $attendance->save();
        }
        // A mirror may have been created/closed above after this relation was eager-loaded.
        // Force AttendanceBillingService to load the fresh overlap rows before recalculation.
        $attendance->unsetRelation('breaks');
    }

    /** @return array<int,array{0:Carbon,1:Carbon,2:string}> */
    private function occurrences(EmployeeClientSchedule $schedule, Carbon $fromUtc, Carbon $toUtc): array
    {
        $zone=$this->timezones->forSchedule($schedule,$schedule->client);
        $fromLocal=$fromUtc->copy()->setTimezone($zone)->subDay()->startOfDay();
        $toLocal=$toUtc->copy()->setTimezone($zone)->addDay()->startOfDay();
        $out=[];
        for($day=$fromLocal->copy();$day->lte($toLocal);$day->addDay()){
            $matches=false;
            if(!$schedule->repeat && $schedule->schedule_date){$matches=$day->toDateString()===$schedule->schedule_date->toDateString();}
            else {$matches=strcasecmp($day->format('l'),(string)$schedule->weekday)===0;}
            if(!$matches)continue;
            $start=Carbon::parse($day->toDateString().' '.$schedule->start_time,$zone);
            $end=Carbon::parse($day->toDateString().' '.$schedule->end_time,$zone); if($end->lte($start))$end->addDay();
            $out[]=[$start->utc(),$end->utc(),$zone];
        }
        return $out;
    }

    private function breakCodeToHms(mixed $value): string
    {
        $raw=number_format((float)($value?:0),2,'.',''); [$h,$m]=array_pad(explode('.',$raw,2),2,'00');
        return sprintf('%02d:%02d:00',max(0,(int)$h),min(59,max(0,(int)$m)));
    }
    private function hmsToSeconds(mixed $value): int { $p=array_map('intval',explode(':',(string)($value?:'00:00:00')));return max(0,(($p[0]??0)*3600)+(($p[1]??0)*60)+($p[2]??0)); }
}
