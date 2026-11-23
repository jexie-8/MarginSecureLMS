# Margin LMS: OWASP ASVS Mapping Checklist

**Reference standard:** OWASP Application Security Verification Standard (ASVS) 5.0, organised by its chapters.
**Target level:** Level 1 for every chapter, with Level 2 items noted where the app already meets them.
**Date of assessment:** 2026-11-23, against the code in this repository (not a deployed environment).

This checklist is organised by ASVS chapter rather than by individual requirement number, so each row can be checked against the official document and the evidence file. It records what the code does today, including gaps. It is a design and code assessment, not a certification: the "Verified by test" column says whether an automated test covers the control.

**Status key:** ✅ Met · 🟡 Partly met · ❌ Not met · ➖ Not applicable · ⏳ Depends on deployment or on the WSTG testing phase

## Summary

| Status | Count |
|---|---|
| ✅ Met | 76 |
| 🟡 Partly met | 8 |
| ❌ Not met | 3 |
| ⏳ Depends on deployment | 5 |
| ➖ Not applicable | 3 |

## V1 Encoding and Sanitization

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Database queries use parameter binding (no string-built SQL) | ✅ | Eloquent and query builder with bindings throughout `app/`; the only raw SQL uses bound parameters (`SetDatabaseUserContext`, `GradeService`) | Partly (auth and workflow tests) |
| API responses are JSON, not HTML, so there is no server-side HTML injection surface | ✅ | `app/Http/Resources/Api/*`; `shouldRenderJsonWhen` in `bootstrap/app.php` | No |
| Frontend shows user text as plain text, never as HTML | ✅ | Flutter text widgets in `frontend/lib` (no HTML renderer used) | No |
| Stored free text (announcements, titles, feedback) is normalised or stripped | ✅ | `SanitizeInput` middleware: control characters removed everywhere; markup and invisible/direction-changing characters removed from names, titles and codes; long text keeps legitimate `<` characters; frontend `input_cleaner.dart` tidies text before sending | Yes (`InputSanitizationTest`, `input_cleaner_test.dart`) |
| CSV export neutralises spreadsheet formulas | ✅ | `csvCell()` in `LearningToolsController` prefixes `= + - @` values | ⏳ WSTG phase |
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
| HSTS | 🟡 | Sent by the API over HTTPS; the static frontend host must also send it after deployment | ⏳ Deployment |
| CORS limited to the known frontend origin | ✅ | `config/cors.php` reads an allow-list from `CORS_ALLOWED_ORIGINS` | No |
| No secrets, keys or personal data in the frontend build | ✅ | `frontend/scripts/build_web.ps1` builds a release bundle (HTTPS API required, no source maps) and fails if it finds development addresses, keys or tokens; passes on the current build | Script check |
| Client-side storage holds no credentials | ✅ | Web client relies on HTTP-only cookies; nothing written to local storage | No |

## V4 API and Web Service

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| All non-public API routes require authentication | ✅ | `auth:web` plus `EnsureAccountIsActive` in `routes/lms_api.php` | Yes |
| Public routes limited to login, MFA challenge and password reset | ✅ | `routes/margin_auth.php`, `routes/lms_api.php` | Yes |
| Only intended HTTP methods are routed | ✅ | `apiResource(...)->only([...])` and explicit routes | No |
| CSRF protection on state-changing requests | ✅ | Sanctum CSRF cookie; Flutter client sends `X-XSRF-TOKEN` (`api_client.dart`) | Yes |
| API responses are not cached | ✅ | `Cache-Control: no-store, private` for `api/*` | Yes |
| API versioned | ✅ | `/api/v1` prefix | No |

