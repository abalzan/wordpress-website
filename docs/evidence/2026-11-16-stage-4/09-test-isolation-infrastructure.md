# Stage 4 — Test-isolation infrastructure

**Date:** 2026-11-16

## 1. Account and host

- **Account:** `conexao_test`
- **Host:** `172.18.%`
- **Why this host:** The test client (the `wordpress` container) runs on the
  `wordpress-website_default` Docker bridge network (172.18.0.0/16). A
  network-scoped host (`172.18.%`) is narrower than `%` and stable across
  container restarts (the Docker network subnet is consistent). The host `%`
  was not necessary.
- **Residual connection-exposure risk:** Any process on the 172.18.0.0/16
  Docker network can attempt to authenticate as `conexao_test`. The
  schema-level grant still restricts the account to the disposable test
  schema, so a successful connection cannot reach the shared `wordpress`
  schema.

## 2. Schema-level privileges

The explicit privilege set granted on
`conexao_stage05_test_5bd9ece5c032` only:

```
GRANT USAGE ON *.* TO `conexao_test`@`172.18.%`
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER,
  CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW,
  CREATE ROUTINE, ALTER ROUTINE, TRIGGER
  ON `conexao_stage05_test_5bd9ece5c032`.* TO `conexao_test`@`172.18.%`
```

`USAGE ON *.*` is the implicit privilege MySQL grants for connection
establishment; it confers no schema access. No `WITH GRANT OPTION` was
granted. No privileges were granted on `wordpress.*`, any other schema, or
globally.

## 3. Environment configuration

- **`.env`** (git-ignored, untracked, mode 0600): the two test variables
  `CONEXAO_TEST_DB_USER` and `CONEXAO_TEST_DB_PASSWORD` were appended. The
  file already existed and was updated (not created new). No existing entries
  were modified or removed.
- **`.env.example`**: empty placeholders for the two variables were added
  with a comment noting their purpose (local WordPress/DB-dependent tests
  only).
- **Password storage:** the password is stored only in the protected,
  Git-ignored `.env`. It must never be copied into reports, transcripts,
  chat, or command arguments.

## 4. Bootstrap/runner changes

### `tests/lib/isolated-db.php`

The raw preflight connection now reads `CONEXAO_TEST_DB_USER` and
`CONEXAO_TEST_DB_PASSWORD` instead of `WORDPRESS_DB_USER` /
`WORDPRESS_DB_PASSWORD`. If either variable is missing or empty, the
preflight fails closed with a clear error before attempting any database
connection:

```
conexao-test-bootstrap: CONEXAO_TEST_DB_USER is required for the isolated test connection.
```

It never silently falls back to `WORDPRESS_DB_USER` / `WORDPRESS_DB_PASSWORD`
for the test connection.

### `scripts/run-tests.sh`

- `.env` is loaded (via `set -a` / `. "$REPO_ROOT/.env"` / `set +a`) so the
  two test variables reach the runner environment without appearing on a
  command line or in a log.
- The three `docker compose exec` invocations (preflight, `run_php`, and the
  in-container variants) pass `CONEXAO_TEST_DB_USER` and
  `CONEXAO_TEST_DB_PASSWORD` to the container via `-e`.
- The normal WordPress stack's `wp-config.php`, `compose.yaml` and
  `WORDPRESS_DB_*` configuration are unchanged. Normal application runtime
  continues to use its existing configuration.

### Fail-closed preflight

With both test variables unset, the bootstrap preflight exits non-zero before
any database connection:

```
conexao-test-bootstrap: CONEXAO_TEST_DB_USER is required for the isolated test connection.
exit code: 1
```

### Actual PHP connection identity

The actual PHP test connection (mysqli, from the `wordpress` container) was
verified:

```
USER()        = conexao_test@172.18.0.3
CURRENT_USER() = conexao_test@172.18.%
DATABASE()    = conexao_stage05_test_5bd9ece5c032
```

No test-time connection to the shared `wordpress` schema was opened.

## 5. Empirical isolation checks

All checks were run from a query container on the `wordpress-website_default`
Docker network (172.18.0.0/16) using the `conexao_test` account only.

