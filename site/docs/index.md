---
title: "Rollcall | Rollcall docs"
description: "Rollcall is the fleet scanner for the Wayne State application stack."
url: https://rollcall.apps.wayne.edu/docs/
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Rollcall[#](https://rollcall.apps.wayne.edu/docs/#rollcall)

This page says what Rollcall is and where it sits in the stack. Next: [install it](https://rollcall.apps.wayne.edu/docs/install.html), then run the [quick start](https://rollcall.apps.wayne.edu/docs/guides/quick-start.html).

> Every app answers "present". The ones on PHP 7.4 answer from the back row.

Front page: [`site/index.html`](https://github.com/waynestate/rollcall/blob/main/site/index.html). It is static, with no build step (see [`site/README.md`](https://github.com/waynestate/rollcall/blob/main/site/README.md)). On Citadel, the raw-PHP deploy layer in [`deploy/`](https://github.com/waynestate/rollcall/tree/main/deploy) serves it: a Squire kit whose front controller puts `site/` behind HELMET's security headers. For local development, serve `site/` directly.

Rollcall is the fleet scanner for the Wayne State application stack. For each app it records the PHP image, the lowest PHP its `composer.json` allows, the Laravel version, the auth package and its version, the PROCTOR result, and, when the app commits one, the result, Booster version and finish time of Booster's hop record (`booster-hop.json`). Then it looks up each version by date in CREED's `policy.json`. It keeps one snapshot per scan, so you can see drift and an EOL burn-down over time. It prunes old snapshots after the retention window (default 90 days; the newest one always stays).

Rollcall enforces nothing. It never opens an issue, leaves a comment or deploys anything, and `scan` and `report` exit `0` whatever the fleet looks like (only `rollcall owners`, a check of the owners file, exits `1`). The public report has totals only: counts and progress, no app names, owners or rankings. The per-app list is internal. Like a good attendance sheet, it is for noticing who is missing, not for shaming them.

![A page of Rollcall's roll book: seventeen sample apps called down the register one at a time, each answered with a stamp: seven present, five late, four back row and one inaudible.](https://rollcall.apps.wayne.edu/docs/readme/register.svg)
One page of the roll book, called name by name. Sample data: the apps are fictional, and a real scan's per-app list stays internal.

## Where it sits in the stack[#](https://rollcall.apps.wayne.edu/docs/#where-it-sits-in-the-stack)

The parts of the Wayne State application stack, and their jobs. WELD is the Laravel starter app that has most of them installed:

| Part | Job |
| --- | --- |
| WayneUI (project 0) | The interface layer: tokens, components, four consumer paths |
| CREED | The standards and `policy.json`, the dates Rollcall reads |
| PROCTOR | Measures one app against the policy; Rollcall reads its JSON report and never re-runs a rule |
| Preflight | CI templates |
| SESAME | Protocol-neutral auth; Rollcall reports which auth package each app uses |
| HELMET | Baseline runtime and its own optional `/metrics` |
| Pinstripe | The coding standard this repo includes for its Pint and PHPStan settings |
| Sourdough | Starters |
| Rollcall | The fleet census and EOL burn-down |

**The whole class**
WayneUI and the stack. Everyone is here today; the ones in the back row know who they are.

[![WayneUI](https://rollcall.apps.wayne.edu/docs/readme/cast/wayneui.png)](https://github.com/waynestate/wayne-ui) [![CREED](https://rollcall.apps.wayne.edu/docs/readme/cast/creed.png)](https://github.com/waynestate/creed) [![PROCTOR](https://rollcall.apps.wayne.edu/docs/readme/cast/proctor.png)](https://github.com/waynestate/proctor) [![SESAME](https://rollcall.apps.wayne.edu/docs/readme/cast/sesame.png)](https://github.com/waynestate/sesame) [![HELMET](https://rollcall.apps.wayne.edu/docs/readme/cast/helmet.png)](https://github.com/waynestate/helmet) [![Sourdough](https://rollcall.apps.wayne.edu/docs/readme/cast/sourdough.png)](https://github.com/waynestate/sourdough) [![Squire](https://rollcall.apps.wayne.edu/docs/readme/cast/squire.png)](https://github.com/waynestate/squire) [![Preflight](https://rollcall.apps.wayne.edu/docs/readme/cast/preflight.png)](https://github.com/waynestate/preflight) [![Pinstripe](https://rollcall.apps.wayne.edu/docs/readme/cast/pinstripe.png)](https://github.com/waynestate/pinstripe) [![Booster](https://rollcall.apps.wayne.edu/docs/readme/cast/booster.png)](https://github.com/waynestate/booster) [![Rollcall](https://rollcall.apps.wayne.edu/docs/readme/cast/rollcall.png)](https://github.com/waynestate/rollcall) [![Drawbridge](https://rollcall.apps.wayne.edu/docs/readme/cast/drawbridge.png)](https://git.wayne.edu/enterprise-apps/drawbridge) [![ADVISOR](https://rollcall.apps.wayne.edu/docs/readme/cast/advisor.png)](https://github.com/waynestate/advisor) [![WELD](https://rollcall.apps.wayne.edu/docs/readme/cast/weld.png)](https://github.com/waynestate/weld)

Images are regenerated from the front page by [`scripts/readme-art`](https://github.com/waynestate/rollcall/tree/main/scripts/readme-art). Present.

**The brand board (the cover of the book)**

![Rollcall's brand board: the open roll-book mark with a gold tick on a green tile, lockups, the green, gold and cream palette, type, favicons, and do and don't panels.](https://rollcall.apps.wayne.edu/docs/readme/board.jpg)

## Install[#](https://rollcall.apps.wayne.edu/docs/#install)

One line:

```
composer require waynestate/rollcall
```

The first command is `php bin/rollcall scan`; the [quick start](https://rollcall.apps.wayne.edu/docs/guides/quick-start.html) runs it on the fixtures in the repo, and [Install](https://rollcall.apps.wayne.edu/docs/install.html) has the rest.
