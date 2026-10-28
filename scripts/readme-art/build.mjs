#!/usr/bin/env node
/* ============================================================================
   ROLLCALL README art kit.

   Renders the README images from this repo's own static front page (site/)
   and brand files, and writes them under docs/readme/. Nothing is drawn by
   hand: the hero and the section cut are screenshots of the real page, the
   register is a page of the roll book built from the page's own sample table
   (fictional apps, labelled as sample data), the cast is the marks resized,
   and the badges read their facts from composer.json and the README.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this
const FORBIDDEN = /GitLab|gitlab\.example|citadel/i; // internal names never ship in art

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 48 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  // The site's marks folder holds the siblings; ROLLCALL's own mark lives in
  // its brand folder.
  if (name === "rollcall") return path.join(siteDir, "brand", "rollcall-avatar-256.png");
  if (name === "weld") return path.join(siteDir, "assets", "marks", "weld.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox. The visible ink spans
// 1546 x 1551 of the 1980 x 1989 viewBox and is centred in it, so this scale
// makes the mark 352 px tall on the 512 tile (about 69%), matching the square
// marks WELD and SESAME.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  // WayneUI first: one source (the traced mark) for both the SVG and the PNG.
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  record("cast/wayneui.svg", tile);
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  // The old dark-variant tile is gone: the green tile works on every surface.
  const stale = path.join(castDir, "wayneui-dark.png");
  if (existsSync(stale)) {
    rmSync(stale);
    console.log("  removed cast/wayneui-dark.png (no longer generated)");
  }
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) {
      console.log(`  skip cast/${name}.png: missing source ${path.relative(repoRoot, src)}`);
      continue;
    }
    const buf = readFileSync(src);
    const png = await sharp(buf)
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------------------- 2. badges */

