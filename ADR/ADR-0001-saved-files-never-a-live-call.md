# ADR-0001: Read saved files, never call a live service unattended

- **Status:** Accepted
- **Date:** 2026-10-28
- **Deciders:** Adam Perkins (Enterprise Applications), project owner. Recorded retroactively from `OPEN_QUESTIONS.md`
  (questions 2, 11 and 16) and the code.
- **Resolves:** how Rollcall gets image data, endoflife.date and Packagist advisories.

## Context

Rollcall needs facts that live in other systems: the runtime image of each app (Citadel or another platform), the
end-of-life dates endoflife.date publishes, and the security advisories Packagist publishes. It runs on a schedule,
with nobody watching. A scheduled job that calls out on its own is one more account to hold, one more host that can
be down, and one more place a token can leak.

GitLab is the exception it cannot avoid: the census starts from the repositories, so the scan reads them, with a
`read_api` token, `GET` and `HEAD` only.

## Decision

Apart from GitLab, Rollcall reads **saved files**, never a live service:

- the image list (`ROLLCALL_IMAGES_FILE`) or Citadel's registrations export (`ROLLCALL_CITADEL_EXPORT`);
- PROCTOR's JSON reports (`ROLLCALL_PROCTOR_DIR`);
- a saved endoflife.date page (`rollcall drift`) and a saved Packagist advisories response (`rollcall advisories`).

A person or a scheduled job saves each file. When a source offers a read-only endpoint, its answer is saved to the
file; Rollcall's reader does not change.

## Consequences

- Rollcall holds no Citadel account and makes no unattended outbound call except to GitLab.
- Every source is tested from fixtures, and the tests never call a host.
- The data is as fresh as the last saved file. Whoever schedules the scan schedules the saves too.
- `ReadOnlyAndDocsTest` keeps write verbs out of `src/`, and the GitLab adapter allows only `GET` and `HEAD`.
- Revisit if a source can only be read live; that would be a new adapter behind the same interface, and a new ADR.
