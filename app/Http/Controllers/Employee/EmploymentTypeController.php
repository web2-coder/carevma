<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\EmploymentType;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class EmploymentTypeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $employeeTypes = EmploymentType::withCount('employees');
    
            return DataTables::of($employeeTypes)
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
                                        class="dropdown-item edit-employeetype" 
                                        data-id="'.$row->id.'" 
                                        data-name="'.$row->name.'">
                                        <i class="ti tabler-edit me-1"></i> Edit
                                    </a>
                                </li>
                                <li>
                                    <form action="'.route('employment-types.destroy', $row->id).'" method="POST">
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
        return view('admin.employment_types.index');
    }

    public function create()
    {
        return view('admin.employment_types.create');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|unique:employment_types']);
        EmploymentType::create($request->only('name'));

        return redirect()->route('employment-types.index')->with('success', 'Employment Type created successfully');
    }

    public function show(EmploymentType $employmentType)
    {
        return view('admin.employment_types.show', compact('employmentType'));
    }

    public function edit(EmploymentType $employmentType)
    {
        return view('admin.employment_types.edit', compact('employmentType'));
    }

    public function update(Request $request, EmploymentType $employmentType)
    {
        $request->validate(['name' => 'required|unique:employment_types,name,' . $employmentType->id]);
        $employmentType->update($request->only('name'));

        return redirect()->route('employment-types.index')->with('success', 'Employment Type updated successfully');
    }

    public function destroy(EmploymentType $employmentType)
    {
        $employmentType->delete();
        return redirect()->route('employment-types.index')->with('success', 'Employment Type deleted successfully');
    }
}
