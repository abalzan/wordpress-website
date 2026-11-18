# Stage 2 — Local development database identity proof

**Date:** 2026-11-17
**Target:** local development database only (disposable test schema `conexao_stage05_test_5bd9ece5c032` explicitly NOT the target).

## 4.1 Local Compose database service

Discovered from `compose.yaml` (this repository) and `docker compose ps`:

| Fact | Value |
|---|---|
| Compose project | `wordpress-website` |
| Compose config file | `/home/andrei/IdeaProjects/wordpress-website/compose.yaml` |
| DB service | `db` (image `mysql:latest`) |
| DB container | `wordpress-website-db-1` |
| Container ID | `831e69426461dbcb9ebb58a233ed15cebe44f65551986b77fac41912be76b57b` |
| Compose project label | `com.docker.compose.project=wordpress-website` |
| Compose network | `wordpress-website_default` |
| DB container status | `Up 2 days (healthy)` |
| WordPress container | `wordpress-website-wordpress-1` |
| WordPress DB host env | `WORDPRESS_DB_HOST=db:3306` |

The DB container's Compose labels confirm it belongs to this repository's local Compose project (`config_files` points at this repo's `compose.yaml`; `working_dir` is this repo). The WordPress container is on the same `wordpress-website_default` network.

## 4.2 WordPress runtime connection identity

Queried through the local WordPress runtime (`wp-load.php` + `$wpdb`) inside the `wordpress` container:

| Query / property | Value |
|---|---|
| `$wpdb->dbname` | `wordpress` |
| `$wpdb->dbhost` | `db:3306` |
| `SELECT DATABASE()` | `wordpress` |
| `SELECT CURRENT_USER()` | `wordpress@%` |
| `SELECT @@hostname` | `831e69426461` |
| `SELECT @@port` | `3306` |

`@@hostname` (`831e69426461`) matches the first 12 characters of the DB container ID (`831e69426461dbcb...`), proving the WordPress runtime talks to the MySQL server inside this repository's local DB container — not a remote host, not the disposable test schema.

No credentials, connection strings, tokens, or secrets were disclosed (only non-secret identity properties).

## Hard-stop preconditions (§4.3)

- Local database proven? **YES** — `wordpress` DB on `db:3306` inside `wordpress-website-db-1`, Compose project `wordpress-website`.
- Not the disposable test schema? **YES** — test schema is `conexao_stage05_test_5bd9ece5c032`; target is `wordpress`.
- Not remote/shared? **YES** — local Compose container.
- Identity consistent? **YES** — `$wpdb` and raw `mysqli` agree; `@@hostname` matches DB container ID.

**Precondition satisfied. Proceeding to inventory.**
