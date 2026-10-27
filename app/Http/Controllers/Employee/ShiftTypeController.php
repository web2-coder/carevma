<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\ShiftType;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class ShiftTypeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $shiftTypes = ShiftType::oldest(); // Oldest created_at first
            return DataTables::of($shiftTypes)
                ->editColumn('created_at', function($row){
                    return $row->created_at ? $row->created_at->format('Y-m-d') : '';
                })
                ->addColumn('actions', function($row){
                    return '
                        <div class="btn-group">
                            <button type="button" class="cstm-dots-btn dropdown-toggle hide-arrow" 
                                data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="ti tabler-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a href="javascript:void(0)" class="dropdown-item edit-shifttype" 
                                        data-id="'.$row->id.'" data-name="'.$row->name.'">
                                        <i class="ti tabler-edit me-1"></i> Edit
                                    </a>
                                </li>
                                <li>
                                    <form action="'.route('shift-types.destroy', $row->id).'" method="POST">
                                        '.csrf_field().method_field("DELETE").'
                                        <button type="submit" class="dropdown-item text-danger"
                                            onclick="return confirm(\'Are you sure?\')">
                                            <i class="ti tabler-trash me-1"></i> Delete
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    ';
                })
                ->rawColumns(['actions'])
                ->make(true);
        }

        return view('admin.shift_types.index');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|unique:shift_types']);
        ShiftType::create($request->only('name'));
        return redirect()->route('shift-types.index')->with('success','Shift Type created successfully');
    }

    public function update(Request $request, ShiftType $shiftType)
    {
        $request->validate(['name' => 'required|unique:shift_types,name,'.$shiftType->id]);
        $shiftType->update($request->only('name'));
        return redirect()->route('shift-types.index')->with('success','Shift Type updated successfully');
    }

    public function destroy(ShiftType $shiftType)
    {
        $shiftType->delete();
        return redirect()->route('shift-types.index')->with('success','Shift Type deleted successfully');
    }
}
