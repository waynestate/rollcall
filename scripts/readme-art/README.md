# Rollcall README art kit

Regenerates the README images under `docs/readme/` from this repo's own static
front page (`site/`) and brand files. Nothing is drawn by hand: the hero and
the burn-down cut are screenshots of the real page, the register is a page of
the roll book built from the page's own sample table (fictional apps, labelled
as sample data), the cast is the marks resized, and the badges read their
facts from `composer.json` and the README.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL: no server, no
network. Same inputs, same outputs.

## What it makes

- `docs/readme/hero.jpg`: the title page, with the masthead hidden.
- `docs/readme/register.svg`: the sample roll as a self-contained animated SVG.
  Names are called down the page one at a time and each answers with its
  stamp; under `prefers-reduced-motion` the page is simply already filled in.
  Fonts are embedded, so it looks the same inside an `<img>` anywhere.
- `docs/readme/burn-down.jpg`: the sample burn-down chart, cut from the page.
- `docs/readme/board.jpg`: `site/brand/rollcall-board.png`, re-encoded small.
- `docs/readme/cast/*.png`: one 96x96 mark per stack part.
- `docs/readme/badges/*.svg`: `php`, `licence` and `exit-codes`, made locally
  by `badge-maker` from `composer.json` and the README's exit-code line.

Every file must stay at or under 300 KB, and nothing captured may mention an
internal host name; the script prints each size and exits 1 on either breach.

If a badge ever looks wrong, the source fact is wrong, not the badge. (Nobody
is ever marked absent here, only late.)
