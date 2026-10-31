<?php
namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WorkSessionBreak extends Model
{
    protected $fillable=['work_session_id','start_time','end_time','notes'];
    protected function startTime(): Attribute { return Attribute::make(get: fn($v)=>$this->utc($v), set: fn($v)=>$this->utcString($v)); }
    protected function endTime(): Attribute { return Attribute::make(get: fn($v)=>$this->utc($v), set: fn($v)=>$this->utcString($v)); }
    private function utc(mixed $v): ?Carbon { if(!$v)return null; if($v instanceof DateTimeInterface)return Carbon::instance($v)->utc(); try{return Carbon::createFromFormat($this->getDateFormat(),(string)$v,'UTC')->utc();}catch(\Throwable){return Carbon::parse((string)$v,'UTC')->utc();} }
    private function utcString(mixed $v): ?string { if(!$v)return null; return ($v instanceof DateTimeInterface?Carbon::instance($v):Carbon::parse((string)$v,'UTC'))->utc()->format($this->getDateFormat()); }
    public function workSession(){return $this->belongsTo(WorkSession::class);}
}
