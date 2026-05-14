<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;

class UserRoleController extends Controller
{
    /**
     * Atribui uma ou mais roles a um usuário específico.
     */
    public function assignRole(Request $request, $userId)
    {
        // 1. Validação
        $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,name', // Garante que a role existe no banco
        ]);

        // 2. Localiza o usuário
        $user = User::findOrFail($userId);

        // 3. Atribui as roles
        // syncRoles remove todas as anteriores e coloca as novas
        // Se quiser apenas ADICIONAR sem remover as atuais, use assignRole()
        $user->syncRoles($request->roles);

        return response()->json([
            'message' => "Roles atualizadas com sucesso para o usuário {$user->name}",
            'user' => $user->load('roles')
        ]);
    }

    public function listPermissions()
    {
        return response()->json(\Spatie\Permission\Models\Permission::all());
    }

    // Atribuir permissões diretas ao usuário
    public function assignPermissions(Request $request, $userId)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $user = User::findOrFail($userId);

        // syncPermissions substitui as permissões diretas atuais pelas novas
        $user->syncPermissions($request->permissions);

        return response()->json([
            'message' => "Permissões diretas atualizadas para {$user->name}",
            'user' => $user->load('permissions')
        ]);
    }

    /**
     * Lista todas as roles disponíveis no sistema (útil para o select no frontend)
     */
    public function listRoles()
    {
        return response()->json(Role::all());
    }

    public function listUsers()
    {
        // Buscamos os usuários com os relacionamentos carregados para evitar o problema de N+1
        $users = User::with(['roles', 'permissions'])->get();

        // Transformamos a coleção para que cada usuário contenha apenas os nomes das roles e permissões
        $transformedUsers = $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'slug' => $user->slug,
                'is_admin' => $user->is_admin,
                // Spatie: Retorna array de strings com os nomes das roles
                'roles' => $user->getRoleNames(),
                // Spatie: Retorna todas as permissões (diretas e via roles) como nomes
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ];
        });

        return response()->json($transformedUsers);
    }
}
