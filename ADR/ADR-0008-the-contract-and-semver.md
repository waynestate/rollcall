# ADR-0008: A JSON Schema contract, proven on real output, versioned by SemVer from 1.0

- **Status:** Accepted
- **Date:** 2026-11-07
- **Deciders:** Adam Perkins (Enterprise Applications), project owner, in the 1.0 brief: a contract file plus a suite
  that validates real output.
- **Resolves:** backlog ROLLCALL-25.

## Context

Dashboards, Grafana, ADVISOR and scripts read Rollcall's JSON. Until now the shape was whatever the code printed,
described by examples in the docs. An example cannot tell a reader which fields are promised, and nothing failed when
the code and the docs drifted.

## Decision

- `contract/` holds JSON Schemas (draft 2020-12) for every shape Rollcall promises or reads: the report (public and
  internal), the stored snapshot, the digest, the image list, Citadel's export and the owners file.
- Rollcall's own output is **closed** (`additionalProperties: false`), so a new field is a contract change in the same
  commit. Inputs stay open: a producer may add fields, and Rollcall ignores them.
- `tests/ContractTest.php` runs the real commands on the fixtures and validates what they print and store, using
  `opis/json-schema` (a dev dependency only). It also proves the schemas have teeth.
- From 1.0.0, Rollcall follows SemVer against the contract: an added field or enum value is a minor release; a
  removed or renamed field or a changed type is a major release. The snapshot carries its own `schema_version`.

## Consequences

- `composer ci` fails when the code and the contract disagree.
- Readers validate against the schema, not against an example.
- The 1.0.0 tag is what turns the SemVer promise on; until then the contract is in force but may still change.
