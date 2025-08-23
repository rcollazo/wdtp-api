<?php

namespace App\Http\Controllers;

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="WDTP API",
 *     version="1.0.0",
 *     description="What Do They Pay? - A wage transparency platform for hourly workers",
 *
 *     @OA\Contact(
 *         name="WDTP API Support",
 *         url="https://wdtp.app",
 *         email="support@wdtp.app"
 *     ),
 *
 *     @OA\License(
 *         name="MIT",
 *         url="https://opensource.org/licenses/MIT"
 *     )
 * )
 *
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="WDTP API Server"
 * )
 *
 * @OA\PathItem(
 *     path="/api/v1"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="apiKey",
 *     in="header",
 *     name="Authorization",
 *     description="Enter token in format: Bearer {token}"
 * )
 *
 * @OA\Tag(
 *     name="Authentication",
 *     description="User authentication and authorization endpoints"
 * )
 * @OA\Tag(
 *     name="Health",
 *     description="API health check endpoints"
 * )
 */
abstract class Controller
{
    //
}
