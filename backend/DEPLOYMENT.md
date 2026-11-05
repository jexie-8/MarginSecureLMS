# Margin LMS production deployment

Serve the Flutter build and Laravel API over HTTPS on the same parent domain. Keep PostgreSQL, Redis/queue workers, storage, and secrets private. Never commit `.env`.

1. Copy `.env.production.example` to `.env`, set unique credentials, then run `php artisan key:generate`.
2. Install production dependencies with `composer install --no-dev --optimize-autoloader`.
3. Run `php artisan migrate --force`, `php artisan storage:link`, `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`.
4. Run the web process behind an HTTPS reverse proxy, plus `php artisan queue:work --tries=3` and the scheduler every minute.
5. Build Flutter with the production API base URL, deploy its `build/web` directory, and allow only that origin in CORS/Sanctum.
6. Back up PostgreSQL and `storage/app/private` daily, encrypt backups, and test restores. Retain audit logs separately.
7. Monitor `/up`, HTTP 5xx rates, queue failures, disk usage, certificate expiry, PostgreSQL health, and backup completion.
8. Install ClamAV and set `CLAMAV_COMMAND=clamscan` to fail closed on uploaded-file scan errors.

Before release, run `php artisan test --configuration phpunit.margin.xml`, `flutter analyze`, `flutter test`, and a browser smoke test for every role. Rotate demo passwords or remove demo accounts.
