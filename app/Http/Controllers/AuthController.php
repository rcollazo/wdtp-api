<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * @OA\Post(
     *     path="/api/v1/auth/register",
     *     tags={"Authentication"},
     *     summary="Register a new user account",
     *     description="Creates a new user account and returns authentication token",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "email", "username", "password", "password_confirmation"},
     *
     *             @OA\Property(property="name", type="string", maxLength=255, example="John Doe", description="User's full name"),
     *             @OA\Property(property="email", type="string", format="email", maxLength=255, example="john.doe@example.com", description="User's email address"),
     *             @OA\Property(property="username", type="string", maxLength=255, example="johndoe", description="Unique username"),
     *             @OA\Property(property="password", type="string", minLength=8, example="password123", description="User's password (minimum 8 characters)"),
     *             @OA\Property(property="password_confirmation", type="string", example="password123", description="Password confirmation"),
     *             @OA\Property(property="phone", type="string", maxLength=20, example="+1-555-123-4567", description="User's phone number (optional)"),
     *             @OA\Property(property="birthday", type="string", format="date", example="1990-01-15", description="User's birthday (optional)"),
     *             @OA\Property(property="city", type="string", maxLength=100, example="New York", description="User's city (optional)"),
     *             @OA\Property(property="state", type="string", maxLength=100, example="NY", description="User's state (optional)"),
     *             @OA\Property(property="country", type="string", maxLength=100, example="United States", description="User's country (optional)"),
     *             @OA\Property(property="zipcode", type="string", maxLength=20, example="10001", description="User's zipcode (optional)")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="User registered successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="User registered successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *                     @OA\Property(property="username", type="string", example="johndoe"),
     *                     @OA\Property(property="role", type="string", enum={"admin", "moderator", "contributor", "viewer"}, example="viewer"),
     *                     @OA\Property(property="enabled", type="boolean", example=true)
     *                 ),
     *                 @OA\Property(property="token", type="string", example="1|abcdef123456789...")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="email", type="array", @OA\Items(type="string", example="The email has already been taken."))
     *             )
     *         )
     *     )
     * )
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'username' => $request->validated('username'),
            'password' => $request->validated('password'),
            'phone' => $request->validated('phone'),
            'birthday' => $request->validated('birthday'),
            'city' => $request->validated('city'),
            'state' => $request->validated('state'),
            'country' => $request->validated('country'),
            'zipcode' => $request->validated('zipcode'),
            'enabled' => true, // Enable user upon registration
        ]);

        $deviceName = 'API Registration';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'enabled' => $user->enabled,
                ],
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Authenticate a user and return a token.
     *
     * @OA\Post(
     *     path="/api/v1/auth/login",
     *     tags={"Authentication"},
     *     summary="Authenticate user and get access token",
     *     description="Validates user credentials and returns authentication token",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *
     *             @OA\Property(property="email", type="string", format="email", example="john.doe@example.com", description="User's email address"),
     *             @OA\Property(property="password", type="string", example="password123", description="User's password"),
     *             @OA\Property(property="device_name", type="string", maxLength=255, example="iPhone App", description="Device name for token identification (optional)")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *                     @OA\Property(property="username", type="string", example="johndoe"),
     *                     @OA\Property(property="role", type="string", enum={"admin", "moderator", "contributor", "viewer"}, example="viewer"),
     *                     @OA\Property(property="enabled", type="boolean", example=true)
     *                 ),
     *                 @OA\Property(property="token", type="string", example="1|abcdef123456789...")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Authentication failed",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="email", type="array", @OA\Items(type="string", example="The provided credentials are incorrect."))
     *             )
     *         )
     *     )
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->enabled) {
            throw ValidationException::withMessages([
                'email' => ['This account has been disabled.'],
            ]);
        }

        $deviceName = $request->validated('device_name') ?? 'API Login';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'enabled' => $user->enabled,
                ],
                'token' => $token,
            ],
        ]);
    }

    /**
     * Revoke the current user's token.
     *
     * @OA\Post(
     *     path="/api/v1/auth/logout",
     *     tags={"Authentication"},
     *     summary="Logout and revoke access token",
     *     description="Revokes the current user's authentication token",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Logged out successfully")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get the authenticated user's information.
     *
     * @OA\Get(
     *     path="/api/v1/auth/me",
     *     tags={"Authentication"},
     *     summary="Get current user profile",
     *     description="Returns the authenticated user's profile information",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="User profile retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *                     @OA\Property(property="username", type="string", example="johndoe"),
     *                     @OA\Property(property="role", type="string", enum={"admin", "moderator", "contributor", "viewer"}, example="viewer"),
     *                     @OA\Property(property="enabled", type="boolean", example=true),
     *                     @OA\Property(property="phone", type="string", example="+1-555-123-4567", nullable=true),
     *                     @OA\Property(property="birthday", type="string", format="date", example="1990-01-15", nullable=true),
     *                     @OA\Property(property="city", type="string", example="New York", nullable=true),
     *                     @OA\Property(property="state", type="string", example="NY", nullable=true),
     *                     @OA\Property(property="country", type="string", example="United States", nullable=true),
     *                     @OA\Property(property="zipcode", type="string", example="10001", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-15T10:30:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-01-15T10:30:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'enabled' => $user->enabled,
                    'phone' => $user->phone,
                    'birthday' => $user->birthday?->toDateString(),
                    'city' => $user->city,
                    'state' => $user->state,
                    'country' => $user->country,
                    'zipcode' => $user->zipcode,
                    'created_at' => $user->created_at?->toISOString(),
                    'updated_at' => $user->updated_at?->toISOString(),
                ],
            ],
        ]);
    }
}
