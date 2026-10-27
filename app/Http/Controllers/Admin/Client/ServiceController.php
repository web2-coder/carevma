<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use App\Models\Service;
use Carbon\Carbon;


class ServiceController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $data = Service::select(['id', 'name', 'created_at']); // select needed fields
            return DataTables::of($data)
                ->addIndexColumn() // auto increment index
                ->addColumn('action', function($row){
                    $btn = '<a href="'.route("services.edit", $row->id).'" class="btn btn-sm btn-primary">Edit</a>';
                    $btn .= ' <a href="javascript:void(0)" data-id="'.$row->id.'" class="btn btn-sm btn-danger deleteService">Delete</a>';
                    return $btn;
                })
                ->editColumn('created_at', function ($row) {
                    return Carbon::parse($row->created_at)->format('Y-m-d'); // only date
                })
                ->rawColumns(['action'])
                ->make(true);
        }
        return view('admin.service.index');
    }

    public function create()
    {
        return view('admin.service.create');
    }

    public function store(Request $request)
    {
        $request->validate(
            ['name' => 'required|unique:services'
        ]);
        
        Service::create($request->only('name'));

        return redirect()->route('services.index')
            ->with('success', 'Service created successfully');
    }

    public function show(Service $service)
    {
        return view('admin.service.show', compact('service'));
    }

    public function edit(Service $service)
    {
        return view('admin.service.edit', compact('service'));
    }

    public function update(Request $request, Service $service)
    {
        $request->validate([
            'name' => 'required|unique:services,name,' . $service->id]
        );

        $service->update($request->only('name'));

        return redirect()->route('services.index')
            ->with('success', 'Service updated successfully');
    }

    public function destroy(Service $service)
    {
        $service->delete();
        return redirect()->route('services.index')
            ->with('success', 'Service deleted successfully');
    }
}
