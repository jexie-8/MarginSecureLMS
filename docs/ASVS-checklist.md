# Margin LMS: OWASP ASVS Mapping Checklist

**Reference standard:** OWASP Application Security Verification Standard (ASVS) 5.0, organised by its chapters.
**Target level:** Level 1 for every chapter, with Level 2 items noted where the app already meets them.
**Date of assessment:** 2026-11-23 against the code. **Updated 2026-11-25** against the running local Docker deployment (`deploy/local/`: Cloudflare quick tunnel -> ModSecurity/OWASP CRS firewall -> app -> PostgreSQL) and the OWASP WSTG retest (`docs/security-testing/vulnerability-log.md`). AWS (`copilot/`) is no longer the deployment target; its files remain in the repository for reference.

This checklist is organised by ASVS chapter rather than by individual requirement number, so each row can be checked against the official document and the evidence file. It records what the code does today, including gaps. It is a design and code assessment, not a certification: the "Verified by test" column says whether an automated test covers the control.

**Status key:** ✅ Met · 🟡 Partly met · ❌ Not met · ➖ Not applicable · ⏳ Depends on deployment or on the WSTG testing phase

## Summary

| Status | Count |
|---|---|
| ✅ Met | 93 |
| 🟡 Partly met | 3 |
| ❌ Not met | 0 |
| ⏳ Depends on deployment | 0 |
| ➖ Not applicable | 3 |

## V1 Encoding and Sanitization

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Database queries use parameter binding (no string-built SQL) | ✅ | Eloquent and query builder with bindings throughout `app/`; the only raw SQL uses bound parameters (`SetDatabaseUserContext`, `GradeService`) | Partly (auth and workflow tests) |
| API responses are JSON, not HTML, so there is no server-side HTML injection surface | ✅ | `app/Http/Resources/Api/*`; `shouldRenderJsonWhen` in `bootstrap/app.php` | No |
| Frontend shows user text as plain text, never as HTML | ✅ | Flutter text widgets in `frontend/lib` (no HTML renderer used) | No |
| Stored free text (announcements, titles, feedback) is normalised or stripped | ✅ | `SanitizeInput` middleware: control characters removed everywhere; markup and invisible/direction-changing characters removed from names, titles and codes; long text keeps legitimate `<` characters; frontend `input_cleaner.dart` tidies text before sending | Yes (`InputSanitizationTest`, `input_cleaner_test.dart`) |
| CSV export neutralises spreadsheet formulas | ✅ | `csvCell()` in `LearningToolsController` prefixes `= + - @` values with a quote | Code review; WSTG-INPV-18 recorded as a manual check |
| No OS command injection from user input | ✅ | The only process call is the ClamAV scanner, with the command from config and the file path from the framework (`FileSecurityScanner`) | No |

## V2 Validation and Business Logic

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Every write endpoint validates input on the server | ✅ | `$request->validate()` / Form Requests in all controllers | Yes (`LmsApiWorkflowTest`) |
| Mass assignment is restricted to listed fields | ✅ | `#[Fillable]` on models; role changes go through `UserController` hierarchy checks | Yes |
| Business rules are enforced server-side (deadlines, enrolment, one grade per submission, TA cannot publish) | ✅ | `SubmissionPolicy`, `GradePolicy`, `GradeService`, DB unique and check constraints, RLS | Yes (`AcademicAuthorizationTest`, `RowLevelSecurityTest`) |
| Published grade changes require a reason and keep old and new values | ✅ | `GradeService::storeGrade`, `grade_revisions` table | Yes |
| Rate limits on API and sensitive flows | ✅ | `margin-api`, `password-reset` limiters in `AppServiceProvider`; `throttle:5,1` on MFA challenge | Yes (`SecurityHardeningTest`) |
| Maximum length on all free-text fields | ✅ | Every string rule in the controllers carries a `max:` or `size:` (audited 2026-11-23) | Yes (validation tests) |

