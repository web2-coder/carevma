<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * The management-portal modules and which roles may use them. Admins always have every
 * module; other staff roles get what the Roles & Permissions page grants them, falling back
 * to the defaults below. Employees and doctors use their own portals and are never listed.
 */
final class Modules
{
    public const KEY = 'role_permissions';
    public const ADMIN_ROLES = ['admin', 'superadmin'];
    public const PORTAL_ROLES = ['employee', 'client'];

    /** @return array<string, array{label: string, group: string, default: string[]}> */
    public static function all(): array
    {
        return [
            'live-users' => ['label' => 'Live Workforce', 'group' => 'Overview', 'default' => ['hr']],
            'employees' => ['label' => 'Employees', 'group' => 'People', 'default' => ['hr']],
            'hr-team' => ['label' => 'HR Team', 'group' => 'People', 'default' => []],
            'clients' => ['label' => 'Clients', 'group' => 'People', 'default' => []],
            'schedules' => ['label' => 'Schedules', 'group' => 'Time', 'default' => ['hr']],
            'attendance' => ['label' => 'Attendance', 'group' => 'Time', 'default' => ['hr']],
            'change-requests' => ['label' => 'Change Requests', 'group' => 'Time', 'default' => ['hr']],
            'overtime' => ['label' => 'Overtime Review', 'group' => 'Time', 'default' => ['hr']],
            'invoices' => ['label' => 'Invoices', 'group' => 'Billing', 'default' => []],
            'company-finance' => ['label' => 'Company Finance', 'group' => 'Billing', 'default' => []],
            'invoice-settings' => ['label' => 'Invoice Settings', 'group' => 'Billing', 'default' => []],
            'notebook' => ['label' => 'Notebook', 'group' => 'Documents', 'default' => ['hr']],
            'activity-logs' => ['label' => 'Activity Logs', 'group' => 'Logs', 'default' => []],
            'configuration' => ['label' => 'Configuration', 'group' => 'Setup', 'default' => ['hr']],
        ];
    }

    /** Roles whose access is editable: every staff role that is not an admin. */
    public static function grantableRoles(): array
    {
        $known = User::query()
            ->whereNotIn('role', array_merge(self::ADMIN_ROLES, self::PORTAL_ROLES))
            ->distinct()->orderBy('role')->pluck('role')->all();

        return array_values(array_unique(array_merge(['hr'], $known)));
    }

    /** @return array<string, string[]> role => module keys */
    public static function grants(): array
    {
        $grants = [];
        foreach (self::all() as $key => $module) {
            foreach ($module['default'] as $role) {
                $grants[$role][] = $key;
            }
        }
        // A role that was saved on the Roles & Permissions page replaces its defaults entirely.
        foreach (Setting::fetch(self::KEY) as $role => $keys) {
            $grants[$role] = array_values(array_intersect(array_keys(self::all()), (array) $keys));
        }

        return $grants;
    }

    /** @return string[] module keys this user may use */
    public static function for(?User $user): array
    {
        if (!$user) return [];
        if (in_array($user->role, self::ADMIN_ROLES, true)) return array_keys(self::all());

        return self::grants()[$user->role] ?? [];
    }

    public static function allows(?User $user, string $module): bool
    {
        return in_array($module, self::for($user), true);
    }

    /** @return string[] the role's grants after saving */
    public static function saveGrants(string $role, array $modules): array
    {
        $stored = Setting::fetch(self::KEY);
        $stored[$role] = array_values(array_intersect(array_keys(self::all()), $modules));
        Setting::put(self::KEY, $stored);

        return self::grants()[$role] ?? [];
    }
}
