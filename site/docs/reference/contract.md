---
title: "Contract | Rollcall docs"
description: "The JSON Schemas Rollcall's report, snapshots and inputs must match, and the suite that checks real output against them."
url: https://rollcall.apps.wayne.edu/docs/reference/contract.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Contract[#](https://rollcall.apps.wayne.edu/docs/reference/contract.html#contract)

This page covers the shapes Rollcall promises to anyone who reads its output. Next: the [Proof](https://rollcall.apps.wayne.edu/docs/reference/proof.html) of a real run.

The contract lives in [`contract/`](https://github.com/waynestate/rollcall/tree/main/contract) as JSON Schema (draft 2020-12). `tests/ContractTest.php` runs the real commands on the fixtures, then validates what they print and store against these files, so the code and the contract cannot drift apart. A dashboard, a Grafana import, ADVISOR or a script should read Rollcall against these schemas, not against an example it once saw.

| Schema | What it describes | Who produces it |
| --- | --- | --- |
| `report.schema.json` | `rollcall report --format=json` and `GET /report.json`, public and internal views | Rollcall |
| `snapshot.schema.json` | One stored scan, `<ROLLCALL_STORE_DIR>/<date>.json` (internal: it holds app ids) | Rollcall |
| `images-export.schema.json` | The image list behind `ROLLCALL_IMAGES_FILE` | Any platform, or a hand-kept file |

## What the suite checks[#](https://rollcall.apps.wayne.edu/docs/reference/contract.html#what-the-suite-checks)

- The public and internal JSON reports, after one scan and after two, match `report.schema.json`.
- `GET /report.json` from the raw-PHP endpoint matches the same schema.
- Every stored snapshot matches `snapshot.schema.json`, and its `schema_version` equals the code's.
- The image list fixture matches its input schema.
- The drift kinds in the schema are exactly the ones the report builder counts, in the same order.
- The schema has teeth: a public report with a per-app list, an `owner` field or a made-up drift kind is refused, and so is an internal report without its list.

It reads fixtures only and never calls GitLab, Citadel or any other host. Run it alone with `vendor/bin/phpunit tests/ContractTest.php`; `composer ci` runs it with everything else.

## Rules[#](https://rollcall.apps.wayne.edu/docs/reference/contract.html#rules)

- **Closed objects.** Rollcall's own output uses `additionalProperties: false` throughout, so a new field is a contract change made in the same commit as the code. Inputs stay open: a producer may add fields of its own, and Rollcall ignores them.
- **Public means counts.** The public report may never carry `apps` or `scan_errors`, and no field holds an owner, a rank or a score.
- **Versioning.** From 1.0.0 on, Rollcall follows SemVer against this contract: an added field or enum value is a minor release; a removed or renamed field or a changed type is a major release. A snapshot carries its own `schema_version` (1 today), and a reader refuses a version it does not know.