## V3 Web Frontend Security

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| `X-Content-Type-Options: nosniff` | ✅ | `SecurityHeaders` middleware | Yes |
| Clickjacking protection (`X-Frame-Options: DENY`) | ✅ | `SecurityHeaders` middleware | Yes |
| `Referrer-Policy` and `Permissions-Policy` set | ✅ | `SecurityHeaders` middleware | Yes |
| Content-Security-Policy | ✅ | API: `default-src 'none'; frame-ancestors 'none'` on every response (`SecurityHeaders`). Web app: strict policy in `web/index.html` (self-hosted scripts, only the API and Google's font host as outside addresses). Loaded in a real browser with no violations except the font host, which was then allowed | Yes (header test); browser-checked |
| HSTS | ✅ | `Strict-Transport-Security: max-age=31536000` on the API (`SecurityHeaders`) and on the web app (`deploy/local/nginx.conf`); browsers honour it once the site is reached over the tunnel's HTTPS | Yes (WSTG-CONF-07, header check) |
| CORS limited to the known frontend origin | ✅ | `config/cors.php` reads an allow-list from `CORS_ALLOWED_ORIGINS` | No |
| No secrets, keys or personal data in the frontend build | ✅ | `frontend/scripts/build_web.ps1` builds a release bundle (HTTPS API required, no source maps) and fails if it finds development addresses, keys or tokens; passes on the current build | Script check |
| Client-side storage holds no credentials | ✅ | Web client relies on HTTP-only cookies; nothing written to local storage | No |

## V4 API and Web Service

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| All non-public API routes require authentication | ✅ | `auth:web` plus `EnsureAccountIsActive` in `routes/lms_api.php` | Yes |
| Public routes limited to login, MFA challenge and password reset | ✅ | `routes/margin_auth.php`, `routes/lms_api.php` | Yes |
| Only intended HTTP methods are routed | ✅ | `apiResource(...)->only([...])` and explicit routes; the firewall allows only `GET HEAD POST PUT PATCH DELETE OPTIONS` (`ALLOWED_METHODS`, fixed in V-07) | Yes (WSTG-CONF-06; `deploy_smoke.py`: PUT passes, TRACE refused) |
| CSRF protection on state-changing requests | ✅ | Sanctum CSRF cookie; Flutter client sends `X-XSRF-TOKEN` (`api_client.dart`) | Yes |
| API responses are not cached | ✅ | `Cache-Control: no-store, private` for `api/*` | Yes |
| API versioned | ✅ | `/api/v1` prefix | No |

## V5 File Handling

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Upload size limit | ✅ | `max:25600` (25 MB) in `LearningToolsController` | No |
| File type allow-list based on detected content type | ✅ | `FileSecurityScanner::ALLOWED` checks `getMimeType()` | Yes |
| Antivirus scan before storing | ✅ | ClamAV in the app image with `freshclam` keeping signatures current (`clamav-db` volume); `FILE_SCAN_REQUIRED=true`. Through the firewall: clean PNG accepted (201), EICAR test file refused (422 "The file failed its security scan.") | Yes (`deploy_smoke.py`, WSTG upload checks) |
| Stored with generated names, outside any public web root | ✅ | `$file->store()` on the private disk | Yes |
| Download requires an authorisation check | ✅ | `downloadMaterial`, `submissionFile`, `SubmissionPolicy` | Yes |
| Archive files cannot cause resource exhaustion | ✅ | `FileSecurityScanner` reads the central directory of every ZIP container (including .docx/.xlsx) without unpacking it and refuses archives that are too large when unpacked (default 256 MB), have too many entries (default 2000), have an extreme compression ratio, are nested, split or ZIP64 | Yes (`ArchiveInspectionTest`; live: `deploy_smoke.py` ZIP-bomb check) |
| Files kept in private storage, never served directly | ✅ | All uploads and downloads go through `PrivateFiles`, which refuses any public disk and streams downloads after the permission check. Local deployment: `FILES_DISK=local` on the `app-files` volume (`storage/app/private`); nginx serves only `/var/www/web`, so no path reaches it | Yes (`PrivateFileStorageTest`; WSTG-CONF-04 sensitive-path checks) |

## V6 Authentication

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Minimum password length of 12 | ✅ | `Password::min(12)` in `PasswordController`, `PasswordResetController` | Yes |
| Password complexity rules | ✅ | `mixedCase()->numbers()->symbols()` | Yes |
| Check against known breached passwords | ✅ | `PasswordPolicy::rule()` adds Laravel's `uncompromised()` check (only a 5-character hash prefix is sent to Have I Been Pwned; skipped if the service is down); used for password change, reset and bulk import (import previously only checked length) | Yes (`AccountProtectionGapsTest`) |
| Passwords hashed with an adaptive algorithm | ✅ | bcrypt, 12 rounds; `password` cast `hashed` in `User` | Yes |
| Login throttling and temporary lockout | ✅ | 5 attempts per minute per email and IP (`AuthController`) | Yes |
| Generic error for wrong credentials or disabled account | ✅ | "Invalid credentials or account unavailable." | Yes |
| Password change requires the current password | ✅ | `current_password:web` rule | Yes |
| Password reset by time-limited emailed link | ✅ | Laravel broker, 60-minute expiry (`config/auth.php`); same response whether or not the email exists | Yes |
| Password reset ends existing sessions | ✅ | Fixed 2026-11-23 in `PasswordResetController` | Yes (`PasswordResetSessionRevocationTest`) |
| Multi-factor authentication available | ✅ | TOTP available to every role (students, lecturers, teaching assistants, administrators) on web and mobile; once enrolled, both login flows require the second factor. Enrolment needs the current password; 8 one-time recovery codes stored hashed. Enrolment is optional (accepted gap: ASVS Level 2 6.3.3 asks for mandatory MFA) | Yes (`SecurityHardeningTest`, `MobileTokenAuthenticationTest` per role; Flutter `mfa_all_roles_test.dart`; live: `deploy_smoke.py` DEPLOY-MFA) |
| A used TOTP code cannot be replayed within its time window | ✅ | `MfaVerifier` remembers each accepted 30-second step for 3 minutes | Yes (`AccountProtectionGapsTest`) |
| No default or demo accounts in production | ✅ | `DatabaseSeeder` throws in production | Yes |

## V7 Session Management

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Session identifiers are long and random | ✅ | Laravel `Str::random(40)` from `random_bytes` | n/a |
| New session identifier after login | ✅ | `session()->regenerate()` in `AuthController`, `MfaController` | Yes |
| Logout invalidates the session server-side | ✅ | `session()->invalidate()` | Yes |
| Cookie flags: Secure, HttpOnly, SameSite | ✅ | `deploy/local/docker-compose.yml` sets `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE=lax`; observed through the firewall: `secure; httponly; samesite=lax` | Yes (WSTG-SESS-02, `deploy_smoke.py`) |
| Session data encrypted at rest | ✅ | `SESSION_ENCRYPT=true` | No |
| Idle timeout | ✅ | `SESSION_LIFETIME=120` minutes | No |
| Absolute session timeout | ✅ | `EnforceAbsoluteSessionLifetime`: 12 hours by default (`SESSION_ABSOLUTE_MINUTES`), audited | Yes (`AccountProtectionGapsTest`) |
| User can list and revoke active sessions | ✅ | `SessionManagementController` (revocation needs the password) | Yes |
| Sessions ended on password change and on account deactivation | ✅ | `PasswordController`, `EnsureAccountIsActive` | Yes |

## V8 Authorization

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Access decisions made on the server, never from client data | ✅ | Policies in `app/Policies/*` | Yes |
| Deny by default | ✅ | Policies return false unless a rule matches; RLS returns nothing without a user context | Yes |
| Object-level checks (IDOR) on every resource | ✅ | `Gate::authorize` in controllers (36 call sites) plus RLS on grades and submissions; the RLS context is set before route-model binding (V-02) | Yes (WSTG-ATHZ-04 cross-tenant and same-course sweep, `SecurityTestingFixesTest`) |
| University / tenant isolation | ✅ | University checks in policies; RLS functions compare university IDs | Yes |
| Administrators cannot read private academic records | ✅ | Policies and RLS return false for admin roles | Yes |
| Role assignment follows the hierarchy (cannot grant equal or higher role) | ✅ | `UserController` role checks | Yes |
| Function-level checks (TA cannot publish grades) | ✅ | `GradePolicy::publish`, RLS update policy | Yes |
| Database enforces access as a second layer | ✅ | RLS on `submissions`, `grades`, `grade_revisions` (added 2026-11-23) | Yes (`RowLevelSecurityTest`) |

## V9 Self-contained Tokens

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| JWT / self-contained token handling | ➖ | Web uses server-side sessions, not JWTs | n/a |
| Mobile API tokens (Sanctum) are expiring and revocable | ✅ | 8-hour expiry (`MOBILE_TOKEN_HOURS`), revoked on logout, password change, password reset and account deactivation; expired tokens pruned daily; stored in the Android secure storage | Yes (`MobileTokenAuthenticationTest`, `mobile_session_test.dart`) |

## V10 OAuth and OIDC

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| OAuth / OIDC | ➖ | Not used | n/a |

## V11 Cryptography

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Approved encryption for application secrets | ✅ | Laravel encrypter, AES-256-CBC with HMAC (`config/app.php`) | No |
| Secrets in the database encrypted | ✅ | `mfa_secret`, `mfa_recovery_codes` cast `encrypted` | Yes |
| Randomness from a cryptographically secure generator | ✅ | `random_bytes`, `Str::random` | n/a |
| Keys kept out of the repository | ✅ | `deploy/local/.env` (generated with random secrets by `start.ps1`) and `backend/.env.testing` are git-ignored and untracked; the Android release keystore and `key.properties` are git-ignored | `git ls-files` check |

## V12 Secure Communication

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| TLS for all client traffic | ✅ | Browsers and the mobile app reach the site only through the Cloudflare tunnel (HTTPS at Cloudflare's edge; release mobile builds refuse non-HTTPS). The hops `cloudflared -> waf -> app` are plain HTTP on the private Docker network inside the same PC (an ASVS Level 2 gap, accepted). Nothing but loopback is published on the host | Live check 2026-11-25 over the tunnel URL: certificate verifies, HTTP redirects (301) to HTTPS, `Strict-Transport-Security: max-age=31536000`, session cookie HttpOnly + Secure + SameSite=Lax (`deploy_smoke.py`, 17/17) |
| TLS to the database with certificate validation | ✅ | `deploy/local/docker-compose.yml`: PostgreSQL `ssl=on`, TLS 1.2 minimum; `pg_hba.conf` accepts `hostssl` only; app uses `DB_SSLMODE=verify-full` against a local CA whose key is deleted after signing (`make-db-certs.sh`). Observed: TLSv1.3 / TLS_AES_256_GCM_SHA384; plaintext and wrong-CA connections refused | Yes (`docs/security-testing/evidence/db_checks.txt`) |
| Database not reachable from the internet | ✅ | The `db` service publishes no port; it is reachable only on the private Docker network. (A separate development PostgreSQL installed on Windows was listening on all interfaces, finding V-08; it is now restricted to localhost, see V13) | Yes (`db_checks.txt`: `docker compose ps`) |

## V13 Configuration

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Debug mode off in production | ✅ | `APP_DEBUG=false` in `.env.production.example` | No |
| Dependencies free of known vulnerabilities | ✅ | `composer audit` clean on 2026-11-23 | n/a |
| Dependencies kept current by a routine | 🟡 | Laravel framework and Sanctum updated 2026-11-23. Dependabot and a CI workflow (tests plus weekly `composer audit`) are written in `.github/` but only run once pushed to GitHub | n/a |
| Separate database accounts for runtime and migrations | ✅ | `deploy/local/db-init.sh`: `margin_owner` (owns tables, migrations only), `margin_runtime` (the app: not superuser, `NOBYPASSRLS`, SELECT/INSERT/UPDATE/DELETE via `margin_app`), `postgres` (seeding only). Observed: `margin_runtime` sees 0 submissions without a user context and only the student's own rows with one; FORCE RLS on `submissions`, `grades`, `grade_revisions` | Yes (`db_checks.txt`, `RowLevelSecurityTest`) |
| Web application firewall in front of the app | ✅ | ModSecurity v3 + OWASP CRS 4.30 (`owasp/modsecurity-crs`, paranoia 1, blocking, anomaly threshold 5). Blocks SQL injection, XSS, Log4Shell, traversal and scanner user agents; no false positives on names with apostrophes, brackets or `&` after V-07 | Yes (`deploy_smoke.py`, WSTG WAF checks) |
| Third-party container images pinned | ✅ | `cloudflared:2026.11.1`, `modsecurity-crs:4.30.0-nginx-202611190211`, `alpine/openssl:3.5.9`, `postgres:17-alpine` (major pinned) | n/a |
| No unneeded services exposed on the host | ✅ | The deployment publishes only `127.0.0.1:8088`. The separate Windows development PostgreSQL was listening on `0.0.0.0:5432` (V-08); it now has `listen_addresses = 'localhost'` and listens only on `127.0.0.1` and `::1`. Its `pg_hba.conf` also accepts only local logins | Yes (`Get-NetTCPConnection -LocalPort 5432`, 2026-11-25) |
| Security headers present | ✅ | See V3 | Yes |

## V14 Data Protection

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| API returns only the fields a client needs | ✅ | Resource classes in `app/Http/Resources/Api` | No |
| Sensitive responses not cached | ✅ | `Cache-Control: no-store` | Yes |
| Personal data kept to what the LMS needs | 🟡 | Reviewed informally; no data inventory yet | No |
| Users told how their data is handled (privacy notice) | ✅ | Public `GET /api/v1/privacy-notice`; shown on the login page and in Settings (`PrivacyController`, `privacy_pages.dart`) | Yes (`PrivacyFeaturesTest`, `privacy_dialogs_test.dart`) |
| Users can obtain a copy of their own data | ✅ | `POST /api/v1/profile/export`: password-confirmed, rate-limited, own data only, audited | Yes (`PrivacyFeaturesTest`) |
| Data encrypted at rest in the database | ✅ | The database volume lives in Docker Desktop's WSL2 disk image on drive C:, which is BitLocker-encrypted (checked 2026-11-25: `System.Volume.BitLockerProtection` = 1, on). There is no tablespace or column encryption beyond the secrets in V11 (`mfa_secret`, recovery codes, sessions). Residual risk: full-disk encryption protects a powered-off or stolen disk, not a running PC; anyone with administrator access to the running machine can read the volume | Owner check (Windows volume property) |
| Uploaded files encrypted at rest | ✅ | `app-files` Docker volume on the same BitLocker-encrypted disk; same residual risk as the database | Owner check |

## V15 Secure Coding and Architecture

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Dependency versions pinned | ✅ | `composer.lock`, `pubspec.lock` | n/a |
| Automated tests for security rules | ✅ | 129 backend tests (584 assertions) covering authentication, authorisation, sessions, RLS and the WSTG fixes; run with `php artisan test -c phpunit.margin.xml` | Yes |
| Security testing with findings tracked to a fix and a retest | ✅ | OWASP WSTG run (188 checks: 175 pass, 0 fail, 13 info on retest); 8 findings in `docs/security-testing/vulnerability-log.md`, all fixed and retested (V-01 to V-06 with regression tests) | Yes |
| Documented threat model and risk register | 🟡 | Exists in the report; being re-scored from the vulnerability log and the local-deployment residual risks | n/a |

## V16 Security Logging and Error Handling

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Authentication events logged (success, failure, lockout, MFA) | ✅ | `AuditLogger` calls in `AuthController`, `MfaController` | Yes |
| Authorisation failures logged | ✅ | `AuditAuthorizationFailures` middleware | Yes |
| Role, account and grade changes logged with old and new values | ✅ | `AuditLogger`, `grade_revisions` | Yes |
| Logs do not contain passwords or tokens | ✅ | Login failures record a hash of the identifier, not the password | Yes |
| Logs protected from modification | ✅ | Append-only at the database: trigger blocks UPDATE, DELETE and TRUNCATE for every role; the runtime role has INSERT and SELECT only; expiry runs through `prune_audit_logs()` (migration `2026_11_23_120000`). A database superuser could still disable the trigger on purpose | Yes (`AuditLogProtectionTest`) |
| Logs retained for 12 months, then removed | ✅ | `audit:prune` scheduled daily (`routes/console.php`) | No |
| Errors do not reveal internal details | ✅ | Debug off in production; 404 and 405 responses made generic (tests found they exposed model names and allowed methods); malformed ids and filters now give 404/422 instead of 500 (V-01), anonymous non-JSON requests 401 (V-05); 500s show only "Server Error" | Yes (`ErrorLeakageTest`, `SecurityTestingFixesTest`) |

## V17 WebRTC

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| WebRTC | ➖ | Not used | n/a |

## Gaps to close (updated 2026-11-25)

1. **Turn on the dependency routine** (V13): push `.github/` to GitHub.

Closed on 2026-11-25: live HTTPS check over the tunnel URL (V12), archive inspection for ZIP uploads (V5), and MFA for all roles (V6: TOTP is now available to students, lecturers and teaching assistants as well as administrators, on web and mobile).

### Accepted gaps

- **MFA is optional** (V6, ASVS Level 2 6.3.3): every role can enrol and is then always challenged, but enrolment is not forced, so a user who never enrols signs in with a password only (still protected by the password policy, breached-password check, login throttling and session limits).
- **Plain HTTP inside the Docker network** (V12, Level 2): `cloudflared -> waf -> app` on the same PC.
