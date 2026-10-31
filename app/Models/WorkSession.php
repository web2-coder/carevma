<?php
namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WorkSession extends Model
{
    protected $fillable = ['employee_id','primary_schedule_id','clock_in','clock_out','status','check_in_comment'];

    protected function clockIn(): Attribute { return Attribute::make(get: fn($v)=>$this->utc($v), set: fn($v)=>$this->utcString($v)); }
    protected function clockOut(): Attribute { return Attribute::make(get: fn($v)=>$this->utc($v), set: fn($v)=>$this->utcString($v)); }
    private function utc(mixed $v): ?Carbon { if(!$v)return null; if($v instanceof DateTimeInterface)return Carbon::instance($v)->utc(); try{return Carbon::createFromFormat($this->getDateFormat(),(string)$v,'UTC')->utc();}catch(\Throwable){return Carbon::parse((string)$v,'UTC')->utc();} }
    private function utcString(mixed $v): ?string { if(!$v)return null; return ($v instanceof DateTimeInterface?Carbon::instance($v):Carbon::parse((string)$v,'UTC'))->utc()->format($this->getDateFormat()); }

    public function employee(){ return $this->belongsTo(Employee::class); }
    public function primarySchedule(){ return $this->belongsTo(EmployeeClientSchedule::class,'primary_schedule_id'); }
    public function attendances(){ return $this->hasMany(Attendance::class); }
    public function breaks(){ return $this->hasMany(WorkSessionBreak::class); }
}
