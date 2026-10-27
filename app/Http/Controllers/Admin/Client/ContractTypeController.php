<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\ContractType;

class ContractTypeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $data = ContractType::select(['id', 'name', 'created_at']); // select needed fields
            return DataTables::of($data)
                ->addIndexColumn() // auto increment index
                ->addColumn('action', function($row){
                    $btn = '<a href="'.route("contracts.edit", $row->id).'" class="btn btn-sm btn-primary">Edit</a>';
                    $btn .= ' <a href="javascript:void(0)" data-id="'.$row->id.'" class="btn btn-sm btn-danger deleteService">Delete</a>';
                    return $btn;
                })
                ->editColumn('created_at', function ($row) {
                    return Carbon::parse($row->created_at)->format('Y-m-d'); // only date
                })
                ->rawColumns(['action'])
                ->make(true);
        }
        return view('admin.contract_type.index');
    }

    public function create()
    {
        return view('admin.contract_type.create');
    }

    public function store(Request $request)
    {
        $request->validate(
            ['name' => 'required|unique:contract_types'
        ]);
        
        ContractType::create($request->only('name'));

        return redirect()->route('contracts.index')
            ->with('success', 'ContractType created successfully');
    }

    public function show(ContractType $contract)
    {
        return view('admin.contract_type.show', compact('contract_type'));
    }

    public function edit(ContractType $contract)
    {
        return view('admin.contract_type.edit', compact('contract_type'));
    }

    public function update(Request $request, ContractType $contract)
    {
        $request->validate([
            'name' => 'required|unique:contract_types,name,' . $contract->id]
        );

        $contract->update($request->only('name'));

        return redirect()->route('contracts.index')
            ->with('success', 'Contract Type updated successfully');
    }

    public function destroy(ContractType $contract)
    {
        $contract->delete();
        return redirect()->route('contracts.index')
            ->with('success', 'Contract Type deleted successfully');
    }
}
