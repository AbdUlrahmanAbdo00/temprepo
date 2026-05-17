# Ecommerce Core Structure

This project now contains a minimal ecommerce core built for demonstrating checkout concurrency issues later.

## Layering

- `Models` define the data shape and relationships.
- `Controllers` only validate requests and return JSON.
- `CheckoutService` owns the checkout flow and the intentionally unsafe stock update logic.
- `Seeders` provide a small dataset with low stock so the race condition is easy to reproduce.

## Main Flow

1. Register or login a user through Sanctum token auth.
2. List products and add them to the cart.
3. Call checkout.
4. The service reads stock, decrements it, creates the order, and stores order items without a transaction or lock.

## API Surface

- `POST /api/register`
- `POST /api/login`
- `GET /api/me`
- `GET /api/products`
- `GET /api/products/{id}`
- `POST /api/cart/add`
- `GET /api/cart`
- `DELETE /api/cart/item`
- `POST /api/checkout`
- `GET /api/orders`
