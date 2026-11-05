# Margin LMS — Step 58 authentication and session security

Target: Laravel 13, Sanctum 4.3.3, PostgreSQL, Flutter Web using first-party
cookie sessions only.

## Install

1. Back up the backend (or commit the current work).
2. Extract this ZIP into the Laravel `backend` directory.
3. From `backend`, run:

   ```powershell
   php install-margin-step58.php
   php artisan optimize:clear
   php artisan route:list --path=api/v1 -v
   ```

The installer creates timestamped backups in `.margin-step58-backups/`. It does
not replace `bootstrap/app.php` or `routes/api.php`. It inserts the standard
`statefulApi()` hook and a single `require` for `routes/margin_auth.php`.

If `routes/api.php` contains only the three earlier authentication routes, the
installer safely replaces that auth-only file after backing it up. If auth
routes are mixed with custom routes, it stops and asks you to use the snippets
in `STEP58_MANUAL_MERGE.md`.

## Test database setup

Create a PostgreSQL database named `margin_test`, owned by the same PostgreSQL
user used by the development backend. Never point the test command at the
development database.

Copy `phpunit.margin.xml.dist` to `phpunit.margin.xml`. If your PostgreSQL
credentials are not already available through `.env`, add only the test
credentials to a local `.env.testing` file (do not commit it):

```dotenv
DB_CONNECTION=pgsql
DB_DATABASE=margin_test
DB_USERNAME=your_postgres_user
DB_PASSWORD=your_postgres_password
```

Run the Step 58 tests:

```powershell
php artisan test --configuration phpunit.margin.xml --filter=AuthenticationSessionSecurityTest
php artisan test --configuration phpunit.margin.xml --filter=AcademicAuthorizationTest
```

The authentication test aborts before migrations if the active connection is
not PostgreSQL database `margin_test`. Tests use the database session driver so
the production-style session lifecycle is exercised in the isolated database.

## Runtime settings

Merge the values from `.env.margin-step58.example` into the backend `.env`.
Set `SANCTUM_STATEFUL_DOMAINS` to the exact Flutter Web host and port. The SPA
must request `GET /sanctum/csrf-cookie` first, then send cookies and the decoded
`XSRF-TOKEN` value as `X-XSRF-TOKEN` on state-changing requests.

Do not create personal access tokens and do not add `HasApiTokens` for this
web-only flow. Protected Step 58 routes use `auth:web`, so bearer tokens are not
accepted.

## Expected endpoints

- `POST /api/v1/login`
- `GET /api/v1/me`
- `POST /api/v1/logout`
- `GET /sanctum/csrf-cookie` (provided by Sanctum)

