// Rollcall's Vue dashboard parity check: one roll, three rooms.
//
// Server-renders resources/js/RollcallDashboard.vue with the props the Laravel
// adapter hands its Inertia page, and compares the result with what the PHP
// renderer (DashboardPage) prints inside <main> for the same report. Pass and
// it writes the Vue pages for the dashboard accessibility gate.
//
// It reads, from <dir> (DashboardRenderTest writes all six):
//   index.html, apps.html, empty.html          the Laravel (Blade) pages
//   inertia.json, inertia-apps.json, inertia-empty.json
//                                              the Inertia page objects the same
//                                              routes answer with when
//                                              rollcall.dashboard.inertia is on
// and writes vue.html, vue-apps.html and vue-empty.html: the server-rendered
// component inside a bare page shell (the same skip link, <main id="main"> and
// WayneUI stylesheet as the PHP page, and no inline style, the way an app
// under HELMET's CSP serves it).
//
// The comparison is structural: both sides are parsed as HTML (parse5) and
// each element is compared by name, attributes (in name order) and text. Only
// three things are normalized away: runs of whitespace (collapsed, and dropped
// between elements, where HTML does not render them), attribute order, and
// comments, which on the Vue side are only its hydration markers (<!--[-->,
// <!--v-if-->). Character references are compared decoded, because &#039; and
// &#39; are the same apostrophe.
//
// Usage: node tools/vue-dashboard/parity.mjs <dir>
// Exit:  0 the pages match, 1 a difference (printed), 2 a tool or input error.
//
// Nobody answers "present" for somebody else here: every name on the roll is
// read out loud, three times, and the three readings must agree.

import { readFile, writeFile } from 'node:fs/promises';
import { stripTypeScriptTypes } from 'node:module';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { parse as parseDocument, parseFragment } from 'parse5';
import { createSSRApp } from 'vue';
import { compileScript, parse as parseSfc } from 'vue/compiler-sfc';
import { renderToString } from 'vue/server-renderer';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const COMPONENT = path.resolve(HERE, '../../resources/js/RollcallDashboard.vue');

const PAIRS = [
  { label: 'public', php: 'index.html', props: 'inertia.json', out: 'vue.html', tables: 5 },
  { label: 'internal', php: 'apps.html', props: 'inertia-apps.json', out: 'vue-apps.html', tables: 6 },
  { label: 'empty', php: 'empty.html', props: 'inertia-empty.json', out: 'vue-empty.html', tables: 0 },
];

/** A problem with the inputs or the tools, not a difference between the pages: exit 2. */
class ToolError extends Error {}

