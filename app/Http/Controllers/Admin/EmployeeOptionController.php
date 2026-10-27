<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EmployeeOptionController extends Controller
{
    public function employee_options(Request $request)
    {
        return view('admin.employee_options.index');
    }
}
