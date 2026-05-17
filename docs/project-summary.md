# Project Summary

This project is a Laravel 10 ecommerce starter focused on building a minimal store core first, then using it later to demonstrate and fix checkout concurrency issues.

## What Is Included

- Authentication with Laravel Sanctum token auth.
- Products with stock, name, price, and description.
- Cart items linked to users and products.
- Orders and order items.
- A checkout flow implemented in a service class.
- Demo seed data with small stock values so concurrency problems are easy to reproduce.

## Main Domain Structure

### Users

- Laravel `User` model with Sanctum support.
- Users own cart items and orders.

### Products

- `Product` model stores:
  - `name`
  - `price`
  - `stock`
  - `description`

### Cart

- `CartItem` links:
  - `user_id`
  - `product_id`
  - `quantity`

### Orders

- `Order` stores:
  - `user_id`
  - `total_price`
  - `status`
- `OrderItem` stores:
  - `order_id`
  - `product_id`
  - `quantity`
  - `price_at_purchase`

## API Endpoints

### Authentication

- `POST /api/register`
- `POST /api/login`
- `GET /api/me`

### Products

- `GET /api/products`
- `GET /api/products/{id}`

### Cart

- `GET /api/cart`
- `POST /api/cart/add`
- `DELETE /api/cart/item`

### Orders

- `POST /api/checkout`
- `GET /api/orders`

## Current Layering

- Controllers only validate input and return JSON responses.
- `CheckoutService` contains the checkout flow and the intentionally unsafe stock update logic.
- Models define relationships and mass-assignable fields.
- Seeders provide a small dataset for testing and demos.

## Important Files

- `routes/api.php` for API wiring.
- `app/Services/CheckoutService.php` for checkout logic.
- `app/Http/Controllers/Api/*` for the API controllers.
- `app/Models/*` for the domain models.
- `database/migrations/*` for schema.
- `database/seeders/EcommerceDemoSeeder.php` for demo data.

## Demo Purpose

The current checkout flow is intentionally not protected with a transaction, lock, or atomic stock update. This makes it suitable for demonstrating a race condition before applying the fix.

## Readiness For The First Demo

The project structure is already suitable for the first concurrency test because it has:

- A dedicated `CheckoutService` that isolates the critical section.
- `stock` stored on `Product`, which acts as the shared resource.
- A clean separation between cart, orders, and products.
- Seed data with intentionally small stock values to make the issue visible quickly.

## What Must Stay Unprotected Before The Test

To keep the race condition visible in the first demo, make sure the checkout path does not use:

- `DB::transaction`
- `lockForUpdate`
- atomic stock updates
- caching that masks concurrent writes

## Practical Test Conditions

The demo is ready when all of these are true:

- The same product is used for every request.
- The same `POST /api/checkout` endpoint is hit concurrently.
- The product stock is very small, ideally `1` or `2`.
- Checkout is still handled by the unsafe section in `CheckoutService`.

## Academic Note

For the report, you can state that the system is intentionally left without concurrency control mechanisms so the race condition can be reproduced and measured clearly before the fix.
