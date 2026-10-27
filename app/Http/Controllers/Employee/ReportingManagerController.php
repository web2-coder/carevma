<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\ReportingManager;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class ReportingManagerController extends Controller
{
    public function index(Request $request)
    {
        if($request->ajax()){
            $managers = ReportingManager::oldest();
            return DataTables::of($managers)
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
                                    <a href="javascript:void(0)" class="dropdown-item edit-manager" 
                                        data-id="'.$row->id.'" data-name="'.$row->name.'">
                                        <i class="ti tabler-edit me-1"></i> Edit
                                    </a>
                                </li>
                                <li>
                                    <form action="'.route('reporting-managers.destroy',$row->id).'" method="POST">
                                        '.csrf_field().method_field("DELETE").'
                                        <button type="submit" class="dropdown-item text-danger" onclick="return confirm(\'Delete this?\')">
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

        return view('admin.reporting_managers.index');
    }

    public function store(Request $request)
    {
        $request->validate(['name'=>'required|unique:reporting_managers']);
        ReportingManager::create($request->only('name'));
        return redirect()->route('reporting-managers.index')->with('success','Reporting Manager added successfully');
    }

    public function update(Request $request, ReportingManager $manager)
    {
        $request->validate(['name'=>'required|unique:reporting_managers,name,'.$manager->id]);
        $manager->update($request->only('name'));
        return redirect()->route('reporting-managers.index')->with('success','Reporting Manager updated successfully');
    }

    public function destroy(ReportingManager $manager)
    {
        $manager->delete();
        return redirect()->route('reporting-managers.index')->with('success','Reporting Manager deleted successfully');
    }
}
