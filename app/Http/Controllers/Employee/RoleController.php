<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class RoleController extends Controller
{
    public function index(Request $request)
    {
            if ($request->ajax()) {
                $roles = Role::withCount('employees');

                return DataTables::of($roles)
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
                                        <form action="'.route('roles.destroy', $row->id).'" method="POST">
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
        return view('admin.roles.index');
    }

    public function create()
    {
        return view('admin.roles.create');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|unique:roles']);
        Role::create($request->only('name'));

        return redirect()->route('roles.index')->with('success', 'Role created successfully');
    }

    public function show(Role $role)
    {
        return view('admin.roles.show', compact('role'));
    }

    public function edit(Role $role)
    {
        return view('admin.roles.edit', compact('role'));
    }

    public function update(Request $request, Role $role)
    {
        $request->validate(['name' => 'required|unique:roles,name,' . $role->id]);
        $role->update($request->only('name'));

        return redirect()->route('roles.index')->with('success', 'Role updated successfully');
    }

    public function destroy(Role $role)
    {
        $role->delete();
        return redirect()->route('roles.index')->with('success', 'Role deleted successfully');
    }
}
