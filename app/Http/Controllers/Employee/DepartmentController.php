<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $departments = Department::withCount('employees');
    
            return DataTables::of($departments)
                ->editColumn('created_at', function ($row) {
                    return $row->created_at ? $row->created_at->format('Y-m-d') : '';
                })
                ->addColumn('employee_count', function ($row) {
                    return $row->employees_count; // from withCount
                })
                ->addColumn('actions', function ($row) {
                    return '
                        <div class="btn-group">
                            <button type="button" class="cstm-dots-btn dropdown-toggle hide-arrow" 
                                data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="ti tabler-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a href="javascript:void(0)" 
                                        class="dropdown-item edit-department" 
                                        data-id="'.$row->id.'" 
                                        data-name="'.$row->name.'">
                                        <i class="ti tabler-edit me-1"></i> Edit
                                    </a>
                                </li>
                                <li>
                                    <form action="'.route('departments.destroy', $row->id).'" method="POST">
                                        '.csrf_field().'
                                        '.method_field("DELETE").'
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
        return view('admin.departments.index');
    }

    public function create()
    {
        return view('admin.departments.create');
    }

    public function store(Request $request)
    {
        $request->validate(
            ['name' => 'required|unique:departments'
        ]);
        
        Department::create($request->only('name'));

        return redirect()->route('departments.index')
            ->with('success', 'Department created successfully');
    }

    public function show(Department $department)
    {
        return view('admin.departments.show', compact('department'));
    }

    public function edit(Department $department)
    {
        return view('admin.departments.edit', compact('department'));
    }

    public function update(Request $request, Department $department)
    {
        $request->validate([
            'name' => 'required|unique:departments,name,' . $department->id]
        );

        $department->update($request->only('name'));

        return redirect()->route('departments.index')
            ->with('success', 'Department updated successfully');
    }

    public function destroy(Department $department)
    {
        $department->delete();
        return redirect()->route('departments.index')
            ->with('success', 'Department deleted successfully');
    }
}
