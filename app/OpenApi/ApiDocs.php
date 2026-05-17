<?php

namespace App\OpenApi;

/**
 * @OA\Info(
 *     title="MY_STORE API",
 *     version="1.0.0",
 *     description="API documentation for the demo ecommerce app"
 * )
 *
 * @OA\Server(
 *     url="http://127.0.0.1:8000",
 *     description="Local dev server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Use the token returned from login/register as: Bearer <token>"
 * )
 */
class ApiDocs
{
    // This file only contains OpenAPI annotations for swagger-php to scan.
}