| Check | Result |
|---|---|
| 7.1 Connection identity | `USER()` = `conexao_test@172.18.0.4`, `CURRENT_USER()` = `conexao_test@172.18.%`, `DATABASE()` = `conexao_stage05_test_5bd9ece5c032` |
| 7.2 Positive read (test schema) | `SELECT COUNT(*) FROM wp_posts` → 8749 (succeeded) |
| 7.3 Negative read (shared schema) | `ERROR 1142 (42000): SELECT command denied to user 'conexao_test'@'172.18.0.4' for table 'wp_posts'` (permission error) |
| 7.4 Negative write (shared schema) | `ERROR 1142 (42000): UPDATE command denied to user 'conexao_test'@'172.18.0.4' for table 'wp_posts'` (permission error) |
| 7.5 Positive DDL probe (test schema) | `CREATE TABLE zz_isolation_probe_<random>` and `DROP TABLE` both succeeded; leftover count = 0 |

Both negative checks produced permission errors (MySQL 1142), which is the
primary isolation evidence. No structural errors ("unknown table" / "unknown
column") were encountered.

## 6. Operator-log isolation gate

The Stage 0.5 operator-log isolation gate (`test-operator-log-isolation.php`)
ran under the restricted credential and passed: 366 passed, 0 failed. The gate
reads `{$wpdb->options}` (the test schema) to verify live operator options
stay byte-identical; it does not read the shared `wordpress` schema, so it is
compatible with the restricted credential.

**Operator-log isolation verification: PASSED.**

## 7. CI provisioning dependency

The CI integration job (`.github/workflows/ci.yml`, line 640) runs the PHP
suites with `CONEXAO_TEST_DB_NAME=wordpress` and the `wordpress` DB
credentials. The fail-closed bootstrap change requires
`CONEXAO_TEST_DB_USER` and `CONEXAO_TEST_DB_PASSWORD` to be set; CI does not
currently provision them. CI test runs will fail until the CI environment is
updated to provision the restricted test credential. CI update is out of
scope for this task and should be tracked as a follow-up.

## 8. Operational notes

- Every future WordPress/DB-dependent test must use this schema-restricted
  account and must not use the over-privileged `wordpress` account for test
  execution.
- To rotate the password locally, update `CONEXAO_TEST_DB_PASSWORD` in `.env`
  and run `ALTER USER 'conexao_test'@'172.18.%' IDENTIFIED BY '<new-password>'`
  as root. No bootstrap code change is needed.
- The password is stored only in the protected, Git-ignored local
  configuration and must never be copied into reports, transcripts, chat, or
  command arguments.
---

## 9. Closeout remediation (2026-11-16)

### 9.1 Grant remediation

**Prior claim (superseded):** The account had excess privileges (EXECUTE,
CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, TRIGGER) on the test
schema.

**Action:** Revoked only the known excess privileges:

```sql
REVOKE EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE,
       ALTER ROUTINE, TRIGGER
ON `conexao_stage05_test_5bd9ece5c032`.*
FROM 'conexao_test'@'172.18.%';
```

**After `SHOW GRANTS`:**

```
GRANT USAGE ON *.* TO `conexao_test`@`172.18.%`
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER,
  CREATE TEMPORARY TABLES, LOCK TABLES
  ON `conexao_stage05_test_5bd9ece5c032`.* TO `conexao_test`@`172.18.%`
```

The effective grants are now exactly the eleven authorised privileges plus
implicit `USAGE`. No other account or schema was modified.

**Grant compliance: PASSED.**

### 9.2 Password rotation and secure storage

**Prior claim (superseded):** The password was passed through command-line
arguments in Docker/MySQL invocations.

**Action:**

1. Generated a new cryptographically random password (32 random bytes, hex
   encoded, 64 characters).
2. Rotated `conexao_test`@`172.18.%` via `ALTER USER` using a protected
   temporary SQL file (mode 0600) fed to the MySQL client by file/stdin. No
   password appeared in any command argument, Docker `-e` value, or log.
3. Stored the new credentials in `.conexao-test.env` (mode 0600) containing
   only `CONEXAO_TEST_DB_USER` and `CONEXAO_TEST_DB_PASSWORD`.
4. Added `.conexao-test.env` to `.gitignore`.
5. Removed `CONEXAO_TEST_DB_USER` and `CONEXAO_TEST_DB_PASSWORD` from `.env`
   (the non-secret `CONEXAO_TEST_DB_NAME` remains in `.env`).
6. Updated `.env.example` to document that actual test credentials live in
   `.conexao-test.env`.

**Password handling: PASSED.**

### 9.3 `wp-config.php` environment-overridability

**Result: CONFIRMED — WORDPRESS_DB_* ENV-READ.**

`wp-config.php` resolves `DB_NAME`, `DB_USER`, `DB_PASSWORD` and `DB_HOST`
from the `WORDPRESS_DB_*` environment variables via the `getenv_docker()`
helper:

```php
define( 'DB_NAME', getenv_docker('WORDPRESS_DB_NAME', 'wordpress') );
define( 'DB_USER', getenv_docker('WORDPRESS_DB_USER', 'example username') );
define( 'DB_PASSWORD', getenv_docker('WORDPRESS_DB_PASSWORD', 'example password') );
define( 'DB_HOST', getenv_docker('WORDPRESS_DB_HOST', 'mysql') );
```

No constants are hardcoded. `wp-config.php` was not modified.

### 9.4 Pre-WordPress environment setup

**New file:** `scripts/conexao-test-exec.sh` — a container-side wrapper that:

1. Sources only the dedicated `.conexao-test.env` (never the full `.env`).
2. Validates `CONEXAO_TEST_DB_USER` is exactly `conexao_test` and
   `CONEXAO_TEST_DB_NAME` is a valid database identifier.
3. Exports `WORDPRESS_DB_USER`, `WORDPRESS_DB_PASSWORD`, `WORDPRESS_DB_NAME`
   and `CONEXAO_TEST_DB_NAME` for the child PHP process.
4. `exec`s the requested PHP command so the restricted environment is in
   place before `wp-config.php` runs.

**`scripts/run-tests.sh` changes:**

- The host-side runner copies `.conexao-test.env` into the container at
  `/var/www/html/scripts/.conexao-test.env` (mode 0600) via
  `docker compose cp` (file contents, not a command-line secret value).
- The runner invokes the wrapper via `docker compose exec` with only the
  secret-file path, the wrapper path and the suite path in argv — never the
  password value.
- No `docker compose exec -e NAME=value` secret forwarding remains.
- The container-side secret file is removed on exit via a trap.
- Shell tracing is disabled in the wrapper from before the secret file is
  opened until the child exits.

**Worked example of the host-side command line:**

```
docker compose exec -T \
  -e CONEXAO_TEST_DB_ENV_FILE=/var/www/html/scripts/.conexao-test.env \
  -e CONEXAO_TEST_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
  -e CONEXAO_TEST_DB_USER=conexao_test \
  -e CONEXAO_TEST_SHARED_DB_NAME=wordpress \
  -e GITHUB_ACTIONS=false \
  wordpress bash /var/www/html/scripts/conexao-test-exec.sh \
    php /var/www/html/tests/lib/isolated-db.php
```

The command line contains the secret-file path and the wrapper invocation,
but not the password value. No new password-exposure path was introduced.

### 9.5 Actual `$wpdb` identity guard

**New guard in `tests/bootstrap.php`:** After WordPress loads and the
post-bootstrap DB name check passes, a fail-closed identity guard queries
`USER()`, `CURRENT_USER()` and `DATABASE()` through the actual `$wpdb`
instance:

```php
$row = $wpdb->get_row(
    'SELECT USER() AS login_user, CURRENT_USER() AS matched_account, DATABASE() AS selected_database',
    ARRAY_A
);
```

The guard verifies:

- `CURRENT_USER()` resolves to `conexao_test` (with or without host suffix);
- `DATABASE()` is the disposable test schema;
- the query ran through the same `$wpdb` object the suite uses.

If the identity differs, the suite terminates immediately. No reconnect, no
silent substitution.

**Verified identity for every required suite:**

```
WPDB_IDENTITY=PASS login_user=conexao_test@172.18.0.3 matched_account=conexao_test@172.18.% selected_database=conexao_stage05_test_5bd9ece5c032
```

**Actual `$wpdb` isolation: PASSED.**

### 9.6 Empirical isolation proof

All checks ran through the actual `$wpdb` connection (not a separate
mysqli/PDO connection):

| Check | Result |
|---|---|
| Positive read (disposable schema) | PASS |
| Negative read (`wordpress.wp_posts`) | DENIED (SELECT command denied) |
| Negative write (`wordpress.wp_posts`) | DENIED (UPDATE command denied) |
| DDL probe (CREATE/INSERT/UPDATE/DELETE/DROP) | PASS |
| Probe table cleanup | PASS (no leftover table) |

**Isolation proof: PASSED (9/9 checks).**

### 9.7 Fail-closed verification

With the secret file absent, the wrapper exits before launching PHP:

```
conexao-test-exec: secret file not found: /var/www/html/scripts/.conexao-test.env
exit: 1
```

No database connection is opened.

**Fail-closed: PASSED.**

### 9.8 Operator-log isolation gate

**Case B applies:** `test-operator-log-isolation.php` only inspects the test
schema's `wp_options` (via `$wpdb->options`), not the shared `wordpress`
schema. It does not open a second connection or query `wordpress.wp_options`.

**Result:** 376 passed, 0 failed.

**Operator-log isolation verification: PASSED.**

### 9.9 CI isolation status

The CI workflow still runs WordPress/DB tests with the over-privileged
`wordpress` credential. Fixing CI provisioning is out of scope for this task.
CI test runs do not qualify as isolation-compliant until the CI follow-up
lands. This is a separate known limitation.

### 9.10 Gate summary

| Gate | Status |
|---|---|
| Grant compliance | PASSED |
| Password handling | PASSED |
| `wp-config.php` environment-overridability | CONFIRMED — WORDPRESS_DB_* ENV-READ |
| Actual `$wpdb` isolation | PASSED |
| Operator-log isolation verification | PASSED |
| Fail-closed behaviour | PASSED |
| Overall test/safety gate | PASSED |

---

## 10. Pre-finalisation verification (2026-11-16)

### 10.1 Repository and file inspection

- Branch: `event-import-improvements`.
- HEAD: `7f6850f` (`Add Stage 3.6 harness for Eventbrite discovery and enumeration`).
- `docs/evidence/2026-11-15-stage-3-6/`: no changes reported by
  `git status --short -- docs/evidence/2026-11-15-stage-3-6/`.
- `.conexao-test.env`: exists, mode `0600`, and is git-ignored. Its contents
  were not displayed or inspected.
- `scripts/conexao-test-exec.sh`: exists and is executable.
- `tests/bootstrap.php`: contains the fail-closed actual-`$wpdb` identity guard.
- The protected container copy was absent after the controlled runs.

### 10.2 Grant inspection

`SHOW GRANTS FOR 'conexao_test'@'172.18.%'` was run through the existing
protected database-container credential mechanism. The fresh result was:

```text
GRANT USAGE ON *.* TO `conexao_test`@`172.18.%`
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES ON `conexao_stage05_test_5bd9ece5c032`.* TO `conexao_test`@`172.18.%`
```

This is exactly the authorised eleven schema privileges plus implicit `USAGE`;
no shared-schema or excess global privilege was observed.

### 10.3 Fresh identity and isolation-proof output

The existing proof was run once through the existing wrapper and actual
WordPress `$wpdb` connection:

```text
ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db
WPDB_IDENTITY=PASS login_user=conexao_test@172.18.0.3 matched_account=conexao_test@172.18.% selected_database=conexao_stage05_test_5bd9ece5c032
ISOLATION_PROBE=PASS positive_read_disposable_schema
ISOLATION_PROBE=PASS negative_read_shared_schema_denied
ISOLATION_PROBE=PASS negative_write_shared_schema_denied
ISOLATION_PROBE=PASS ddl_create_probe_table
ISOLATION_PROBE=PASS dml_insert_probe_row
ISOLATION_PROBE=PASS dml_update_probe_row
ISOLATION_PROBE=PASS dml_delete_probe_row
ISOLATION_PROBE=PASS ddl_drop_probe_table
ISOLATION_PROBE=PASS probe_table_cleaned_up
ISOLATION_PROBE_SUMMARY pass=9 fail=0
```

The shared-schema write probe was the existing no-op `UPDATE` with `WHERE ID =
-1`; it was denied. The probe table was created and removed in the disposable
schema, with zero remaining rows in `information_schema`.

For explicit error text, the same existing statement was issued through the
actual `$wpdb` connection (still with `WHERE ID = -1`):

```text
NEGATIVE_WRITE_RESULT=false ERROR=UPDATE command denied to user 'conexao_test'@'172.18.0.3' for table 'wp_posts'
```

The missing-secret fail-closed path was also checked without touching the real
secret file:

```text
conexao-test-exec: secret file not found: /var/www/html/scripts/.definitely-absent-conexao-test.env
exit: 1
```

**Isolation gate: PASSED.**

### 10.4 Credential exposure assessment — owner decision required

- A credential candidate is confirmed in the earlier execution history as
  having appeared in a temporary MySQL defaults-file attempt. The value is not
  repeated here.
- The available evidence does not establish that this candidate matched the
  subsequently rotated credential stored in the active `.conexao-test.env`.
- No direct evidence establishes that the active rotated `.conexao-test.env`
  credential appeared in a transcript, shell command argument, process
  argument, report, or log. **ACTIVE CREDENTIAL EXPOSURE NOT ESTABLISHED.**
- This task does not rotate credentials.
- Owner security review is recommended because a credential candidate appeared
  in prior execution history. Any rotation decision should coordinate every
  other consumer of the account and must not be inferred from this task.

The active secret value, hash, rotation output and secret-bearing command are
not included in this evidence.
