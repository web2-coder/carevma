<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Modules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RolePermissionsController extends Controller
{
    public function index()
    {
        return response()->json($this->payload());
    }

    public function update(Request $request, string $role)
    {
        abort_unless(in_array($role, Modules::grantableRoles(), true), 404, 'Unknown role.');

        $data = $request->validate([
            'modules' => ['present', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(Modules::grantableModules()))],
            'actions' => ['sometimes', 'array'],
            'actions.*' => ['string', Rule::in(Modules::actionKeys(Modules::grantableModules()))],
        ]);

        foreach ($data['actions'] ?? [] as $permission) {
            if (!in_array(explode('.', $permission, 2)[0], $data['modules'], true)) {
                throw ValidationException::withMessages(['actions' => 'Enable View on a module before granting its actions.']);
            }
        }

        Modules::saveGrants($role, array_values(array_unique($data['modules'])), $data['actions'] ?? null);

        $label = $this->label($role);

        return response()->json(['message' => "{$label} access updated. Signed-in {$label} users see the change on their next page load."] + $this->payload());
    }

    private function payload(): array
    {
        $roles = Modules::grantableRoles();
        $matrix = Modules::matrix();

        return [
            'modules' => collect(Modules::all())->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'group' => $m['group']])->values(),
            'module_actions' => collect(Modules::all())->map(fn ($module) => $module['actions'] ?? []),
            'locked_modules' => collect(Modules::all())->map(fn ($module) => $module['locked'] ?? null)->filter(),
            'roles' => array_map(fn ($role) => ['key' => $role, 'label' => $this->label($role)], $roles),
            'grants' => array_map(fn ($row) => $row['modules'], $matrix),
            'action_grants' => array_map(fn ($row) => $row['actions'], $matrix),
        ];
    }

    private function label(string $role): string
    {
        return $role === 'hr' ? 'HR' : ucwords(str_replace(['-', '_'], ' ', $role));
    }
}
