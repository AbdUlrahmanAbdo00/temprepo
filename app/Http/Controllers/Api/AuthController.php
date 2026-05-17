<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="Auth",
 *     description="Authentication"
 * )
 */

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/register",
     *     tags={"Auth"},
    *     summary="Register a new user",
    *     @OA\RequestBody(
    *         required=true,
    *         @OA\JsonContent(
    *             required={"name","email","password","password_confirmation"},
    *             @OA\Property(property="name", type="string", example="Test User"),
    *             @OA\Property(property="email", type="string", format="email", example="user@test.com"),
    *             @OA\Property(property="password", type="string", format="password", example="password123"),
    *             @OA\Property(property="password_confirmation", type="string", format="password", example="password123")
    *         )
    *     ),
     *     @OA\Response(response=201, description="Registered")
     * )
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Registered successfully.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * @OA\Post(
     *     path="/api/login",
     *     tags={"Auth"},
        *     summary="Login and receive an access token",
        *     @OA\RequestBody(
        *         required=true,
        *         @OA\JsonContent(
        *             required={"email","password"},
        *             @OA\Property(property="email", type="string", format="email", example="user1@test.com"),
        *             @OA\Property(property="password", type="string", format="password", example="password123")
        *         )
        *     ),
     *     @OA\Response(response=200, description="Logged in")
     * )
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/me",
     *     tags={"Auth"},
        *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Current user")
     * )
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
