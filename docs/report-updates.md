# Proposed report edits (2026-11-25)

Text to paste into `Security Report - final edits.docx`. It is based on the WSTG retest, the deployment checks and the ASVS update:
- `docs/security-testing/vulnerability-log.md`: findings V-01 to V-08
- `docs/security-testing/evidence/`: deployment smoke test and database checks
- `docs/ASVS-checklist.md`: 87 met, 8 partly met, 1 waiting on the live tunnel check

BitLocker on drive C: was confirmed on 2026-11-25.

---

## 4.1 Deployment paragraph (replace the current one)

> **Deployment:** The system runs as containers on one machine. A ModSecurity Web Application Firewall with the OWASP Core Rule Set sits in front. Behind it, one container serves the Flutter web application and the Laravel API. PostgreSQL runs in a separate container that publishes no port. The site is published over HTTPS through a free Cloudflare tunnel, which only connects outwards, so no domain name, open inbound port or paid hosting is needed. The application reaches the database over TLS 1.3 and verifies its certificate. The database accepts no unencrypted connection. The data volumes sit on the machine's disk and are protected at rest by BitLocker full-disk encryption of the host drive. Outgoing mail (password resets) is sent over HTTPS through Resend's free plan, because outgoing SMTP ports are blocked on the hosting network. The same containers could move to a commercial cloud provider unchanged.

## 5.1 Network & Hosting row (append to the current text)

> The firewall allows only the HTTP methods the API uses (GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS). Third-party container images are pinned to exact versions. Inside the machine, the tunnel-to-firewall-to-application hops use plain HTTP on a private container network. TLS protects the visitor-to-Cloudflare and application-to-database links.

## 5.4 Security testing (replace "Security testing will combine…")

> Security testing combined automated checks with controlled testing against the running deployment, following the OWASP Web Security Testing Guide. A test harness ran 188 checks across information gathering, configuration, identity, authentication, authorization, sessions, input validation, error handling, business logic, client-side controls and the firewall. Checks ran both through the firewall and against the application alone, to show that the application defends itself without the firewall. A second university was seeded so that cross-tenant access could be tested.
>
> The first run (24 November 2026) recorded 20 failures. Investigation traced them to a handful of real findings; the others were errors in the test checks, which were corrected. Together with issues seen while reviewing the run, eight findings were logged: database type errors surfacing as HTTP 500 for malformed identifiers (V-01); Row-Level Security hiding submissions from their own owner and lecturer, because the database user context was set after route-model binding (V-02); Host-header injection in pagination links (V-03); account enumeration through the password-reset reply when mail delivery failed (V-04); HTTP 500 for anonymous non-JSON requests (V-05); missing rate limits on the session routes (V-06); the firewall blocking every PUT, PATCH and DELETE request by default, whose real cause only showed in the retest (V-07); and a separate development database installed on the host machine listening on all network interfaces, although it accepted only local logins (V-08). Each was fixed: V-01 to V-06 with regression tests, V-07 with a smoke test through the firewall, and V-08 by restricting the database to the local machine.
>
> The retest (25 November 2026) passed 175 of 188 checks with no failures. The remaining 13 are informational notes or accepted design decisions. A deployment smoke test through the firewall (14 checks) confirmed sign-in, secure cookie flags, the virus scan (the EICAR test file is refused), firewall blocking of SQL injection, cross-site scripting and Log4Shell probes, and a neutral password-reset reply. The backend regression suite passes (129 tests). No finding remains open. The full vulnerability log, with evidence, root cause, fix, regression test and retest result for each finding, is given in Appendix **[X]**.

## Risk register: "How to read the register" (replace)

> **How to read the register.** "Before controls" is the score assuming only the baseline design. "Residual" is the score after the controls now implemented, covered by automated tests and confirmed by the OWASP WSTG retest of the running deployment (25 November 2026). Residual scores are judgments based on the design and the test results, not on incident data.

## Risk register: re-scored rows

Only the changed cells are listed. "Before controls" scores are unchanged.

