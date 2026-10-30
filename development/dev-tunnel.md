# Local development through VS Code Dev Tunnels

The configured application address is `https://7b8w9dgt-8000.inc1.devtunnels.ms` and the local backend runs on port 8000. `APP_URL` does not create a tunnel; the VS Code forwarded port must remain active.

Start the backend if needed:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

In VS Code, open **Ports** and forward **8000**. Keep VS Code and the Laravel server running. Use the forwarded address shown there; if it changes, update `APP_URL` in `.env` and run `php artisan config:clear`. Keep the current port visibility unless a different access policy is intended. See [VS Code port-forwarding instructions](https://code.visualstudio.com/docs/debugtest/port-forwarding).

For CSS and JavaScript through a single forwarded port, use compiled assets:

```sh
npm run build
php artisan config:clear
```

Stop any Vite development server first. Its `public/hot` marker directs browsers to the separate development asset server, which needs its own forwarding configuration. The current setup has no hot marker and uses the existing production build. Rebuild after frontend changes.

`bootstrap/app.php` trusts forwarded host/protocol/port/client-IP headers only from loopback relay addresses (`127.0.0.1`, `::1`). This allows HTTPS links and redirects through the tunnel while rejecting spoofed proxy headers from other clients. `SESSION_DOMAIN` remains null so cookies use the visiting hostname. See [Laravel trusted-proxy documentation](https://laravel.com/framework/docs/requests#configuring-trusted-proxies).

Two focused feature tests verify secure tunnel login/logo URLs and rejection of untrusted forwarded hosts. No database, permission, tunnel visibility or login-account changes are needed.
