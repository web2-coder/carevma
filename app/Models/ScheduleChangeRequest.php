<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ScheduleChangeRequest extends Model
{
    protected $fillable=['employee_id','schedule_id','requested_date','requested_start_time','requested_end_time','approved_date','approved_start_time','approved_end_time','reason','status','reviewed_by','reviewed_at','review_note'];
    protected $casts=['requested_date'=>'date','approved_date'=>'date','reviewed_at'=>'datetime'];
    public function employee(){return $this->belongsTo(Employee::class);}
    public function schedule(){return $this->belongsTo(EmployeeClientSchedule::class);}
    public function reviewer(){return $this->belongsTo(User::class,'reviewed_by');}
}
