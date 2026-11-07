# ADR-0006: Owner digests are opt-in, print first, and mail only behind a flag

- **Status:** Accepted
- **Date:** 2026-11-07
- **Deciders:** Adam Perkins (Enterprise Applications), project owner, in the 1.0 brief: output to a file or the
  screen first, mail only behind an explicit flag and configuration.
- **Resolves:** backlog ROLLCALL-29, how an owner hears about their own apps.

## Context

Owners want to know when their apps change state. A tool that mails people on its own, by default, is a tool that
spams people when its config is wrong, and a mail transport is one more secret to hold.

## Decision

- `rollcall digest` builds one digest per opted-in owner from the internal report, so a digest never disagrees with
  the report. No change since the previous scan means no digest.
- By default it prints them (`--format=text` or `json`). `--out=DIR` writes one text file per owner for a mail tool
  you already have.
- `--send` mails them only when `ROLLCALL_DIGEST_MAIL_ENABLED=true`, `ROLLCALL_DIGEST_MAIL_DSN` and
  `ROLLCALL_DIGEST_MAIL_FROM` are set and `symfony/mailer` is installed (suggested, not required). Every contact is
  checked before anything is sent; one bad address sends nothing.

## Consequences

- With no owners file, no digest exists. With an owners file and no mail settings, nothing leaves the host.
- The DSN is a secret: never in git, never printed (hidden from `var_dump`).
- The digest is internal, like the per-app list, because it names apps.
