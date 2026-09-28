# Isolated test database

The feature suite uses Laravel `RefreshDatabase`. Never point it at a developer, staging, or production tenant database.

1. Create a dedicated MySQL schema and user with permission only on that schema.
2. Copy `.env.testing.example` to `.env.testing`.
3. Replace the test-only key and database credentials.
4. Run the focused test or full suite:

```bash
cp .env.testing.example .env.testing
bin/test-erp --filter=MaterialIssueIntegrationTest
bin/test-erp
```

`bin/test-erp` refuses to run when `.env.testing` is missing or names the same database as `.env`.

The test environment uses array cache/session drivers and synchronous queues so tests cannot enqueue work into a shared production worker. Remove `.env.testing` from source control and rotate the test credentials when they are no longer needed.
