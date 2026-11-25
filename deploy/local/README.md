# Margin LMS on one machine (no domain, no cost)

```
Internet -> Cloudflare quick tunnel (HTTPS) -> ModSecurity WAF (OWASP CRS) -> app (nginx + PHP-FPM + Flutter web) -> PostgreSQL
```

Nothing is published on the host except `127.0.0.1:8088` (for testing on this PC). The tunnel is the only way in from the internet.

## Requirements
Docker Desktop running, and the web build (`start.ps1` makes it for you).

## Commands (PowerShell, from this folder)
| Task | Command |
|---|---|
| Test on this PC only, nothing public | `./start.ps1 -LocalOnly` then open http://localhost:8088 |
| Put it online through the tunnel | `./start.ps1` (prints the https address) |
| After changing Flutter code | `./start.ps1 -RebuildWeb` (add `-LocalOnly` to stay private) |
| Take it offline, keep data | `./stop.ps1` |
| Take it offline, erase data | `./stop.ps1 -DeleteData` |

The tunnel address changes whenever the tunnel container is recreated. `start.ps1` reads the new address, saves it in `.env` and restarts the app with it. Run it again if the address stops working.

## Secrets
`start.ps1` creates `.env` (git-ignored) with random secrets on first run. You add one value by hand:

* `RESEND_API_KEY` - an API key from Resend's free plan (resend.com, no card needed). Sign up with the `MAIL_ALWAYS_TO` inbox: without a domain of its own, Resend only sends from `onboarding@resend.dev` and only to the account's own address, which is fine here because all email goes to `MAIL_ALWAYS_TO` anyway. Without the key, password-reset email is not sent (the user still sees the same neutral reply).

Why not Gmail SMTP: outgoing SMTP ports 465 and 587 are blocked on this PC's network (from Windows as well as from Docker), so mail goes out over HTTPS on port 443 instead. To go back to SMTP where it works, set `MAIL_MAILER=smtp` and `MAIL_PASSWORD` (a Gmail app password) and `MAIL_FROM_ADDRESS` in `.env`.

The demonstration accounts use `MARGIN_SEED_ADMIN_PASSWORD` from `.env`: `student@margin.test`, `lecturer@margin.test`, `university-admin@margin.test`, `admin@margin.test`.

## Database accounts
* `margin_owner` owns the tables and runs migrations only.
* `margin_runtime` is what the app uses. It is not a superuser and not the owner, so Row-Level Security applies to it.
* `postgres` (superuser) is used only to seed the demonstration data.

## Database encryption
* **In transit:** PostgreSQL only accepts TLS connections (`pg_hba.conf` rejects plain ones, TLS 1.2 or newer). The app checks the server certificate and name (`verify-full`) against a local CA made once by `make-db-certs.sh`. The CA key is thrown away after signing. The server key never leaves the database container.
* **At rest:** the data lives in Docker volumes, which Docker Desktop keeps inside its WSL2 disk image (`%LOCALAPPDATA%\Docker\wsl\disk\docker_data.vhdx`) on drive C:. The application does not encrypt it; protection comes from encrypting the disk (BitLocker / Windows device encryption). On this PC, drive C: is BitLocker-encrypted (checked 2026-11-25). To check again without admin rights: `(New-Object -ComObject Shell.Application).NameSpace('C:').Self.ExtendedProperty('System.Volume.BitLockerProtection')` (1 = on). `manage-bde -status C:` also works, but only in a PowerShell started with *Run as administrator*.
* To issue new certificates, run `./stop.ps1 -DeleteData` (this also erases the data) or delete only the `margin_db-certs` and `margin_db-ca` volumes and start again.

## Firewall
ModSecurity with the OWASP Core Rule Set (paranoia level 1, blocking). `ALLOWED_METHODS` lists the HTTP methods the API uses. The CRS default (GET/HEAD/POST/OPTIONS) blocked every profile edit, grade and delete (rule 911100). To see why a request was blocked: `docker compose logs waf | Select-String ruleId`.

## Security tests
All in `../../docs/security-testing/` (results and the vulnerability log are there too).

| Test | Command (from this folder) |
|---|---|
| Deployed path through the firewall (login, cookies, upload + virus scan, WAF, reset) | `python ../../docs/security-testing/deploy_smoke.py` |
| Full OWASP WSTG run | `docker compose -f docker-compose.yml -f docker-compose.test.yml up -d app`, then `python ../../docs/security-testing/run_wstg.py` |
| Backend regression suite | in `backend/`: `php artisan test -c phpunit.margin.xml` |

The test override publishes the app alone on `127.0.0.1:8089` so the tests can show that the application defends itself without the firewall. Never use it for a public run. `./start.ps1` recreates the app without it.

## Mobile app (release APK)
The quick tunnel's address changes whenever the tunnel container starts, and the APK has the address compiled in. For a demonstration:
1. `./start.ps1` (public), then **leave the tunnel running**.
2. In `frontend/`: `./scripts/build_android.ps1`. It reads `TUNNEL_HOST` from `.env` and builds `build/app/outputs/flutter-apk/app-release.apk` against `https://<tunnel>`.
3. Install it on the phone or emulator. If the tunnel restarts, build again.

Release signing: put `upload-keystore.jks` and `key.properties` (`storePassword`, `keyPassword`, `keyAlias`, and `storeFile=../upload-keystore.jks`, a path relative to `android/app`) in `frontend/android/`. Both are git-ignored. Without them, the APK is signed with the debug key (fine for testing only).

## Known limits
* Online only while this PC, Docker and the tunnel are running. The tunnel address changes on restart.
* Inside the PC, `cloudflared -> waf -> app` is plain HTTP on the private Docker network. TLS is between the visitor and Cloudflare, and between the app and the database.
* Disk encryption of the data volumes depends on the PC (see above).
* A separate PostgreSQL installed directly on Windows (for development) is not part of this deployment. It is set to `listen_addresses = 'localhost'` (since 2026-11-25), so it is not reachable from the network.
