<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\User;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\EmployeeClientSchedule;
use Monarobase\CountryList\CountryList;
use Yajra\DataTables\Facades\DataTables;
use App\Mail\ClientSetPasswordMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;


class AdminClientController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $clients = Client::with(['contractType', 'service'])->select('clients.*');

            return DataTables::of($clients)
                ->addColumn('contract_type', function ($row) {
                    return $row->contractType->name ?? '-';
                })
                ->addColumn('client_info', function ($row) {
                    return '
                        <div>
                            <div><strong>' . e($row->name) . '</strong></div>
                            <span style="text-transform:none;color:gray;">' . e(strtolower($row->email)) . '</span><br>
                            <span style="text-transform:none;color:gray;">' . e(strtolower($row->phone_number)) . '</span>
                        </div>
                    ';
                })
                ->filterColumn('client_info', function ($query, $keyword) {
                    $query->where(function ($q) use ($keyword) {
                        $q->where('name', 'like', "%{$keyword}%")
                            ->orWhere('email', 'like', "%{$keyword}%")
                            ->orWhere('phone_number', 'like', "%{$keyword}%");
                    });
                })
                ->addColumn('service', function ($row) {
                    return $row->service->name ?? '-';
                })
                ->editColumn('per_hour_charges', function ($row) {
                    return '$' . number_format($row->per_hour_charges, 2);
                })
                ->addColumn('ring_center', function ($row) {
                    return $row->ring_center
                        ? '<span class="badge bg-success">Enabled</span>'
                        : '<span class="badge bg-danger">Disabled</span>';
                })
                ->addColumn('actions', function ($row) {
                    $viewUrl = route('client.profile', $row->id);
                    $deleteUrl = route('admin.clients.destroy', $row->id);

                    return '
                    <div class="btn-group">
                        <button type="button" class="cstm-dots-btn dropdown-toggle hide-arrow" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="ti tabler-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="' . $viewUrl . '">
                                <i class="ti tabler-eye me-1"></i> View</a>
                            </li>
                            <li>
                                <form action="' . $deleteUrl . '" method="POST" onsubmit="return confirm(\'Are you sure?\')">
                                    ' . csrf_field() . '
                                    ' . method_field("DELETE") . '
                                    <button type="submit" class="dropdown-item text-danger">
                                    <i class="ti tabler-trash me-1"></i> Delete
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                    ';
                })
                ->rawColumns(['ring_center', 'actions', 'client_info'])
                ->make(true);
        }
        return view('admin.client.index');
    }

    public function add_client(Request $request, CountryList $countryList)
    {
        $services = Service::all();
        $contractTypes = ContractType::all();
        $countries = $countryList->getList('en'); // works now
        return view('admin.client.add', compact(['services', 'contractTypes', 'countries']));
    }


    public function insert_client(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|unique:clients,email',
            'business_name' => 'nullable|string|max:255',
            'country' => 'required|string|max:2',
            'phone_number' => 'required|string|max:20',
            'per_hour_charges' => 'required|numeric|min:0',
            'timezone' => 'required|string',
            'contract_type_id' => 'required|integer|exists:contract_types,id',
            'service_id' => 'required|integer|exists:services,id',
            'ring_center' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make(Str::random(40)), // random so they must set new one
                'role' => 'client',
            ]);

            $client = new Client();
            $client->user_id = $user->id;
            $client->name = $validated['name'];
            $client->email = $validated['email'];
            $client->business_name = $validated['business_name'] ?? null;
            $client->country = $validated['country'];
            $client->phone_number = $validated['phone_number'];
            $client->per_hour_charges = $validated['per_hour_charges'];
            $client->timezone = $validated['timezone'];
            $client->contract_type_id = $validated['contract_type_id'];
            $client->service_id = $validated['service_id'];
            $client->ring_center = $request->boolean('ring_center');
            $client->save();

            // token + signed link
            $token = Str::random(64);

            $url = URL::temporarySignedRoute(
                'client.password.form',
                now()->addMinutes(60), // better than 5 mins
                ['email' => $user->email, 'token' => $token]
            );

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            Mail::to($user->email)->send(new ClientSetPasswordMail($client, $url));

            DB::commit();

            return redirect()->back()->with('success', 'Client added successfully! Set-password email sent.');
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Client operation failed.', ['exception' => $e]);
            return redirect()->back()->with('error', 'The client could not be updated. Please check the form and try again.');
        }
    }

    public function destroy($id)
    {
        $client = Client::findOrFail($id);

        // Optionally delete the associated user
        if ($client->user) {
            $client->user->delete();
        }

        $client->delete();

        return redirect()->route('all.clients')->with('success', 'Client deleted successfully.');
    }

    public function assign_va($id)
    {
        $client = Client::with('user')->findOrFail($id);
        $employees = Employee::all();


        return view('admin.client.assign_va', compact(['client', 'employees']));
    }

    // public function assign_employee(Request $request)
    // {
    //     $clients = Client::all();
    //     $employees = Employee::all();
    //     return view('admin.client.assign_employee', compact(['clients','employees']));
    // }

    public function get_client_details($id)
    {
        $client = Client::findOrFail($id);
        return response()->json($client);
    }

    public function get_employee_details($id)
    {
        $employee = Employee::findOrFail($id);
        return response()->json($employee);
    }

    public function client_schedule($id)
    {
        $client = Client::with(['employeeSchedules.employee'])->findOrFail($id);

        $employees = Employee::all();
        return view('admin.client.schedule', compact(['client', 'employees']));
    }

    public function client_profile($id, CountryList $countryList)
    {
        $client = Client::findOrFail($id);
        $countries = $countryList->getList('en');
        $contractTypes = ContractType::all();
        $services = Service::all();

        return view('admin.client.profile', compact(['client', 'countries', 'contractTypes', 'services']));
    }

    public function client_profile_timezone($id)
    {
        $client = Client::findOrFail($id);
        return view('admin.client.profile_timezone', compact('client'));
    }

    public function client_update(Request $request, $id)
    {
        $client = Client::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:clients,email,' . $client->id,
            'business_name' => 'nullable|string|max:255',
            'country' => 'nullable|string',
            'phone_number' => 'nullable|string|max:20',
            'per_hour_charges' => 'nullable|numeric',
            'contract_type_id' => 'nullable|exists:contract_types,id',
            'service_id' => 'nullable|exists:services,id',
            'timezone' => 'nullable|string',
        ]);

        $client->update([
            'name' => $request->name,
            'email' => $request->email,
            'business_name' => $request->business_name,
            'country' => $request->country,
            'phone_number' => $request->phone_number,
            'per_hour_charges' => $request->per_hour_charges,
            'contract_type_id' => $request->contract_type_id,
            'service_id' => $request->service_id,
            'ring_center' => $request->has('ring_center') ? 1 : 0,
            'timezone' => $request->timezone,
        ]);

        return redirect()
            ->route('client.profile', $client->id)
            ->with('success', 'Client updated successfully');
    }

    public function daily_report(Request $request)
    {
        if ($request->ajax()) {
            $client = Client::findOrFail($request->id);

            $query = Attendance::with('employee')
                ->whereHas('employee.clientSchedules', function ($q) use ($client) {
                    $q->where('client_id', $client->id);
                });

            return DataTables::of($query)
                ->addColumn('employee_name', function ($row) {
                    return $row->employee->first_name . ' ' . $row->employee->last_name;
                })
                ->editColumn('date', function ($row) {
                    return \Carbon\Carbon::parse($row->date)->format('d M Y');
                })
                ->editColumn('clock_in', function ($row) {
                    $clientTz = optional($row->employee->clientSchedules->first()->client ?? null)->timezone ?? config('carevma.business_timezone', 'Asia/Karachi');

                    return $row->clock_in
                        ? \Carbon\Carbon::parse($row->clock_in)->timezone($clientTz)->format('h:i A')
                        : '-';
                })
                ->editColumn('clock_out', function ($row) {
                    $clientTz = optional($row->employee->clientSchedules->first()->client ?? null)->timezone ?? config('carevma.business_timezone', 'Asia/Karachi');

                    return $row->clock_out
                        ? \Carbon\Carbon::parse($row->clock_out)->timezone($clientTz)->format('h:i A')
                        : '-';
                })

                ->editColumn('total_minutes', function ($row) {
                    $hours = floor($row->total_minutes / 60);
                    $minutes = $row->total_minutes % 60;
                    return sprintf("%02d:%02d hrs", $hours, $minutes);
                })
                ->editColumn('late_minutes', function ($row) {
                    return $row->late_minutes ? $row->late_minutes . " min" : "0";
                })
                ->make(true);
        }
        $client = Client::findOrFail($request->id);

        return view('admin.client.daily_work_report', compact('client'));
    }

}
