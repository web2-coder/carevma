<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Expertise;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class ExpertiseController extends Controller
{
    public function index(Request $request)
    {
        if($request->ajax()){
            $expertises = Expertise::oldest();
            return DataTables::of($expertises)
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
                                    <a href="javascript:void(0)" class="dropdown-item edit-expertise" 
                                        data-id="'.$row->id.'" data-name="'.$row->name.'">
                                        <i class="ti tabler-edit me-1"></i> Edit
                                    </a>
                                </li>
                                <li>
                                    <form action="'.route('expertises.destroy',$row->id).'" method="POST">
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

        return view('admin.expertises.index');
    }

    public function store(Request $request)
    {
        $request->validate(['name'=>'required|unique:expertises']);
        Expertise::create($request->only('name'));
        return redirect()->route('expertises.index')->with('success','Expertise added successfully');
    }

    public function update(Request $request, Expertise $expertise)
    {
        $request->validate(['name'=>'required|unique:expertises,name,'.$expertise->id]);
        $expertise->update($request->only('name'));
        return redirect()->route('expertises.index')->with('success','Expertise updated successfully');
    }

    public function destroy(Expertise $expertise)
    {
        $expertise->delete();
        return redirect()->route('expertises.index')->with('success','Expertise deleted successfully');
    }
}
