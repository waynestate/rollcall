# Rollcall's contract

The shapes Rollcall promises, as JSON Schema (draft 2020-12). `tests/ContractTest.php` runs the real commands on
the fixtures and validates what they print and store against these files, so the code and the contract cannot
drift apart. If you read Rollcall's output (a dashboard, a Grafana import, ADVISOR, a script), read it against
these schemas, not against an example you once saw.

| File | What it describes | Who produces it |
|---|---|---|
| `report.schema.json` | `rollcall report --format=json` and `GET /report.json`, public and internal (`--internal`) views | Rollcall |
| `snapshot.schema.json` | One stored scan, `<ROLLCALL_STORE_DIR>/<date>.json` (internal data: it holds app ids) | Rollcall |
| `images-export.schema.json` | The image list behind `ROLLCALL_IMAGES_FILE` | Any platform: Citadel, OpenShift, Coolify or a hand-kept file |

## Rules

- **Closed objects.** Rollcall's own output (report, snapshot) uses `additionalProperties: false` throughout, so a
  new field is a contract change, made in the same commit as the code. Inputs (the image list) stay open: a
  producer may add fields of its own, and Rollcall ignores them.
- **Public means counts.** The public report may never carry `apps` or `scan_errors`; the schema refuses them
  unless `visibility` is `internal`, and no field holds an owner, a rank or a score.
- **Versioning.** From 1.0.0 on, Rollcall follows SemVer against this contract: an added field or enum value is a
  minor release, and a removed or renamed field or a changed type is a major release. The snapshot file carries its
  own `schema_version` (1 today); a reader refuses a version it does not know.
- **Fixtures, not hosts.** The suite never calls GitLab, Citadel or any other host. It reads the fixtures under
  `tests/fixtures/`.

Run it alone with `vendor/bin/phpunit tests/ContractTest.php`; `composer ci` runs it with everything else.
