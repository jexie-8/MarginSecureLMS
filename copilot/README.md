# AWS Deployment Preparation

AWS Copilot is configured for four workloads: the public API, a private queue worker, a daily scheduler job, and the Flutter static site. The API addon creates a private KMS-encrypted and versioned S3 bucket whose public access is blocked.

Before any deployment:

1. Region: `eu-central-1` (Frankfurt), already set in the manifest and `.env.production.example`. Domains: choose two related ones; `lms.example.edu` and `api.example.edu` are placeholders until then.
2. Provision private RDS PostgreSQL and require TLS. Create a restricted runtime account and a separate migration account.
   Row-Level Security does not apply to superusers, so the runtime account must not be one. The RLS migration creates a `margin_app` role with the exact privileges the app needs; run `GRANT margin_app TO <runtime_account>;` once after the first migration and make sure the runtime account is not the table owner or an RDS master user.
3. Create the Copilot environment secrets referenced by the manifests. Store the migration credentials separately and use them only for a one-off migration task.
4. Verify an Amazon SES domain with DKIM and move the account out of the SES sandbox.
5. Build Flutter with the production API URL before deploying the `web` workload.
6. Validate the infrastructure with `copilot svc package` and `copilot job package` before deploying anything.

No manifest runs database migrations automatically. This prevents the long-running API and worker tasks from receiving schema-change privileges.

Browser sign-in has two requirements that follow from the security controls:

- The web app and the API must share a registrable domain (for example `lms.example.edu` and `api.example.edu`). Browsers then label requests `same-site`, which the API requires for cookie sessions (`REQUIRE_BROWSER_CONTEXT`), and the session cookie works.
- Build the web app with `frontend/scripts/build_web.ps1 -ApiUrl https://api.example.edu`, not a plain `flutter build web`. The script sets the HTTPS API address, points the Content-Security-Policy at it, and checks the bundle for leaks.