// Facts are read from composer.json and the README, never typed: a badge that
// lies is worse than no badge at all. Colours are the register's own: the
// green binding and the blue ruling ink.
function buildBadges() {
  console.log("badges:");
  const composer = JSON.parse(readFileSync(path.join(repoRoot, "composer.json"), "utf8"));
  const readme = readFileSync(path.join(repoRoot, "README.md"), "utf8");
  // The exit-code contract, as the README states it: "`0` ok, `2` tool error".
  const exits = /Exit codes: `(\d)` ok, `(\d)` tool error/.exec(readme);
  const defs = [
    { key: "php", label: "php", message: composer.require.php },
    { key: "license", label: "licence", message: composer.license },
    { key: "exit-codes", label: "exit codes", message: exits ? `${exits[1]} ok, ${exits[2]} error` : "" },
  ];
  for (const d of defs) {
    if (!d.message) fail(`no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: "#0C5449",
      color: "#24427E",
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, svg);
  }
}

/* ------------------------------------------------------------- 3. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2, // crisp images
    reducedMotion: "reduce", // the finished page: every name answered, ink dry
  });
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, {
    waitUntil: "networkidle",
  });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

// The hard rule: no internal hostnames in anything we capture.
async function assertClean(page, selector, what) {
  const text = await page.locator(selector).first().innerText();
  if (FORBIDDEN.test(text)) fail(`forbidden text matched ${FORBIDDEN} in ${what}`);
}

/* ------------------------------------------------------------------ 4. hero */

async function buildHero(page) {
  console.log("hero:");
  // Hide the chrome that must not appear in the README art.
  await page.addStyleTag({ content: ".masthead, .wsu-skip-link { display: none !important; }" });
  await assertClean(page, ".titlepage", "hero");
  const png = await page.locator(".titlepage").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("hero.jpg", jpg);
}

/* ------------------------------------------------------ 5. the register page */

function esc(s) {
  return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function fontData(file) {
  return readFileSync(path.join(siteDir, "assets", "fonts", file)).toString("base64");
}

// Stamp ink per mark, read from the site's own CSS custom properties below.
const INK = { present: "#0c5449", late: "#24427e", "back row": "#8b2145", inaudible: "#4f5566" };
const ROT = { present: -4, late: 3, "back row": -6, inaudible: 2 };

// Reads the sample roll (17 fictional apps, labelled "sample data" on the page)
// straight out of the page's own table: names, versions, marks, seat labels,
// totals. Nothing is typed here.
async function readRoll(page) {
  await assertClean(page, "[data-roll]", "register");
  return page.$eval("[data-roll]", (root) => {
    const text = (el) => (el ? el.textContent.replace(/\s+/g, " ").trim() : "");
    const cellText = (td) => {
      const c = td.cloneNode(true);
      c.querySelectorAll("small").forEach((s) => s.remove());
      return text(c);
    };
    const items = [];
    root.querySelectorAll("table.roll tbody tr").forEach((tr) => {
      if (tr.classList.contains("seat__label")) {
        const th = tr.querySelector("th");
        const note = th.querySelector("span");
        const rest = th.cloneNode(true);
        rest.querySelectorAll("span").forEach((s) => s.remove());
        items.push({ kind: "seat", title: text(rest), note: text(note) });
        return;
      }
      const cells = tr.querySelectorAll("td");
      items.push({
        kind: "row",
        no: text(tr.querySelector(".roll__no")),
        name: text(tr.querySelector("th")),
        php: cellText(cells[1]),
        laravel: cellText(cells[2]),
        auth: cellText(cells[3]),
        proctor: cellText(cells[4]),
        off: [1, 2, 3, 4].map((i) => cells[i].classList.contains("is-off")),
        mark: text(tr.querySelector(".stamp")),
      });
    });
    const totals = [...root.querySelectorAll(".totals > div")].map((d) => ({
      label: text(d.querySelector("dt")),
      n: text(d.querySelector("dd")),
    }));
    return {
      sample: text(root.querySelector(".register__top .sample")),
      note: text(root.querySelector(".register__note")).split(". The public")[0] + ".",
      items,
      totals,
    };
  });
}

// One page of the roll book as a self-contained animated SVG: ruled paper, the
// red double margin rule, the 17 names called down the page, each answered with
// a rubber stamp. No script, no external refs; fonts are embedded as data URIs.
// Reduced motion gets the finished page.
function buildRegisterSvg(roll) {
  const W = 960;
  const padTop = 96;
  const rowH = 27;
  const seatH = 30;
  const colNo = 52; // right-aligned in the margin
  const ruleX = 66;
  const colName = 96;
  const colPhp = 292;
  const colLar = 404;
  const colAuth = 520;
  const colProc = 622;
  const colMark = 800; // stamp centre
  const font = "'Courier Prime', 'Courier New', ui-monospace, monospace";
  const display = "'Libre Caslon Display', 'Libre Caslon Text', Georgia, 'Times New Roman', serif";

  // Timeline (seconds). Names are called every STEP seconds; the stamp lands a
  // beat after the highlighter.
  const STEP = 0.42;
  const START = 0.9;
  const rows = roll.items.filter((i) => i.kind === "row");
  const T = Math.ceil(START + rows.length * STEP + 0.6 + 4.2 + 0.8);
  const pct = (t) => ((t / T) * 100).toFixed(2);
  const fadeOutAt = T - 0.9;

  // Layout pass.
  let y = padTop + 34; // below the column heads
  const placed = roll.items.map((it) => {
    if (it.kind === "seat") {
      y += seatH;
      return { ...it, y: y - 8 };
    }
    y += rowH;
    return { ...it, y: y - 8 };
  });
  const tableBottom = y + 12;
  const totalsY = tableBottom + 20;
  const H = totalsY + 90;

  const css = [];
  const body = [];
  let k = 0;

  // Ruled lines under every row.
  const rules = [];
  placed.forEach((it) => {
    rules.push(`<path d="M${ruleX + 8} ${it.y + 7.5}H${W - 28}"/>`);
  });

  placed.forEach((it) => {
    if (it.kind === "seat") {
      body.push(
        `<text x="${colName}" y="${it.y}" font-family="${font}" font-size="12" font-style="italic" fill="#4f5566"><tspan font-weight="700" fill="#1c2541" font-style="normal" letter-spacing="1.4">${esc(it.title.toUpperCase())}</tspan>  ${esc(it.note)}</text>`,
      );
      return;
    }
    const idx = k++;
    const tCall = START + idx * STEP;
    const tStamp = tCall + 0.16;
    const off = (n) => (it.off[n] ? "#8b2145" : "#1c2541");
    const cell = (x, txt, n) =>
      `<text x="${x}" y="${it.y}" font-family="${font}" font-size="14" fill="${off(n)}"${it.off[n] ? ' font-weight="700"' : ""}>${esc(txt)}</text>`;
    // highlighter bar (the "calling" pen) under the row, then the row text
    body.push(
      `<rect class="pen pen${idx}" x="${ruleX + 8}" y="${it.y - 16}" width="${W - 28 - ruleX - 8}" height="23" fill="#ffcc33" fill-opacity=".62" opacity="0"/>`,
    );
    body.push(
      `<text x="${colNo}" y="${it.y}" text-anchor="end" font-family="${font}" font-size="12" fill="#8b2145" fill-opacity=".8">${esc(it.no)}</text>`,
    );
    body.push(
      `<text x="${colName}" y="${it.y}" font-family="${font}" font-size="15" font-weight="700" fill="#1c2541">${esc(it.name)}</text>`,
    );
    body.push(cell(colPhp, it.php, 0), cell(colLar, it.laravel, 1), cell(colAuth, it.auth, 2), cell(colProc, it.proctor, 3));
    // the stamp
    const word = it.mark.toLowerCase();
    const ink = INK[word];
    if (!ink) fail(`unknown mark "${it.mark}" (expected ${Object.keys(INK).join(", ")})`);
    const label = word.toUpperCase();
    const sw = Math.round(label.length * 8.6 + 18);
    const dashed = word === "inaudible" ? ' stroke-dasharray="4 3"' : "";
    body.push(
      `<g transform="translate(${colMark} ${it.y - 5}) rotate(${ROT[word]})">` +
        `<g class="stamp st${idx}"><rect x="${-sw / 2}" y="-11" width="${sw}" height="21" rx="3" fill="none" stroke="${ink}" stroke-width="1.8"${dashed}/>` +
        (word === "inaudible" ? "" : `<rect x="${-sw / 2 + 3}" y="-8" width="${sw - 6}" height="15" rx="1.5" fill="none" stroke="${ink}" stroke-width=".9"/>`) +
        `<text x="0" y="4.2" text-anchor="middle" font-family="${font}" font-size="12" font-weight="700" letter-spacing="1.2" fill="${ink}">${label}</text></g></g>`,
    );
    // keyframes for this row
    css.push(
      `@keyframes pen${idx}{0%,${pct(tCall - 0.01)}%{opacity:0}${pct(tCall)}%,${pct(tCall + STEP - 0.04)}%{opacity:1}${pct(tCall + STEP)}%,100%{opacity:0}}` +
        `@keyframes st${idx}{0%,${pct(tStamp - 0.01)}%{opacity:0;transform:scale(1.9)}${pct(tStamp + 0.14)}%{opacity:.94;transform:scale(.94)}${pct(tStamp + 0.24)}%{opacity:.94;transform:scale(1)}${pct(fadeOutAt)}%{opacity:.94;transform:scale(1)}100%{opacity:0;transform:scale(1)}}` +
        `.pen${idx}{animation:pen${idx} ${T}s linear infinite}.st${idx}{animation:st${idx} ${T}s linear infinite}`,
    );
  });

  // Totals, tallied once the last name is answered.
  const tTot = START + rows.length * STEP + 0.3;
  const tot = roll.totals;
  const sum = tot[tot.length - 1];
  let tx = colName;
  const totParts = [];
  tot.slice(0, -1).forEach((t) => {
    const word = t.label.toLowerCase();
    const ink = INK[word] || "#1c2541";
    const label = word.toUpperCase();
    const sw = Math.round(label.length * 7.6 + 16);
    totParts.push(
      `<g transform="translate(${tx} ${totalsY})"><rect x="0" y="-13" width="${sw}" height="19" rx="2.5" fill="none" stroke="${ink}" stroke-width="1.5"${word === "inaudible" ? ' stroke-dasharray="4 3"' : ""}/>` +
        `<text x="${sw / 2}" y="1" text-anchor="middle" font-family="${font}" font-size="11" font-weight="700" letter-spacing="1" fill="${ink}">${label}</text>` +
        `<text x="${sw + 10}" y="2" font-family="${font}" font-size="18" font-weight="700" fill="#1c2541">${esc(t.n)}</text></g>`,
    );
    tx += sw + 10 + 36;
  });
  totParts.push(
    `<text x="${W - 28}" y="${totalsY + 2}" text-anchor="end" font-family="${font}" font-size="14" fill="#1c2541"><tspan letter-spacing="1.4" font-weight="700">${esc(sum.label.toUpperCase())}</tspan>  <tspan font-size="20" font-weight="700" fill="#0c5449">${esc(sum.n)}</tspan> of ${esc(sum.n)}</text>`,
  );
  css.push(
    `@keyframes tot{0%,${pct(tTot)}%{opacity:0}${pct(tTot + 0.5)}%,${pct(fadeOutAt)}%{opacity:1}100%{opacity:0}}.tot{animation:tot ${T}s linear infinite}`,
  );

  const faces = [
    ["Courier Prime", 400, "normal", "courier-prime-latin-400-normal.woff2"],
    ["Courier Prime", 700, "normal", "courier-prime-latin-700-normal.woff2"],
    ["Courier Prime", 400, "italic", "courier-prime-latin-400-italic.woff2"],
    ["Libre Caslon Display", 400, "normal", "libre-caslon-display-latin-400-normal.woff2"],
  ]
    .map(
      ([fam, w, st, f]) =>
        `@font-face{font-family:'${fam}';font-weight:${w};font-style:${st};src:url(data:font/woff2;base64,${fontData(f)}) format('woff2')}`,
    )
    .join("");

  const desc = `A page of the roll book: ${rows.length} sample apps called down the register one at a time, each answered with a stamp. ${tot
    .slice(0, -1)
    .map((t) => `${t.n} ${t.label}`)
    .join(", ")}. ${roll.sample}.`;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="reg-title reg-desc">
  <title id="reg-title">ROLLCALL's roll book: every app answers</title>
  <desc id="reg-desc">${esc(desc)}</desc>
  <style>
    ${faces}
    .stamp{transform-box:fill-box;transform-origin:center;opacity:.94}
    ${css.join("\n    ")}
    @media (prefers-reduced-motion: reduce){
      .pen{animation:none!important;opacity:0!important}
      .stamp{animation:none!important;opacity:.94!important;transform:none!important}
      .tot{animation:none!important;opacity:1!important}
    }
  </style>
  <rect width="${W}" height="${H}" rx="16" fill="#f6f0e1"/>
  <g stroke="#24427e" stroke-opacity=".16" stroke-width="1">${rules.join("")}</g>
  <path d="M${ruleX} 0V${H}M${ruleX + 5} 0V${H}" stroke="#b23448" stroke-opacity=".6" stroke-width="1.3"/>
  <text x="${colName}" y="52" font-family="${display}" font-size="34" fill="#1c2541">Today's roll</text>
  <text x="${W - 28}" y="46" text-anchor="end" font-family="${font}" font-size="12" fill="#4f5566">${esc(roll.sample)}</text>
  <g font-family="${font}" font-size="11" font-weight="700" letter-spacing="1.3" fill="#24427e">
    <text x="${colNo}" y="${padTop + 12}" text-anchor="end">NO.</text>
    <text x="${colName}" y="${padTop + 12}">APP</text>
    <text x="${colPhp}" y="${padTop + 12}">PHP IMAGE</text>
    <text x="${colLar}" y="${padTop + 12}">LARAVEL</text>
    <text x="${colAuth}" y="${padTop + 12}">AUTH</text>
    <text x="${colProc}" y="${padTop + 12}">PROCTOR</text>
    <text x="${colMark}" y="${padTop + 12}" text-anchor="middle">MARK</text>
  </g>
  <path d="M${ruleX + 8} ${padTop + 22}H${W - 28}" stroke="#1c2541" stroke-opacity=".55" stroke-width="1.6"/>
${body.join("\n")}
  <g class="tot">
    <path d="M${ruleX + 8} ${totalsY - 26}H${W - 28}" stroke="#1c2541" stroke-opacity=".55" stroke-width="1.6"/>
    ${totParts.join("\n    ")}
    <text x="${colName}" y="${totalsY + 46}" font-family="${font}" font-size="12" font-style="italic" fill="#4f5566">${esc(roll.note)}</text>
  </g>
</svg>
`;
}

async function buildRegister(page) {
  console.log("register:");
  const roll = await readRoll(page);
  const rows = roll.items.filter((i) => i.kind === "row");
  if (rows.length !== 17) fail(`expected 17 sample rows, found ${rows.length}`);
  const svg = buildRegisterSvg(roll);
  if (FORBIDDEN.test(svg.replace(/base64,[A-Za-z0-9+/=]+/g, ""))) fail("register.svg text matched a forbidden name");
  record("register.svg", Buffer.from(svg, "utf8"));
}

/* ------------------------------------------------------ 6. section cut + board */

// One more front-page section, cut from the real page: the sample burn-down
// chart (fictional apps, labelled "Sample data" on the page).
async function buildSectionCut(page) {
  console.log("section cut:");
  const sel = '[aria-labelledby="burn-title"] [data-chart]';
  await assertClean(page, sel, "burn-down section");
  const png = await page.locator(sel).screenshot();
  const jpg = await sharp(png).resize({ width: 1280, withoutEnlargement: true }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("burn-down.jpg", jpg);
}

// The brand board, re-encoded small enough to live in the README.
async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "rollcall-board.png");
  if (!existsSync(src)) fail("missing site/brand/rollcall-board.png");
  for (const q of [82, 74, 66, 58]) {
    const jpg = await sharp(src).resize({ width: 1600, withoutEnlargement: true }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
    if (jpg.length <= 300 * 1024 || q === 58) {
      record("board.jpg", jpg);
      return;
    }
  }
}

/* -------------------------------------------------------------------- main */

async function main() {
  await buildCast();
  buildBadges();
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const { context, page } = await openPage(browser);
    await buildRegister(page);
    await buildSectionCut(page);
    await buildHero(page);
    await context.close();
  } finally {
    await browser.close();
  }

  // The size gate: print what we have, fail if anything grew past the limit.
  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
