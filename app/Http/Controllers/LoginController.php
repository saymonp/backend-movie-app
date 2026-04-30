<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->user();

        // Verificamos se o usuário já existe antes do updateOrCreate
        $userExists = User::where('email', $googleUser->email)->exists();

        $user = User::updateOrCreate([
            'email' => $googleUser->email,
        ], [
            'name' => $googleUser->name,
            'google_id' => $googleUser->id,
            'avatar' => $googleUser->avatar,
        ]);

        // Se for um novo usuário, atribui a role padrão
        if (!$userExists) {
            $user->assignRole('user');
        }

        $apiToken = $user->createToken('auth_token')->plainTextToken;

        // Redireciona para o seu frontend (ex: localhost:3000) levando o token na URL
        // O seu frontend então captura esse token e guarda no localStorage
        return redirect("http://localhost:3000/auth/success?token={$apiToken}");
    }

    public function register(Request $request)
    {
        $dados = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6'
        ]);

        $dados['password'] = Hash::make($dados['password']);

        $user = User::create($dados);

        // Todo usuário registrado manualmente começa como 'user'
        $user->assignRole('user');
        $apiToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $apiToken,
            'token_type' => 'Bearer',
            'user' => $user->load('roles')
        ], 201);
    }

    public function login(Request $request)
    {
        $dados = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $dados['email'])->first();

        // Verificação extra: o usuário existe e TEM uma senha definida?
        if (!$user || $user->password === null) {
            return response()->json([
                'message' => 'Esta conta utiliza login via Google. Por favor, entre com sua conta Google ou recupere sua senha.'
            ], 401);
        }

        if (!Hash::check($dados['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciais inválidas'
            ], 401);
        }

        $apiToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $apiToken,
            'token_type' => 'Bearer',
            'user' => $user->load('roles')
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout realizado'
        ], 200);
    }

    /**
     * O próprio usuário deleta sua conta
     */
    public function deleteOwnAccount(Request $request)
    {
        $user = $request->user(); // Pega o usuário pelo Token, 100% seguro

        // Opcional: Revogar todos os tokens antes de deletar
        $user->tokens()->delete();

        $user->delete();

        return response()->json(['message' => 'Sua conta foi excluída com sucesso.'], 200);
    }

    /**
     * Admin deleta um usuário específico
     */
    public function destroy($id)
    {
        // Apenas admins chegam aqui por causa do middleware na rota
        $user = User::findOrFail($id);

        $user->delete();

        return response()->json(['message' => 'Usuário excluído pelo administrador.'], 200);
    }
}
