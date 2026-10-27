<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientEmployeeRate;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Expertise;
use App\Models\ReportingManager;
use App\Models\Role;
use App\Models\Service;
use App\Models\ShiftType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CareVmaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('Password123!');

        $department = Department::firstOrCreate(['name' => 'Operations'], ['description' => 'Virtual assistant operations']);
        $employeeRole = Role::firstOrCreate(['name' => 'Virtual Assistant']);
        $employmentType = EmploymentType::firstOrCreate(['name' => 'Full Time']);
        $shiftType = ShiftType::firstOrCreate(['name' => 'Day Shift']);
        $designation = Designation::firstOrCreate(['name' => 'Virtual Assistant']);
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $expertise = Expertise::firstOrCreate(['name' => 'General Administration']);
        $manager = ReportingManager::firstOrCreate(['name' => 'Operations Manager']);
        $service = Service::firstOrCreate(['name' => 'Virtual Assistant Services']);
        $contract = ContractType::firstOrCreate(['name' => 'Hourly']);

        User::updateOrCreate(
            ['email' => 'admin@carevma.local'],
            ['name' => 'CareVMA Admin', 'password' => $password, 'role' => 'admin']
        );

        User::updateOrCreate(
            ['email' => 'hr@carevma.local'],
            ['name' => 'CareVMA HR', 'password' => $password, 'role' => 'hr']
        );

        $employeeUser = User::updateOrCreate(
            ['email' => 'va@carevma.local'],
            ['name' => 'Demo VA', 'password' => $password, 'role' => 'employee']
        );

        $employee = Employee::updateOrCreate(
            ['email' => 'va@carevma.local'],
            [
                'user_id' => $employeeUser->id,
                'first_name' => 'Demo',
                'last_name' => 'VA',
                'password' => $password,
                'phone' => '+1 555 010 1000',
                'department_id' => $department->id,
                'role_id' => $employeeRole->id,
                'employment_type_id' => $employmentType->id,
                'shift_type_id' => $shiftType->id,
                'designation_id' => $designation->id,
                'employee_status_id' => $status->id,
                'expertise_id' => $expertise->id,
                'reporting_manager_id' => $manager->id,
                'location' => 'Lahore',
                'timezone' => 'Asia/Karachi',
                'break_allowed_hours' => 1,
                'profile_completed' => true,
            ]
        );

        $doctorUser = User::updateOrCreate(
            ['email' => 'doctor@carevma.local'],
            ['name' => 'Demo Doctor', 'password' => $password, 'role' => 'client']
        );

        $client = Client::updateOrCreate(
            ['email' => 'doctor@carevma.local'],
            [
                'user_id' => $doctorUser->id,
                'name' => 'Demo Doctor',
                'business_name' => 'Demo Medical Practice',
                'country' => 'United States',
                'phone_number' => '+1 555 010 2000',
                'per_hour_charges' => 20,
                'timezone' => 'America/New_York',
                'contract_type_id' => $contract->id,
                'service_id' => $service->id,
                'ring_center' => false,
            ]
        );

        EmployeeClientSchedule::updateOrCreate(
            ['employee_id' => $employee->id, 'client_id' => $client->id, 'weekday' => 'Monday'],
            [
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'no_of_hours' => '8.00',
                'timezone' => 'Asia/Karachi',
                'enabled' => true,
                'repeat' => true,
            ]
        );

        ClientEmployeeRate::updateOrCreate(
            ['client_id' => $client->id, 'employee_id' => $employee->id],
            ['per_hour_rate' => 20]
        );
    }
}
