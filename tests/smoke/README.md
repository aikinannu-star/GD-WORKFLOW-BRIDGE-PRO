Smoke tests

1) Stripe webhook smoke test (PHP CLI)

   php stripe_webhook_test.php <wp-rest-webhook-url> <signing-secret>

   Example:

   php stripe_webhook_test.php http://localhost:8000/wp-json/gdwb/v1/stripe-webhook whsec_test_secret

2) License server smoke script (bash)

   ./license_server_smoke.sh http://127.0.0.1:8001

These scripts are minimal helpers to exercise the webhook verification and license-server endpoints during local development.
