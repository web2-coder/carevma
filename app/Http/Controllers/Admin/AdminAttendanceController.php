<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Attendance;
use App\Models\AttendanceChangeRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\AttendanceStatusMail;
use Yajra\DataTables\Facades\DataTables;

class AdminAttendanceController extends Controller
{
   public function index(Request $request)
{
    if ($request->ajax()) {
        $attendances = Attendance::with(['employee', 'breaks'])->select('attendances.*');

        // Filter by Employee Name
        if ($request->filled('employee_name')) {
            $attendances->whereHas('employee', function ($q) use ($request) {
                $q->where('first_name', 'like', '%' . $request->employee_name . '%')
                  ->orWhere('last_name', 'like', '%' . $request->employee_name . '%');
            });
        }

        // Filter by single date
        if ($request->filled('date')) {
            $attendances->where('date', $request->date);
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $attendances->where('date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $attendances->where('date', '<=', $request->to_date);
        }

        return DataTables::eloquent($attendances)
            ->addIndexColumn()
            ->addColumn('name', function ($row) {
                return ($row->employee->first_name ?? '') . ' ' . ($row->employee->last_name ?? '');
            })
            ->addColumn('date', function ($row) {
                return $row->date ? Carbon::parse($row->date)->format('Y-m-d') : '-';
            })
            ->addColumn('clock_in', function ($row) {
                if (!$row->clock_in) {
                    return '-';
                }

                $tz = $row->getScheduleTimezone();
                return Carbon::parse($row->clock_in)->timezone($tz)->format('h:i A');
            })
            ->addColumn('clock_out', function ($row) {
                if (!$row->clock_out) {
                    return '-';
                }

                $tz = $row->getScheduleTimezone();
                return Carbon::parse($row->clock_out)->timezone($tz)->format('h:i A');
            })
            ->addColumn('breaks', function ($row) {
                return $row->breaks->count();
            })
            ->addColumn('worked_hours', function ($row) {
                if ($row->clock_in && !$row->clock_out) {
                    return 'Running...';
                }

                if ($row->clock_in && $row->clock_out) {
                    $start = Carbon::parse($row->clock_in);
                    $end = Carbon::parse($row->clock_out);
                    $minutes = (int) $start->diffInMinutes($end);

                    return $minutes < 60
                        ? $minutes . ' mins'
                        : sprintf('%d:%02d hrs', floor($minutes / 60), $minutes % 60);
                }

                return '-';
            })
            ->addColumn('overtime', function ($row) {
                if ($row->overtime > 0) {
                    $hours = floor($row->overtime / 60);
                    $minutes = $row->overtime % 60;
                    return sprintf('%d:%02d hrs', $hours, $minutes);
                }

                return '-';
            })
            ->rawColumns(['worked_hours'])
            ->make(true);
    }

    return view('admin.attendance.index');
}

    public function attendance_changes_requests(Request $request)
    {
        if ($request->ajax()) {
            $data = AttendanceChangeRequest::with('employee', 'attendance')->latest()->get();

            return DataTables::of($data)
                ->addIndexColumn()
                ->addColumn('employee', function ($row) {
                    return $row->employee->first_name . ' ' . $row->employee->last_name;
                })
                ->addColumn('date', function ($row) {
                    return Carbon::parse($row->date)->format('Y-m-d');
                })
                ->addColumn('requested_clock_in', function ($row) {
                    return $row->requested_clock_in ? Carbon::parse($row->requested_clock_in)->format('h:i A') : '-';
                })
                ->addColumn('requested_clock_out', function ($row) {
                    return $row->requested_clock_out ? Carbon::parse($row->requested_clock_out)->format('h:i A') : '-';
                })
                ->addColumn('status', function ($row) {
                    return '<span class="badge bg-' . ($row->status == 'approved' ? 'success' : ($row->status == 'pending' ? 'warning' : 'danger')) . '">' . ucfirst($row->status) . '</span>';
                })
                ->addColumn('action', function ($row) {
                    return '
                        <button class="btn btn-sm btn-primary viewRequest" 
                            data-id="' . $row->id . '" 
                            data-date="' . Carbon::parse($row->date)->format('Y-m-d') . '" 
                            data-oldin="' . ($row->attendance->clock_in ? Carbon::parse($row->attendance->clock_in)->format('h:i A') : '-') . '" 
                            data-oldout="' . ($row->attendance->clock_out ? Carbon::parse($row->attendance->clock_out)->format('h:i A') : '-') . '" 
                            data-requestedin="' . ($row->requested_clock_in ? Carbon::parse($row->requested_clock_in)->format('h:i A') : '-') . '" 
                            data-requestedout="' . ($row->requested_clock_out ? Carbon::parse($row->requested_clock_out)->format('h:i A') : '-') . '" 
                            data-reason="' . $row->reason . '">View</button>';
                })
                ->rawColumns(['status', 'action'])
                ->make(true);
        }

        return view('admin.attendance.requests');
    }

    public function approve(Request $request)
    {
        $req = AttendanceChangeRequest::with('employee', 'attendance')->findOrFail($request->id);
        $req->old_clock_in = $req->attendance->clock_in;
        $req->old_clock_out = $req->attendance->clock_out;
        $req->status = 'approved';
        $req->save();

        if ($req->attendance) {
            $req->attendance->clock_in = $req->requested_clock_in;
            $req->attendance->clock_out = $req->requested_clock_out;
            $req->attendance->save();
        }

        Mail::to($req->employee->email)->send(new AttendanceStatusMail($req, 'approved'));

        return response()->json(['success' => true]);
    }

    public function reject(Request $request)
    {
        $req = AttendanceChangeRequest::with('employee')->findOrFail($request->id);
        $req->status = 'rejected';
        $req->save();

        Mail::to($req->employee->email)->send(new AttendanceStatusMail($req, 'rejected'));

        return response()->json(['success' => true]);
    }
}