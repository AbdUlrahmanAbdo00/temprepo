# Swagger (L5-Swagger) Integration

This project now contains OpenAPI annotations in controllers and a minimal `config/l5-swagger.php` so you can generate and view API docs using L5-Swagger.

Steps to install and view the Swagger UI:

1. Install the package via Composer:

```bash
composer require darkaonline/l5-swagger
```

2. Publish the vendor files and config:

```bash
php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```

3. Generate the docs (this scans `app/` for annotations):

```bash
php artisan l5-swagger:generate
```

4. Open the UI in your browser (default):

```
http://127.0.0.1:8000/api/documentation
```

Notes:
- If you changed `config/l5-swagger.php`, re-run `php artisan l5-swagger:generate` after publishing.
- The annotations are basic and intended to give you a working UI; you can expand them using `@OA` annotations as needed.

If you want, I can also generate a Postman collection from the generated OpenAPI JSON once you've run the `l5-swagger:generate` command.