/** Compile the SFC the way Vite does for production, strip its TypeScript, and import it. */
async function loadComponent() {
  const source = await readFile(COMPONENT, 'utf8');
  const { descriptor, errors } = parseSfc(source, { filename: COMPONENT });
  if (errors.length > 0) {
    throw new ToolError(`${COMPONENT} does not parse: ${errors.map(String).join('; ')}`);
  }

  const compiled = compileScript(descriptor, { id: 'rollcall-dashboard', inlineTemplate: true, isProd: true });
  const js = stripTypeScriptTypes(compiled.content, { mode: 'strip' });

  // The module is imported from a data: URL, which cannot resolve a bare
  // specifier, so point every "vue" import at the copy this tool runs.
  const vue = JSON.stringify(import.meta.resolve('vue'));
  const code = js.replace(/from\s*(['"])vue\1/g, `from ${vue}`);
  if (/from\s*['"](?!file:)/.test(code)) {
    throw new ToolError('the compiled component imports something other than vue; this check cannot load it');
  }

  const module = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

  return module.default;
}

/** Render the component to HTML; any Vue warning (a bad prop, a render error) fails the check. */
async function render(component, props) {
  const warnings = [];
  const app = createSSRApp(component, props);
  app.config.warnHandler = (message) => warnings.push(message);
  const html = await renderToString(app);
  if (warnings.length > 0) {
    throw new ToolError(`Vue warned while rendering: ${warnings.join('; ')}`);
  }

  return html;
}

/** The children of the PHP page's <main>. */
function phpMain(html, file) {
  const stack = [parseDocument(html)];
  while (stack.length > 0) {
    const node = stack.pop();
    if (node.tagName === 'main') {
      return node.childNodes;
    }
    stack.push(...(node.childNodes ?? []));
  }
  throw new ToolError(`${file} has no <main>`);
}

/** The PHP page's stylesheet link, so the Vue page loads the same one. */
function phpStylesheet(html) {
  const stack = [parseDocument(html)];
  while (stack.length > 0) {
    const node = stack.pop();
    if (node.tagName === 'link') {
      const attrs = Object.fromEntries(node.attrs.map((a) => [a.name, a.value]));
      if (attrs.rel === 'stylesheet' && attrs.href) {
        return attrs.href;
      }
    }
    stack.push(...(node.childNodes ?? []));
  }

  return null;
}

/**
 * One line per element and text run, indented by depth: the shape the two
 * sides must share. Comments go; adjacent text merges; whitespace collapses
 * to one space, is trimmed at the start and end of an element, and a run of
 * nothing but whitespace between two elements goes.
 */
function canonical(nodes, depth = 0, out = []) {
  const items = [];
  for (const node of nodes) {
    if (node.nodeName === '#comment') {
      continue;
    }
    if (node.nodeName === '#text') {
      const last = items[items.length - 1];
      if (last && last.text !== undefined) {
        last.text += node.value;
      } else {
        items.push({ text: node.value });
      }
      continue;
    }
    items.push({ node });
  }

  items.forEach((item, index) => {
    if (item.text === undefined) {
      return;
    }
    let text = item.text.replace(/\s+/g, ' ');
    if (index === 0) text = text.trimStart();
    if (index === items.length - 1) text = text.trimEnd();
    item.text = text.trim() === '' ? '' : text;
  });

  const pad = '  '.repeat(depth);
  for (const item of items) {
    if (item.text !== undefined) {
      if (item.text !== '') out.push(pad + JSON.stringify(item.text));
      continue;
    }
    const { node } = item;
    const attrs = [...node.attrs]
      .sort((a, b) => a.name.localeCompare(b.name))
      .map((a) => ` ${a.name}=${JSON.stringify(a.value)}`)
      .join('');
    out.push(`${pad}<${node.tagName}${attrs}>`);
    canonical(node.childNodes, depth + 1, out);
    out.push(`${pad}</${node.tagName}>`);
  }

  return out;
}

/** The first difference between two canonical forms, with a little context, or null. */
function difference(php, vue) {
  const length = Math.max(php.length, vue.length);
  for (let i = 0; i < length; i++) {
    if (php[i] !== vue[i]) {
      const from = Math.max(0, i - 3);
      const show = (lines) => lines.slice(from, i + 4).map((line, k) => `${from + k === i ? '>' : ' '} ${line ?? '(end)'}`).join('\n');

      return `first difference at line ${i + 1} of ${length}\nPHP (DashboardPage):\n${show(php)}\nVue (RollcallDashboard.vue):\n${show(vue)}`;
    }
  }

  return null;
}

function escapeAttribute(value) {
  return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

/** The Vue page as the accessibility gate loads it: the component inside a bare shell. */
function page(body, stylesheet) {
  const lines = [
    '<!DOCTYPE html>',
    '<html lang="en">',
    '<head>',
    '    <meta charset="utf-8">',
    '    <meta name="viewport" content="width=device-width, initial-scale=1">',
    '    <meta name="robots" content="noindex">',
    '    <title>ROLLCALL: fleet census (Vue)</title>',
  ];
  if (stylesheet) {
    lines.push(`    <link rel="stylesheet" href="${escapeAttribute(stylesheet)}">`);
  }
  lines.push('</head>', '<body>', '<a href="#main" class="wsu-skip-link">Skip to the report</a>', '<main id="main">', body, '</main>', '</body>', '</html>', '');

  return lines.join('\n');
}

async function readInput(dir, file) {
  try {
    return await readFile(path.join(dir, file), 'utf8');
  } catch {
    throw new ToolError(`${path.join(dir, file)} is missing: run DashboardRenderTest with ROLLCALL_DASHBOARD_OUT=${dir} first`);
  }
}

async function main() {
  const dir = process.argv[2];
  if (!dir) {
    throw new ToolError('usage: node tools/vue-dashboard/parity.mjs <dir>');
  }

  const component = await loadComponent();
  const seen = {};
  let failed = false;

  for (const pair of PAIRS) {
    const phpHtml = await readInput(dir, pair.php);
    let inertia;
    try {
      inertia = JSON.parse(await readInput(dir, pair.props));
    } catch (error) {
      throw error instanceof ToolError ? error : new ToolError(`${pair.props} is not JSON`);
    }
    if (inertia?.component !== 'Rollcall/Dashboard' || typeof inertia.props !== 'object') {
      throw new ToolError(`${pair.props} is not an Inertia page object for Rollcall/Dashboard`);
    }

    const { report, internal, scanCommand } = inertia.props;
    const vueHtml = await render(component, { report, internal, scanCommand });

    const php = canonical(phpMain(phpHtml, pair.php));
    const vue = canonical(parseFragment(vueHtml).childNodes);
    seen[pair.label] = php;

    // Guard against a check that passes because it compares nothing.
    const captions = php.filter((line) => line.trimStart().startsWith('<caption')).length;
    if (captions !== pair.tables || (pair.tables > 0 && !php.some((line) => line.includes('role="status"')))) {
      throw new ToolError(`${pair.php} has ${captions} tables, expected ${pair.tables} and a status line`);
    }

    const diff = difference(php, vue);
    if (diff) {
      failed = true;
      console.error(`FAIL ${pair.label}: RollcallDashboard.vue differs from ${pair.php}\n${diff}\n`);
      continue;
    }

    await writeFile(path.join(dir, pair.out), page(vueHtml, phpStylesheet(phpHtml)));
    console.log(`ok   ${pair.label}: RollcallDashboard.vue matches ${pair.php} (${php.length} lines), wrote ${pair.out}`);
  }

  if (difference(seen.public, seen.internal) === null) {
    throw new ToolError('the public and internal pages compare equal; the comparison is not looking at the page');
  }

  return failed ? 1 : 0;
}

main().then(
  (code) => process.exit(code),
  (error) => {
    console.error(error instanceof ToolError ? `error: ${error.message}` : error);
    process.exit(2);
  },
);
