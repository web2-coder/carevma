<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Modules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'modules.*' => ['string', Rule::in(array_keys(Modules::all()))],
        ]);

        Modules::saveGrants($role, $data['modules']);

        $label = $this->label($role);

        return response()->json(['message' => "{$label} access updated. Signed-in {$label} users see the change on their next page load."] + $this->payload());
    }

    private function payload(): array
    {
        $roles = Modules::grantableRoles();
        $grants = Modules::grants();

        return [
            'modules' => collect(Modules::all())->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'group' => $m['group']])->values(),
            'roles' => array_map(fn ($role) => ['key' => $role, 'label' => $this->label($role)], $roles),
            'grants' => array_intersect_key($grants, array_flip($roles)) + array_fill_keys($roles, []),
        ];
    }

    private function label(string $role): string
    {
        return $role === 'hr' ? 'HR' : ucwords(str_replace(['-', '_'], ' ', $role));
    }
}
