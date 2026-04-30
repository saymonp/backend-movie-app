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

    /**
     * Lista todas as roles disponíveis no sistema (útil para o select no frontend)
     */
    public function listRoles()
    {
        return response()->json(Role::all());
    }

    public function listUsers()
    {
        return response()->json(User::all());
    }
}
