# Load Balancing Demo

## Goal

Use Apache `mod_proxy_balancer` as the front door and run `php artisan serve --port=8080` as the second backend. Then verify distribution by hitting `/server-info`, which exposes the backend identity through PHP SAPI, PID, and proxy headers.

---

## 1. Enable Apache Modules

Open `Apache (httpd.conf)` from XAMPP and make sure these modules are enabled:

```apache
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_balancer_module modules/mod_proxy_balancer.so
LoadModule proxy_http_module modules/mod_proxy_http.so
LoadModule lbmethod_byrequests_module modules/mod_lbmethod_byrequests.so
```

Restart Apache after saving the file.

---

## 2. Start the Second Backend

In a terminal inside the project root, run:

```bash
php artisan serve --port=8080
```

This is the second backend server.

---

## 3. Apache Virtual Host Example

Use a balancer vhost like this:

```apache
<Proxy "balancer://mycluster">
    BalancerMember "http://127.0.0.1:8000" retry=1
    BalancerMember "http://127.0.0.1:8080" retry=1
    ProxySet lbmethod=byrequests
</Proxy>

<VirtualHost *:80>
    ServerName my-ecommerce-app.test

    ProxyPass "/" "balancer://mycluster/"
    ProxyPassReverse "/" "balancer://mycluster/"
</VirtualHost>

<VirtualHost *:8000>
    DocumentRoot "C:/xampp/htdocs/my-ecommerce-app/public"
    <Directory "C:/xampp/htdocs/my-ecommerce-app/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

If your project path differs, update it accordingly.

---

## 4. Verification Endpoint

The application now includes:

- `GET /server-info`
- `GET /health`

Example response fields from `/server-info`:

- `php_sapi`
- `process_id`
- `server_software`
- `server_port`
- `x_forwarded_host`
- `x_forwarded_proto`

You can refresh the endpoint several times or run load testing to see responses alternate between backends.

---

## 5. Load Test With k6

Run the included script:

```bash
k6 run --env BASE_URL=http://my-ecommerce-app.test scripts/k6_load_balance_demo.js
```

What to look for:

- Lines labeled `APACHE` and `ARTISAN`
- Different `php_sapi` values
- Different process IDs
- Summary counts at the end

---

## 6. Manual Browser Check

Open:

```text
http://my-ecommerce-app.test/server-info
```

Refresh several times. If the balancer is working, you should see the backend identity change between requests.

---

## 7. Instructor-Friendly Proof

For a quick demo, show these three things:

1. Apache vhost with `balancer://mycluster`
2. A running `php artisan serve --port=8080`
3. Repeated `/server-info` responses showing different backend identity

---

## 8. Notes

- This is a lightweight simulation of load balancing inside XAMPP.
- It avoids introducing NGINX or external infrastructure.
- It is ideal for classroom demonstration and verification.
