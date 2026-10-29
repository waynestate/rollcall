# ROLLCALL best practices

Drawn from the stack's long guide (with sources). This is the short list for people who
change ROLLCALL, tagged by how the repository holds each rule at 0.1.

Tags: **Enforced** (code or a failing test), **Checked** (a test or CI warns), **Documented** (written down only),
**Not yet** (a gap, listed in `OPEN_QUESTIONS.md`).

## Collect read-only and lightly

| Rule | Tag | How |
|---|---|---|
| Never write to the systems you scan | Enforced | `GitLabRepositorySource::send()` allows only `GET` and `HEAD`; `ReadOnlyAndDocsTest` fails on a write verb in `src/` and on file writes outside the snapshot store |
| Use the smallest token scope (`read_api`), owned by a group or service account | Documented | README; ROLLCALL cannot see a token's scopes |
| Plan token expiry and rotation; an expired token is a loud failure | Enforced (warning, loud failure) / Documented (rotation) | A 401 or 403 stops the scan with a plain message; `scan` prints a note 14 days before the token expires (and when it has already expired); rotation is in `OPEN_QUESTIONS.md` |
| List projects per group with subgroups, in the small form | Enforced | `include_subgroups=true&simple=true&archived=false`, tested |
| Fetch only the files you need, never clone | Enforced | File endpoints only; the directory adapter reads two named files |
| Skip what has not changed | Enforced | `HEAD` blob id compared with the last snapshot; tested for both adapters |
| Respect rate limits and back off | Enforced | `429` waits for `Retry-After` (capped at 120 s), bounded retries, tested |
| Follow pagination links, only to the configured host | Enforced | `Link: rel="next"`; a link to another host is refused so the token stays home |
| Scan from the lock file; never run the scanned app's code | Enforced | `ComposerReader` only decodes JSON |
| Keep the token out of logs and out of scanned repos | Checked | Hidden from `print_r`/`var_dump`; never in an error message (tests) |

## Take version states from one source

| Rule | Tag | How |
|---|---|---|
| Derive state from `policy.json` by date | Enforced | `Policy::stateOf()` is the only place a state is decided |
| endoflife.date is a drift check only | Enforced | `rollcall drift` flags, never changes a state |
| Report image, declared floor and framework as separate facts | Enforced | Separate fields and tables |
| Read the PROCTOR report; do not re-run a rule | Enforced | `ProctorReportReader` validates PROCTOR's JSON shape |
| Show every override with reason and expiry; an expired one stays, marked expired | Checked | Internal report and page; tested |
| Show the date next to every state | Enforced | `security_until` and `days_to_eol` in each table |
| A package with no advisory data says "no data", not "safe" | Not yet | The Packagist advisories lookup is not built |

## Show progress without blame

| Rule | Tag | How |
|---|---|---|
| Report at app level, as counts, never a ranking | Checked | The public report has no app ids; a test fails if `rank`, `owner`, `score` appear in the JSON |
| Set no per-owner numeric targets | Documented | There are none to set |
| Credit an upgrade on the day it lands | Checked | `upgraded_on` in the internal report; tested |
| Keep a snapshot per scan | Enforced | One atomic JSON file per day |
| Per-app lists stay internal | Enforced | Off in the public report, `/report.json`, `/metrics`; the Laravel per-app page is opt-in behind your middleware |

## Metrics

| Rule | Tag | How |
|---|---|---|
| Off by default; protected by a token or allowlist | Enforced | `Endpoint` answers 404 while off, 401 without credentials, and denies everyone with no credentials configured |
| No personal data; low-cardinality labels | Enforced | The renderer builds from the public report and collapses any unexpected value to `unknown` or `other` |

## Not yet

Citadel read-only adapter, the upgrade-record reader, ownership verification, the Packagist advisories lookup, the
CycloneDX bill of materials, the front page and the a11y gate run. See `OPEN_QUESTIONS.md`, "Not built yet".
