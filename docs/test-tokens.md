# Test Tokens and Quick Checkout Test

Generated tokens for seeded users (do not share publicly; for local testing only):

- user1@test.com: 1|cOht6LPC90nQ9eSi8DwgpqJvAK3v0XeWLxTRJdWea18869c1
- user2@test.com: 2|ceYmtNuUs6cMnji7lxA3K5w7vdkEiNZyzxbWoq9da804862d
- user3@test.com: 3|8TSRdlgbOGCjHI6k0dmp7OVibBy112ApVpu9y7dafc9a4c06
- user4@test.com: 4|z09KK6zFMskJIvGk3RNFNyH2Gju1lRmmsiNtXWeo6afd51a8
- user5@test.com: 5|lduCIhixgKLB6v4o39ecWYGrje0GVdt8zPZjEXZv49cea787

Products (id | name | stock):

- 1 | Demo Product A | stock=1
- 2 | Demo Product B | stock=2
- 3 | Demo Product C | stock=8
- 4 | Demo Product D | stock=5
- 5 | Demo Product E | stock=20

Notes:
- Demo Product A (id=1) has stock `1` — use this to reproduce the race condition quickly.
- All seeded users use the password: `password123` (you may still use the tokens below directly).

---

## Quick single-user test (manual)

1. Add the product to a user's cart (here `user1`):

```bash
curl -X POST http://127.0.0.1:8000/api/cart/add \
  -H "Authorization: Bearer 1|cOht6LPC90nQ9eSi8DwgpqJvAK3v0XeWLxTRJdWea18869c1" \
  -H "Content-Type: application/json" \
  -d '{"product_id":1,"quantity":1}'
```

2. Trigger checkout:

```bash
curl -X POST http://127.0.0.1:8000/api/checkout \
  -H "Authorization: Bearer 1|cOht6LPC90nQ9eSi8DwgpqJvAK3v0XeWLxTRJdWea18869c1" \
  -H "Content-Type: application/json"
```

Expected: order created (201) and product stock decremented to 0.

---

## Prepare all users (add the same product to every user's cart)

Run for each token (example shown in bash):

```bash
TOKENS=( \
  "1|cOht6LPC90nQ9eSi8DwgpqJvAK3v0XeWLxTRJdWea18869c1" \
  "2|ceYmtNuUs6cMnji7lxA3K5w7vdkEiNZyzxbWoq9da804862d" \
  "3|8TSRdlgbOGCjHI6k0dmp7OVibBy112ApVpu9y7dafc9a4c06" \
  "4|z09KK6zFMskJIvGk3RNFNyH2Gju1lRmmsiNtXWeo6afd51a8" \
  "5|lduCIhixgKLB6v4o39ecWYGrje0GVdt8zPZjEXZv49cea787" \
)

for t in "${TOKENS[@]}"; do
  curl -s -X POST http://127.0.0.1:8000/api/cart/add \
    -H "Authorization: Bearer $t" \
    -H "Content-Type: application/json" \
    -d '{"product_id":1,"quantity":1}' &
done
wait
```

This adds `Demo Product A` (id=1) to each user's cart.

---

## Concurrent checkout demo (reproduce race condition)

This sends many checkout requests concurrently (5 tokens × 20 rounds = 100 requests).

Bash example (Linux / WSL / Git Bash):

```bash
TOKENS=("<paste tokens here as above>")
for i in {1..20}; do
  for t in "${TOKENS[@]}"; do
    curl -s -X POST http://127.0.0.1:8000/api/checkout \
      -H "Authorization: Bearer $t" \
      -H "Content-Type: application/json" >/dev/null &
  done
done
wait
```

PowerShell example (Windows):

```powershell
$tokens = @(
  '1|cOht6LPC90nQ9eSi8DwgpqJvAK3v0XeWLxTRJdWea18869c1',
  '2|ceYmtNuUs6cMnji7lxA3K5w7vdkEiNZyzxbWoq9da804862d',
  '3|8TSRdlgbOGCjHI6k0dmp7OVibBy112ApVpu9y7dafc9a4c06',
  '4|z09KK6zFMskJIvGk3RNFNyH2Gju1lRmmsiNtXWeo6afd51a8',
  '5|lduCIhixgKLB6v4o39ecWYGrje0GVdt8zPZjEXZv49cea787'
)

1..20 | ForEach-Object {
  foreach ($t in $tokens) {
    Start-Job -ScriptBlock {
      param($token)
      Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/checkout' -Method Post -Headers @{ Authorization = "Bearer $token" }
    } -ArgumentList $t | Out-Null
  }
}

# Wait for jobs to finish
Get-Job | Wait-Job | Receive-Job
```

Expected outcome (Before Fix):

- More than one checkout may succeed for `Demo Product A` (stock=1), resulting in orders > stock and possibly stock going negative or inconsistent.
- Responses for failed attempts should be 422 with message `Out of stock...` (as thrown by the current `CheckoutService`).

---

## Notes and tips

- The demo is intentionally unsafe; after reproducing the problem you can implement fixes (DB transaction + `lockForUpdate` or atomic `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?`) and compare results.
- If you rerun the concurrent script, remember to re-add the product to each user's cart (or clear carts and re-add) because successful checkouts remove cart items.

---

If you want, I can also:

- Produce a Postman collection (JSON) with the `add to cart` and `checkout` requests and a pre-filled environment with the tokens.
- Create a small Node/PHP harness that runs concurrent requests and collects per-request responses to produce a before/after report.
