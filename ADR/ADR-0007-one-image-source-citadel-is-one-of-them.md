# ADR-0007: One image source at a time; Citadel's export is one source among others

- **Status:** Accepted
- **Date:** 2026-11-07
- **Deciders:** Adam Perkins (Enterprise Applications), project owner, in the 1.0 brief: a Citadel source from a file
  export or a documented read-only shape, fixtures only, not required.
- **Resolves:** backlog ROLLCALL-10, and the reader half of `OPEN_QUESTIONS.md` question 2.

## Context

Most of the fleet runs on Citadel, which knows each app's base image and schedules forced base-image upgrades with a
due date. The stack must also run on OpenShift, Coolify and plain Docker, where Citadel does not exist. Merging two
image sources would raise a question nobody can answer from the data: which one wins when they disagree?

## Decision

- The portable source stays the image list (`ROLLCALL_IMAGES_FILE`), which any platform can produce.
- Citadel's registrations export (`ROLLCALL_CITADEL_EXPORT`) is a second, complete image source, in Citadel's own
  field names, read from a saved file (ADR-0001). It also brings the forced upgrade dates.
- A scan uses one or the other. Setting both stops the scan with a plain message.
- Entries it cannot trust are skipped, never guessed. Citadel apps that are not in the scanned repositories are not
  invented: the census starts from GitLab.

## Consequences

- The report gains `forced_upgrades` (counts only, zero with any other source); the internal list and the snapshot
  gain `upgrade_due`. Both are in the contract.
- Producing the export on a schedule is the Citadel team's part, still open in question 2.
- A platform with its own upgrade schedule would get its own adapter behind `UpgradeDueSource`.
