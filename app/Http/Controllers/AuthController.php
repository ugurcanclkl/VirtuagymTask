<?php

namespace App\Http\Controllers;

use App\Actions\RegisterUser;
use App\Exceptions\ApiException;
use App\Http\ApiResponse;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        $user = $register->handle($request->validated());

        return ApiResponse::success(['user' => $user, 'wallet_id' => $user->wallet->id], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
        ]);
        if (strlen($data['password']) > 72 || str_contains($data['password'], "\0")) {
            throw new ApiException('The email or password is incorrect.', 1003, 401);
        }
        $user = User::where('email', strtolower($data['email']))->first();

        // Also check a dummy hash on unknown accounts to avoid a cheap timing distinction.
        $hash = $user?->password ?? '$2y$12$w1WShScxeFsQeIlPNdCjNuGhEFcwD91ILJG1Sky.rsuNCOGX9hbkC';
        $matches = Hash::check($data['password'], $hash);
        if (! $user || ! $matches) {
            throw new ApiException('The email or password is incorrect.', 1003, 401);
        }

        $token = $user->createToken('interview-api', ['wallet:access']);

        return ApiResponse::success(['access_token' => $token->plainTextToken, 'token_type' => 'Bearer']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(['message' => 'Logged out.']);
    }
}
