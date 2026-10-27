<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceChangeRequest extends Model
{
    protected $fillable = ['employee_id','attendance_id','date','requested_clock_in','requested_clock_out','old_clock_in','old_clock_out','reason','status','reviewed_by','reviewed_at','review_note'];
    protected $casts = ['date'=>'date','reviewed_at'=>'datetime'];
    public function employee(){ return $this->belongsTo(Employee::class); }
    public function attendance(){ return $this->belongsTo(Attendance::class); }
    public function reviewer(){ return $this->belongsTo(User::class, 'reviewed_by'); }
}
