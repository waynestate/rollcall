# Rollcall front page

Static HTML, CSS and a little vanilla JS. No build step: serve this folder from Citadel or anywhere else that
serves files. Open `index.html` through a web server (fonts are blocked over `file://`).

type: Libre Caslon Display (display) and Courier Prime (typewriter); body text is Lato from WayneUI.

- **World:** the roll book. Warm ruled paper with a red double margin rule, typewritten names, rubber stamps
  (present, late, back row, inaudible), a gold highlighter, and the green cloth binding as the back cover.
- **Magic:** the roll is taken. As the register scrolls in, Rollcall calls each name, the row is highlighted, a stamp
  thumps down in wet ink that dries, and the back row raises its hands. The burn-down chart rules itself by hand.
  On the title page the release is a report card: a green PROMOTED stamp comes down beside the version, which the
  docs builder stamps at every release (`data-docs-version`), with a link to the contract's SemVer promise.
- **Built on WayneUI 2.0.8** (`assets/wayne-ui/`, copied, no CDN): tokens, Lato, skip link, `wsu-sr-only`. The
  look is overridden in `assets/rollcall.css`.
- **Fonts** are self-hosted from `@fontsource` under the SIL Open Font License; licenses in `assets/fonts/`.
- **Reduced motion:** with `prefers-reduced-motion: reduce` the page arrives with the roll already taken, every word
  at first paint.
- **Sample data:** every app name on the page is fictional and labeled as sample data. The printout is real output
    from Rollcall 1.1.1 against the bundled fixtures.
- Screenshots: `screenshots/` (desktop 1440, mobile 390, reduced motion, motion GIF).

## The door to the fleet dashboard

The title page's "Teacher's copy" ribbon links to the fleet dashboard, which the dashboard app serves at `/dashboard`
behind a Wayne State sign-in on the same host as this page (D-050). Words only: no counts appear on this page. The
committed href is production (`https://rollcall.apps.wayne.edu/dashboard`); `stack-env.js` moves it to the
pre-production host like every other part link. In development `rollcall.js` decides: served from a path with
`/site/` in it (the side-by-side stack server) or from `file://`, there is no app behind the door, so the name stays
as plain text and the note says it lives on the deployed site; served from anywhere else on a dev host (the dashboard
app on a laptop), the link becomes `/dashboard`.

## Links between the stack sites

Links to the other parts are committed as production URLs (`https://<project>.apps.wayne.edu/`), so crawlers and
visitors without JavaScript always get production. `assets/stack-env.js` (the same file on every stack site) rewrites
them to `http://127.0.0.1:18432/<project>/site/` in development, for a static server whose root is the
folder that holds all the projects side by side. Dev is picked automatically on `localhost` or `127.0.0.1`;
`?env=dev` or `?env=production` forces one (remembered until `?env=auto`), `<meta name="stack-env">` sets the page
default, and `?devBase=http://127.0.0.1:<port>` points at a server on another port. On a host ending in
`.appstest.wayne.edu` the links point at `https://<project>.appstest.wayne.edu/` instead, the pre-production home of
every part (`?env=test` forces it). WayneUI is the exception: in development its links go to
`http://127.0.0.1:4180/`, since it does not run from a subfolder, and in test they stay on production, since there is
no pre-production WayneUI.
