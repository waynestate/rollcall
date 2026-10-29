# Rollcall front page

Static HTML, CSS and a little vanilla JS. No build step: serve this folder from Citadel or anywhere else that
serves files. Open `index.html` through a web server (fonts are blocked over `file://`).

type: Libre Caslon Display (display) and Courier Prime (typewriter); body text is Lato from WayneUI.

- **World:** the roll book. Warm ruled paper with a red double margin rule, typewritten names, rubber stamps
  (present, late, back row, inaudible), a gold highlighter, and the green cloth binding as the back cover.
- **Magic:** the roll is taken. As the register scrolls in, Rollcall calls each name, the row is highlighted, a stamp
  thumps down in wet ink that dries, and the back row raises its hands. The burn-down chart rules itself by hand.
- **Built on WayneUI 2.0.8** (`assets/wayne-ui/`, copied, no CDN): tokens, Lato, skip link, `wsu-sr-only`. The
  look is overridden in `assets/rollcall.css`.
- **Fonts** are self-hosted from `@fontsource` under the SIL Open Font License; licences in `assets/fonts/`.
- **Reduced motion:** with `prefers-reduced-motion: reduce` the page arrives with the roll already taken, every word
  at first paint.
- **Sample data:** every app name on the page is fictional and labelled as sample data. The printout is real output
   from Rollcall 0.1 against the bundled fixtures.
- Screenshots: `screenshots/` (desktop 1440, mobile 390, reduced motion, motion GIF).

## Links between the stack sites

Links to the other parts are committed as production URLs (`https://<project>.apps.wayne.edu/`), so crawlers and
visitors without JavaScript always get production. `assets/stack-env.js` (the same file on every stack site) rewrites
them to `http://127.0.0.1:18432/<project>/site/` in development, for a static server whose root is the
folder that holds all the projects side by side. Dev is picked automatically on `localhost` or `127.0.0.1`; `?env=dev` or `?env=production` forces
one (remembered until `?env=auto`), `<meta name="stack-env">` sets the page default, and `?devBase=http://127.0.0.1:<port>`
points at a server on another port. WayneUI links stay on production.
