<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Jobs\SendEmailJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        // Redireciona para o frontendlevando o token na URL
        // O frontend captura o token e guarda no localStorage
        $frontend = config('services.google.redirect');

        return redirect($frontend . "/auth/success?token={$apiToken}");
    }

    public function register(Request $request)
    {
        $dados = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'termos_versao' => 'required|numeric',
            'aceitou_termos' => 'required|boolean'
        ]);
        if ($dados['aceitou_termos'] === true) {
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
        } else {
            return response()->json([
                'message' => 'Os termos precisam ser aceitos'
            ], 400);
        }
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
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->hasRole('admin'), // Um booleano rápido
                // Transforma a coleção de objetos em um array simples de nomes
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'roles' => $user->getRoleNames(),
            ]
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

    public function recoverPasswordRequest(Request $request)
    {
        $dados = $request->validate([
            'email' => 'required|email'
        ]);
        $user = User::where('email', $dados['email'])->first();

        if ($user) {
            $token = Str::random(60);
            // Salva ou atualiza o token na tabela padrão do Laravel (evita duplicados)
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => $token, // Idealmente convertido em hash, mas a string limpa já resolve o fluxo
                    'created_at' => now()
                ]
            );

            $frontUrl = config('app.frontend_url');
            SendEmailJob::dispatch(
                $user->email,
                'Recuperação de Senha - Filmeiro',
                "<h1>Olá, {$user->name}!</h1>
             <p>Recebemos uma solicitação para redefinir a sua senha.</p>
             <p>Clique no link abaixo para prosseguir:</p>
             <a href='{$frontUrl}/reset-password?token={$token}&email={$user->email}'>Redefinir Senha</a>"
            );
        }
        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, um link de redefinição será enviado.'
        ], 200);
    }

    public function resetPassword(Request $request)
    {
        $dados = $request->validate([
            'email'        => 'required|email',
            'token'        => 'required|string',
            'new_password' => ['required', 'string'],
        ]);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $dados['email'])
            ->where('token', $dados['token'])
            ->first();

        if (! $resetRecord || now()->parse($resetRecord->created_at)->addMinutes(60)->isPast()) {
            return response()->json([
                'message' => 'Token de recuperação inválido ou expirado.'
            ], 422);
        }
        $user = User::where('email', $dados['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => 'Incapaz de redefinir a senha para este usuário.'
            ], 404);
        }

        $user->update([
            'password' => Hash::make($dados['new_password'])
        ]);
        DB::table('password_reset_tokens')->where('email', $dados['email'])->delete();
        // Deleta tokens antigos por segurança
        $user->tokens()->delete();
        $apiToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $apiToken,
            'token_type'   => 'Bearer',
            'user'         => $user->load('roles')
        ], 200);
    }
}