## V5 File Handling

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Upload size limit | ✅ | `max:25600` (25 MB) in `LearningToolsController` | No |
| File type allow-list based on detected content type | ✅ | `FileSecurityScanner::ALLOWED` checks `getMimeType()` | Yes |
| Antivirus scan before storing | 🟡 | ClamAV hook implemented and required in the production config (`FILE_SCAN_REQUIRED=true`), but not exercised end to end | ⏳ Deployment |
| Stored with generated names, outside any public web root | ✅ | `$file->store()` on the private disk | Yes |
| Download requires an authorisation check | ✅ | `downloadMaterial`, `submissionFile`, `SubmissionPolicy` | Yes |
| Archive files cannot cause resource exhaustion | 🟡 | `application/zip` is allowed; contents are not inspected for decompression bombs | ⏳ WSTG phase |
| Files live in the S3 bucket in production | ✅ | All uploads and downloads go through `PrivateFiles`, which uses the configured private disk (`FILESYSTEM_DISK=s3` in production), refuses any public disk, and streams downloads after the permission check. Tested with a stand-in disk; confirm against the real bucket after deployment | Yes (`PrivateFileStorageTest`) |

## V6 Authentication

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Minimum password length of 12 | ✅ | `Password::min(12)` in `PasswordController`, `PasswordResetController` | Yes |
| Password complexity rules | ✅ | `mixedCase()->numbers()->symbols()` | Yes |
| Check against known breached passwords | ❌ | Not used (`Password::uncompromised()` would add it) | No |
| Passwords hashed with an adaptive algorithm | ✅ | bcrypt, 12 rounds; `password` cast `hashed` in `User` | Yes |
| Login throttling and temporary lockout | ✅ | 5 attempts per minute per email and IP (`AuthController`) | Yes |
| Generic error for wrong credentials or disabled account | ✅ | "Invalid credentials or account unavailable." | Yes |
| Password change requires the current password | ✅ | `current_password:web` rule | Yes |
| Password reset by time-limited emailed link | ✅ | Laravel broker, 60-minute expiry (`config/auth.php`); same response whether or not the email exists | Yes |
| Password reset ends existing sessions | ✅ | Fixed 2026-11-23 in `PasswordResetController` | Yes (`PasswordResetSessionRevocationTest`) |
| Multi-factor authentication available | 🟡 | TOTP for admin roles only, optional to enrol; recovery codes stored hashed. Students and lecturers have none | Yes |
| A used TOTP code cannot be replayed within its time window | ❌ | Not tracked | No |
| No default or demo accounts in production | ✅ | `DatabaseSeeder` throws in production | Yes |

## V7 Session Management

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Session identifiers are long and random | ✅ | Laravel `Str::random(40)` from `random_bytes` | n/a |
| New session identifier after login | ✅ | `session()->regenerate()` in `AuthController`, `MfaController` | Yes |
| Logout invalidates the session server-side | ✅ | `session()->invalidate()` | Yes |
| Cookie flags: Secure, HttpOnly, SameSite | ✅ | Production env sets `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE=lax` | ⏳ Deployment |
| Session data encrypted at rest | ✅ | `SESSION_ENCRYPT=true` | No |
| Idle timeout | ✅ | `SESSION_LIFETIME=120` minutes | No |
| Absolute session timeout | ❌ | Sessions renew while active, with no maximum lifetime | No |
| User can list and revoke active sessions | ✅ | `SessionManagementController` (revocation needs the password) | Yes |
| Sessions ended on password change and on account deactivation | ✅ | `PasswordController`, `EnsureAccountIsActive` | Yes |

## V8 Authorization

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Access decisions made on the server, never from client data | ✅ | Policies in `app/Policies/*` | Yes |
| Deny by default | ✅ | Policies return false unless a rule matches; RLS returns nothing without a user context | Yes |
| Object-level checks (IDOR) on every resource | ✅ | `Gate::authorize` in controllers (36 call sites) plus RLS on grades and submissions | Yes; full sweep ⏳ WSTG phase |
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
| Keys kept out of the repository | 🟡 | Production keys come from environment secrets. A local development database password is committed in `backend/.env.testing`; replace it with an example file | No |

