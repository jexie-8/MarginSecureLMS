# Manual merge (only if the installer stops)

## `bootstrap/app.php`

Inside the existing `withMiddleware` callback, add this once:

```php
$middleware->statefulApi();
```

Do not add the `web` middleware to API routes. Sanctum's stateful API middleware
selectively supplies the cookie, session, and request-forgery middleware for
first-party SPA requests.

## `routes/api.php`

Remove only the old definitions for `/v1/login`, `/v1/me`, and `/v1/logout`,
then add this once at the end of the file:

```php
require __DIR__.'/margin_auth.php';
```

Keep all academic and unrelated routes unchanged.

## `config/cors.php` (when Flutter Web uses a different origin)

Publish the config first if it does not exist (`php artisan config:publish cors`).
Keep the existing file and ensure these values are merged:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => ['http://localhost:3000'], // exact Flutter origin(s)
'supports_credentials' => true,
```

Do not combine credentialed requests with the wildcard `*` origin. Update the
origin and scheme for your real Flutter development and production URLs.

Verify there is exactly one route for each authentication endpoint:

```powershell
php artisan route:list --path=api/v1 -v
```

The login route must not list `web` explicitly. Protected routes must use
`auth:web`, not `auth:sanctum`, because Margin does not accept API bearer tokens.

