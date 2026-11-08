---
title: Owner digests
description: An opt-in digest per owner, about only their apps and only when something changed; printed by default, mailed only behind a flag and three settings.
---

# Owner digests

This page shows how an owner who asks gets a digest of only their own apps. Next: [metrics](metrics.md).

A digest is a short note in plain words: what changed in that owner's apps since the previous scan, then where each
app stands. Nothing changed, no digest. Nobody gets one by default, and Rollcall never guesses an owner from
commit authors.

## 1. Say who answers for which apps

An owners file maps each scanned project to the contacts who answer for it. Point `ROLLCALL_OWNERS_FILE` at it. An
owner opts in with `"digest": true`; leaving the key out means no.

```json
{
    "$schema": "contract/owners.schema.json",
    "owners": [
        {"contact": "team@example.test", "digest": true, "projects": ["group/project", "group/other-project"]},
        {"contact": "another-team@example.test", "projects": ["group/third-project"]}
    ]
}
```

Here the first team gets digests and the second does not. A contact is a mail address, a team alias or a role; each
one is listed once, and a project may sit under more than one contact. The shape is
`contract/owners.schema.json`, and the optional `"$schema"` line lets an editor check the file as you type.

Rollcall keeps its own file in the repo, [`owners.json`](https://github.com/waynestate/rollcall/blob/main/owners.json): the 13 stack projects the
scheduled scan reads, all answered for by the stack maintainer. The repo is public, so its contacts are roles and
teams, never a person (a test refuses an `@` in it), and those digests print rather than mail. To scan another
fleet, keep that fleet's file next to its snapshots, outside a public repo, and point `ROLLCALL_OWNERS_FILE` there.

An app in the scan that no entry lists is not dropped. `rollcall digest` names it on stderr every run,

```
No owner on file for 1 of 14 apps in this scan, so no digest covers it. Add it to the owners file:
- group/new-project: no owner on file
```

and `--format=json` lists it under `no_owner_on_file`. An owner who has not opted in still counts as its owner.
Reports never read the owners file, so the report stays free of owners.

## 2. Print them

After a scan:

```
rollcall digest
```

prints one digest per opted-in owner with news, each headed `To:` and `Subject:`. `--owner=CONTACT` picks one
owner (an owner who has not opted in is refused), `--format=json` gives a script the same digests
(`contract/digest.schema.json`), and `--out=DIR` writes them as `<scan date>-<contact>.txt` instead of printing, for
a mail tool you already run. Lines on stderr name any app with no owner on file and count how many owners had news
and how many get nothing this time.

What counts as a change, app by app:

- the PHP image moved (a move from end of life to a supported PHP says so, with a well-earned "Present!");
- the declared PHP floor, the Laravel version or the PROCTOR result changed;
- a drift flag appeared ("now runs an end-of-life Laravel") or cleared ("no longer has a failing PROCTOR result"),
  including one that appears because a date in CREED's policy passed;
- an app answered present for the first time, or was in the last scan and is not in this one.

The first scan has no "before", so the first digest is a first roll call: every app and where it stands. The states
and flags are the report's own (`rollcall report --internal`), so a digest and the report never disagree.

## 3. Mail them (only if you want Rollcall to)

Sending is a second, separate switch, and every part of it must be there:

1. `composer require symfony/mailer` (Rollcall only suggests it).
2. `ROLLCALL_DIGEST_MAIL_ENABLED=true`.
3. `ROLLCALL_DIGEST_MAIL_DSN`: a Symfony Mailer DSN, such as `smtp://mail.example.test:25`. It can hold a password,
   so it is a secret: never in git, never printed.
4. `ROLLCALL_DIGEST_MAIL_FROM`: the from address.
5. `rollcall digest --send`.

Without any one of them nothing is mailed; with the settings but no `--send`, the digests are only printed. Every
contact with news must be a mail address, or nothing goes out at all. Mail is plain text. This is the one outbound
action in Rollcall, so it stays behind both the flag and the settings.

Schedule it right after `rollcall scan`: weekly suits the burn-down, and a quiet week sends nothing.
