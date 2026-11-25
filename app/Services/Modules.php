<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class Modules
{
    public const KEY = 'role_permissions';
    public const ADMIN_ROLES = ['admin', 'superadmin'];
    public const PORTAL_ROLES = ['employee', 'client'];

    public static function all(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'group' => 'Overview', 'locked' => 'staff'],
            'live-users' => ['label' => 'Live Workforce', 'group' => 'Overview', 'default' => ['hr']],
            'employees' => ['label' => 'Employees', 'group' => 'People', 'default' => ['hr'], 'actions' => ['create', 'edit', 'delete', 'export']],
            'hr-team' => ['label' => 'HR Team', 'group' => 'People', 'actions' => ['create', 'edit', 'delete']],
            'clients' => ['label' => 'Clients', 'group' => 'People', 'actions' => ['create', 'edit', 'delete']],
            'schedules' => ['label' => 'Schedules', 'group' => 'Time', 'default' => ['hr'], 'actions' => ['create', 'edit', 'delete']],
            'attendance' => ['label' => 'Attendance', 'group' => 'Time', 'default' => ['hr']],
            'change-requests' => ['label' => 'Change Requests', 'group' => 'Time', 'default' => ['hr'], 'actions' => ['review']],
            'overtime' => ['label' => 'Overtime Review', 'group' => 'Time', 'default' => ['hr'], 'actions' => ['review']],
            'invoices' => ['label' => 'Invoices', 'group' => 'Billing', 'actions' => ['create', 'edit', 'delete', 'export', 'send']],
            'company-finance' => ['label' => 'Company Finance', 'group' => 'Billing', 'actions' => ['create', 'delete']],
            'invoice-settings' => ['label' => 'Invoice Settings', 'group' => 'Billing', 'actions' => ['edit']],
            'notebook' => ['label' => 'Notebook', 'group' => 'Documents', 'default' => ['hr'], 'actions' => ['create', 'delete', 'export']],
            'activity-logs' => ['label' => 'Activity Logs', 'group' => 'Logs'],
            'configuration' => ['label' => 'Configuration', 'group' => 'Setup', 'default' => ['hr'], 'actions' => ['create', 'edit', 'delete']],
            'client-services' => ['label' => 'Client Services', 'group' => 'Setup', 'actions' => ['create', 'edit', 'delete']],
            'contract-types' => ['label' => 'Contract Types', 'group' => 'Setup', 'actions' => ['create', 'edit', 'delete']],
            'roles-permissions' => ['label' => 'Roles & Permissions', 'group' => 'Setup', 'locked' => 'admin', 'actions' => ['edit']],
            'branding' => ['label' => 'Branding', 'group' => 'Setup', 'actions' => ['edit', 'delete']],
            'docs' => ['label' => 'Docs', 'group' => 'Help', 'locked' => 'staff'],
            'notifications' => ['label' => 'Notifications', 'group' => 'Account', 'locked' => 'staff', 'actions' => ['edit']],
            'account' => ['label' => 'My Account', 'group' => 'Account', 'locked' => 'staff', 'actions' => ['edit']],
        ];
    }

    public static function referenceModule(string $type): string
    {
        return match ($type) {
            'services' => 'client-services',
            'contracts' => 'contract-types',
            default => 'configuration',
        };
    }

    public static function grantableRoles(): array
    {
        // The users.role enum and the staff portal currently have one editable staff role.
        return ['hr'];
    }

    public static function grantableModules(): array
    {
        return array_filter(self::all(), fn (array $module) => !isset($module['locked']));
    }

    /** The original module-only records granted every action on the enabled modules. */
    private static function roleGrants(string $role, array $stored): array
    {
        $catalog = self::grantableModules();
        $saved = $stored[$role] ?? null;
        $modules = $saved === null
            ? array_keys(array_filter($catalog, fn (array $module) => in_array($role, $module['default'] ?? [], true)))
            : array_values(array_intersect((array) ($saved['modules'] ?? $saved), array_keys($catalog)));

        $actions = [];
        foreach ($modules as $key) {
            $supported = $catalog[$key]['actions'] ?? [];
            foreach ($supported as $action) {
                $permission = "{$key}.{$action}";
                if (!isset($saved['actions']) || in_array($permission, $saved['actions'], true)) {
                    $actions[] = $permission;
                }
            }
        }

        return ['modules' => $modules, 'actions' => $actions];
    }

    private static function forUser(?User $user): array
    {
        if (!$user || in_array($user->role, self::PORTAL_ROLES, true)) {
            return ['modules' => [], 'actions' => []];
        }

        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return ['modules' => array_keys(self::all()), 'actions' => self::actionKeys()];
        }

        return self::roleGrants($user->role, Setting::fetch(self::KEY));
    }

    public static function for(?User $user): array
    {
        return self::forUser($user)['modules'];
    }

    public static function actionsFor(?User $user): array
    {
        return self::forUser($user)['actions'];
    }

    public static function allows(?User $user, string $module, string $action = 'view'): bool
    {
        if ((self::all()[$module]['locked'] ?? null) === 'staff' && $user && in_array($user->role, self::grantableRoles(), true)) {
            return $action === 'view' || in_array($action, self::all()[$module]['actions'] ?? [], true);
        }

        $grants = self::forUser($user);
        return $action === 'view'
            ? in_array($module, $grants['modules'], true)
            : in_array("{$module}.{$action}", $grants['actions'], true);
    }

    public static function actionKeys(?array $modules = null): array
    {
        $keys = [];
        foreach ($modules ?? self::all() as $key => $module) {
            foreach ($module['actions'] ?? [] as $action) {
                $keys[] = "{$key}.{$action}";
            }
        }

        return $keys;
    }

    public static function matrix(): array
    {
        $stored = Setting::fetch(self::KEY);
        $roles = [];
        foreach (self::grantableRoles() as $role) {
            $roles[$role] = self::roleGrants($role, $stored);
        }

        return $roles;
    }

    public static function saveGrants(string $role, array $modules, ?array $actions = null): void
    {
        DB::transaction(function () use ($role, $modules, $actions) {
            // Serialize changes to the shared setting, including its first write.
            Setting::query()->insertOrIgnore(['key' => self::KEY, 'value' => '[]']);
            $setting = Setting::query()->lockForUpdate()->findOrFail(self::KEY);
            $stored = $setting->value ?? [];

            if ($actions === null && isset($stored[$role]['actions'])) {
                // Older clients can still save module grants without erasing action restrictions.
                $previous = self::roleGrants($role, $stored);
                $newModules = array_diff($modules, $previous['modules']);
                $actions = array_merge($previous['actions'], self::actionKeys(array_intersect_key(self::grantableModules(), array_flip($newModules))));
            }

            $stored[$role] = $actions === null ? $modules : ['modules' => $modules, 'actions' => array_values(array_unique($actions))];
            $setting->update(['value' => $stored]);
        }, 3);

        Cache::forget('setting:'.self::KEY);
    }
}
