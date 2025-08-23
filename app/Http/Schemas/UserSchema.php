<?php

namespace App\Http\Schemas;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     title="User",
 *     description="User model",
 *
 *     @OA\Property(property="id", type="integer", example=1, description="User ID"),
 *     @OA\Property(property="name", type="string", example="John Doe", description="User's full name"),
 *     @OA\Property(property="email", type="string", format="email", example="john.doe@example.com", description="User's email address"),
 *     @OA\Property(property="username", type="string", example="johndoe", description="User's username"),
 *     @OA\Property(property="role", type="string", enum={"admin", "moderator", "contributor", "viewer"}, example="viewer", description="User role"),
 *     @OA\Property(property="enabled", type="boolean", example=true, description="Whether the user account is enabled"),
 *     @OA\Property(property="phone", type="string", example="+1-555-123-4567", nullable=true, description="User's phone number"),
 *     @OA\Property(property="birthday", type="string", format="date", example="1990-01-15", nullable=true, description="User's birthday"),
 *     @OA\Property(property="city", type="string", example="New York", nullable=true, description="User's city"),
 *     @OA\Property(property="state", type="string", example="NY", nullable=true, description="User's state"),
 *     @OA\Property(property="country", type="string", example="United States", nullable=true, description="User's country"),
 *     @OA\Property(property="zipcode", type="string", example="10001", nullable=true, description="User's zipcode"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-15T10:30:00Z", description="Account creation timestamp"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-01-15T10:30:00Z", description="Last update timestamp")
 * )
 *
 * @OA\Schema(
 *     schema="UserPublic",
 *     type="object",
 *     title="User (Public)",
 *     description="User model for public responses (excludes sensitive fields)",
 *
 *     @OA\Property(property="id", type="integer", example=1, description="User ID"),
 *     @OA\Property(property="name", type="string", example="John Doe", description="User's full name"),
 *     @OA\Property(property="email", type="string", format="email", example="john.doe@example.com", description="User's email address"),
 *     @OA\Property(property="username", type="string", example="johndoe", description="User's username"),
 *     @OA\Property(property="role", type="string", enum={"admin", "moderator", "contributor", "viewer"}, example="viewer", description="User role"),
 *     @OA\Property(property="enabled", type="boolean", example=true, description="Whether the user account is enabled")
 * )
 *
 * @OA\Schema(
 *     schema="AuthResponse",
 *     type="object",
 *     title="Authentication Response",
 *     description="Authentication response with user data and token",
 *
 *     @OA\Property(property="message", type="string", example="Login successful", description="Response message"),
 *     @OA\Property(property="data", type="object",
 *         @OA\Property(property="user", ref="#/components/schemas/UserPublic"),
 *         @OA\Property(property="token", type="string", example="1|abcdef123456789...", description="Authentication token")
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="ValidationError",
 *     type="object",
 *     title="Validation Error",
 *     description="Standard validation error response",
 *
 *     @OA\Property(property="message", type="string", example="The given data was invalid.", description="Error message"),
 *     @OA\Property(property="errors", type="object",
 *         additionalProperties=@OA\Property(type="array", @OA\Items(type="string")),
 *         example={"email": {"The email has already been taken."}},
 *         description="Field-specific validation errors"
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *     title="Error Response",
 *     description="Standard error response",
 *
 *     @OA\Property(property="message", type="string", example="Unauthenticated.", description="Error message")
 * )
 */
class UserSchema
{
    // This class is used only for OpenAPI documentation schemas
}