## V12 Secure Communication

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| TLS for all client traffic | ⏳ | HSTS and secure cookies configured; TLS terminates at the load balancer after deployment | ⏳ Deployment |
| TLS to the database with certificate validation | ✅ | `DB_SSLMODE=verify-full` in `copilot/api/manifest.yml` | ⏳ Deployment |
| Database not reachable from the internet | ⏳ | Planned private RDS | ⏳ Deployment |

## V13 Configuration

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Debug mode off in production | ✅ | `APP_DEBUG=false` in `.env.production.example` | No |
| Dependencies free of known vulnerabilities | ✅ | `composer audit` clean on 2026-11-23 | n/a |
| Dependencies kept current by a routine | 🟡 | Laravel framework and Sanctum updated 2026-11-23. Dependabot and a CI workflow (tests plus weekly `composer audit`) are written in `.github/` but only run once pushed to GitHub | n/a |
| Separate database accounts for runtime and migrations | ⏳ | Documented in `copilot/README.md` | ⏳ Deployment |
| Security headers present | ✅ | See V3 | Yes |

## V14 Data Protection

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| API returns only the fields a client needs | ✅ | Resource classes in `app/Http/Resources/Api` | No |
| Sensitive responses not cached | ✅ | `Cache-Control: no-store` | Yes |
| Personal data kept to what the LMS needs | 🟡 | Reviewed informally; no data inventory yet | No |
| Users told how their data is handled (privacy notice) | ✅ | Public `GET /api/v1/privacy-notice`; shown on the login page and in Settings (`PrivacyController`, `privacy_pages.dart`) | Yes (`PrivacyFeaturesTest`, `privacy_dialogs_test.dart`) |
| Users can obtain a copy of their own data | ✅ | `POST /api/v1/profile/export`: password-confirmed, rate-limited, own data only, audited | Yes (`PrivacyFeaturesTest`) |
| Data encrypted at rest in the database | ⏳ | Planned: RDS encryption with KMS | ⏳ Deployment |
| Uploaded files encrypted at rest | ⏳ | S3 KMS bucket defined in `copilot/api/addons/private-files.yml`, but see the V5 gap | ⏳ Deployment |

## V15 Secure Coding and Architecture

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Dependency versions pinned | ✅ | `composer.lock`, `pubspec.lock` | n/a |
| Automated tests for security rules | ✅ | 54 backend tests covering authentication, authorisation, sessions and RLS | Yes |
| Documented threat model and risk register | 🟡 | Exists in the report; being updated | n/a |

## V16 Security Logging and Error Handling

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| Authentication events logged (success, failure, lockout, MFA) | ✅ | `AuditLogger` calls in `AuthController`, `MfaController` | Yes |
| Authorisation failures logged | ✅ | `AuditAuthorizationFailures` middleware | Yes |
| Role, account and grade changes logged with old and new values | ✅ | `AuditLogger`, `grade_revisions` | Yes |
| Logs do not contain passwords or tokens | ✅ | Login failures record a hash of the identifier, not the password | Yes |
| Logs protected from modification | ✅ | Append-only at the database: trigger blocks UPDATE, DELETE and TRUNCATE for every role; the runtime role has INSERT and SELECT only; expiry runs through `prune_audit_logs()` (migration `2026_11_23_120000`). A database superuser could still disable the trigger on purpose | Yes (`AuditLogProtectionTest`) |
| Logs retained for 12 months, then removed | ✅ | `audit:prune` scheduled daily (`routes/console.php`) | No |
| Errors do not reveal internal details | ✅ | Debug off in production; 404 and 405 responses made generic (tests found they exposed model names and allowed methods); 500s show only "Server Error" | Yes (`ErrorLeakageTest`) |

## V17 WebRTC

| Control | Status | Evidence | Verified by test |
|---|---|---|---|
| WebRTC | ➖ | Not used | n/a |

## Gaps to close (from this assessment)

1. **Absolute session timeout** (V7).
2. **TOTP replay protection** (V6) and wider MFA coverage.
3. **Breached-password check** (V6).
4. **Turn on the dependency routine** (V13): push `.github/` to GitHub.
5. **Replace the committed `.env.testing` database password** with an example file (V11).