| ID | Residual (was) | Residual (now) | Status (now) | Why |
|---|---|---|---|---|
| R1 Unauthorized grade change | 2×5 = 10 High | **1×5 = 5 Moderate** | Implemented; tested | Two independent layers (policies and Row-Level Security) confirmed by WSTG ATHZ-02/04 and BUSL-03: students cannot grade or publish, lecturers of other universities cannot grade, out-of-range scores are refused. V-02 fixed. |
| R2 Account compromise | 3×5 = 15 High | 3×5 = 15 High | Partly; tested | Lockout, generic errors, timing parity and the breached-password check were confirmed (WSTG ATHN/IDNT). MFA is still not required for students and lecturers (accepted gap). |
| R3 Privilege escalation | 2×5 = 10 High | 2×5 = 10 High | Implemented; tested | Role and mass-assignment checks pass (WSTG ATHZ-02/03). Kept at 2 because role functions are enforced in the application layer only. |
| R4 Cross-university data access | 2×5 = 10 High | **1×5 = 5 Moderate** | Implemented; tested | The cross-tenant IDOR sweep and list/search mixing checks pass. The database refuses cross-tenant rows for the runtime account (evidence: `db_checks.txt`). |
| R5 Insecure file access or upload | 2×4 = 8 Moderate | 2×4 = 8 Moderate | Implemented; verified on the live stack | ClamAV with current signatures refuses EICAR through the firewall. Traversal paths serve no files. Remaining: ZIP contents are not inspected. |
| R6 SQL injection or unsafe input | 1×5 = 5 Moderate | 1×5 = 5 Moderate | Implemented; tested | No injection found (WSTG INPV-05, including time-based payloads). Malformed input now gets 404/422 instead of 500 (V-01). |
| R8 Password-reset abuse | 2×4 = 8 Moderate | 2×4 = 8 Moderate | Implemented; tested | An enumeration flaw (500 reply when mail failed) was found and fixed (V-04). Forged tokens are refused. |
| R9 Database exposure or misconfiguration | 2×5 = 10 High | **1×5 = 5 Moderate** | Implemented; tested | TLS to the database is now in place (verify-full; plaintext and wrong-CA connections refused). The database publishes no port, and separate owner, runtime and seed accounts were verified. The host drive is BitLocker-encrypted. A separate development database on the host was listening on all interfaces (V-08, low); it now listens only on the local machine. |
| R10 Brute force or denial of service | 3×3 = 9 Moderate | 3×3 = 9 Moderate | Partly; tested | Throttling (including the session routes, V-06) and firewall blocking were confirmed. Still one host with no failover. |
| R11 Cross-site scripting | 2×4 = 8 Moderate | **1×4 = 4 Low** | Implemented; tested | Payloads are stored as text and returned only as JSON with `nosniff`. Flutter renders text, not HTML, and the CSP forbids inline scripts. The firewall also blocks common XSS probes. |
| R12 Cross-site request forgery | 1×4 = 4 Low | 1×4 = 4 Low | Implemented; tested | Requests with no token and no browser headers, and cross-site requests, are refused (WSTG SESS-05). CORS grants only the site's own origin. |
| R14 Session or token theft | 2×4 = 8 Moderate | 2×4 = 8 Moderate | Implemented; tested | Cookie flags were observed through the firewall. A replayed cookie from another client is refused. Sessions and bearer tokens die at logout. |
| R16 Information leakage through errors | 1×3 = 3 Low | 1×3 = 3 Low | Implemented; tested | The two sources of HTTP 500 found in testing (V-01, V-05) were fixed. |
| R17 Demonstration hosting | 3×3 = 9 Moderate | 3×3 = 9 Moderate | Accepted for demonstration | Add: the mobile app has the tunnel address built in and is rebuilt for each demonstration session. Internal hops are plain HTTP on the private container network. |

### New rows

| ID | Risk | Before controls | Controls | Residual | Status |
|---|---|---|---|---|---|
| R18 | **Security control blocks legitimate use.** A misconfigured firewall or over-strict rule stops users from editing profiles, grading or deleting content, so the system is unavailable for its purpose. | 4×3 = 12 High | Found in testing (V-07: the CRS default method policy blocked PUT, PATCH and DELETE). The allowed methods now match the API. A smoke test through the firewall checks a write request and a name with punctuation on every run. Firewall paranoia level 1. Blocked requests are logged with the rule ID. | 1×3 = 3 Low | Implemented; tested |
| R19 | **External mail provider.** Password-reset emails pass through a third-party service (Resend). An outage stops resets, and the provider sees the recipient address and the reset link. | 3×3 = 9 Moderate | Mail is sent over HTTPS. Reset links expire after 60 minutes and work once. A failed send gives the same neutral reply (V-04). The demonstration sends only to one owner-controlled inbox, so no real user's address is shared. | 2×3 = 6 Moderate | Accepted for demonstration |

### Matrix (residual positions)

| Impact ↓ / Likelihood → | 1 | 2 | 3 | 4 | 5 |
|---|---|---|---|---|---|
| 5 Severe | 5: R1 R4 R6 R9 | 10: R3 | 15: R2 | 20 | 25 |
| 4 Major | 4: R11 R12 R15 | 8: R5 R8 R13 R14 | 12 | 16 | 20 |
| 3 Moderate | 3: R16 R18 | 6: R19 | 9: R10 R17 | 12 | 15 |
| 2 Minor | 2 | 4 | 6 | 8 | 10 |
| 1 Insignificant | 1 | 2 | 3 | 4 | 5 |

## "Accepted gaps" paragraph (replace)

> **Accepted gaps.** Multi-factor authentication is not required for students and lecturers (R2). Encryption at rest relies on the host's BitLocker full-disk encryption rather than the database itself, so it protects a powered-off or stolen disk but not a running machine (R9). Inside the machine, the tunnel, firewall and application talk over plain HTTP on a private container network (R17). One machine hosts the demonstration, with no failover, and its public address changes on restart (R10, R17). Password-reset mail depends on a free third-party provider (R19). ZIP uploads are not inspected for decompression bombs (R5).

## Before you paste
1. Do the short public tunnel run to tick the last ASVS item (TLS, HSTS and cookies seen in a browser over https).
2. Figure 3 (DFD): add the mail provider (Resend, over HTTPS) as an external entity on the API's outbound side.
