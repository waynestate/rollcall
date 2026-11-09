<script setup lang="ts">
/**
 * The Rollcall dashboard as a Vue component: the third room the roll is read
 * out in, after the Laravel view and the raw-PHP page.
 *
 * It renders what Waynestate\Rollcall\Dashboard\DashboardPage renders inside
 * <main>, element for element: the same WayneUI classes (wsu-stat, wsu-table,
 * wsu-table-wrap), the same role="status" line, the same captions and headers,
 * the same words for every state. The parity check in Rollcall's CI
 * (tools/vue-dashboard/parity.mjs) server-renders this file with the props the
 * Laravel adapter sends and fails on any difference from the PHP page.
 *
 * Its props are the report DashboardPage::report() builds, untouched; no
 * number is worked out here, only put into words. It has no style block, no
 * style attribute and no raw-HTML binding, so it sits under HELMET's CSP as is. The
 * layout around it (skip link, <main id="main">, the page title) belongs to
 * the app: see resources/js/pages/Rollcall/Dashboard.vue for WELD's.
 */
import { computed } from 'vue';

type Report = Record<string, unknown>;

interface Table {
    key: string;
    title: string;
    caption: string;
    columns: string[];
    rows: string[][];
}

const props = withDefaults(
    defineProps<{
        /** What DashboardPage::report() returned: null when no scan is stored yet. */
        report: Report | null;
        /** True on the per-app page, which the adapter serves only behind sign-in. */
        internal?: boolean;
        /** The command the empty state names. */
        scanCommand?: string;
    }>(),
    { internal: false, scanCommand: 'php artisan rollcall:scan' },
);

/** The version tables, in page order: report key => title (DashboardPage::VERSION_TABLES). */
const VERSION_TABLES: [string, string][] = [
    ['php_image', 'PHP image'],
    ['php_floor', 'Declared PHP floor'],
    ['laravel', 'Laravel'],
];

/** PHP's is_scalar(). */
function isScalar(value: unknown): value is string | number | boolean {
    return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean';
}

/** PHP's (string) cast of a scalar: true is "1", false is "". */
function str(value: string | number | boolean): string {
    return typeof value === 'boolean' ? (value ? '1' : '') : String(value);
}

/** The value as text, or nothing (DashboardPage::e()). */
function e(value: unknown): string {
    return isScalar(value) ? str(value) : '';
}

/** The value as text, or the fallback when it is missing (DashboardPage::text()). */
function text(value: unknown, fallback: string): string {
    return isScalar(value) ? str(value) : fallback;
}

/** A state or drift kind in words: security_only reads "security only". */
function words(value: unknown): string {
    return text(value, '').replaceAll('_', ' ');
}

/** PHP's (bool) on a scalar: "", "0", 0 and false are false. */
function truthy(value: string | number | boolean): boolean {
    return typeof value === 'string' ? value !== '' && value !== '0' : Boolean(value);
}

function obj(value: unknown): Report {
    return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Report) : {};
}

/** A PHP array as key/value pairs, whether JSON made it a list or an object. */
function entries(value: unknown): [string, unknown][] {
    if (Array.isArray(value)) {
        return value.map((item, index): [string, unknown] => [String(index), item]);
    }

    return Object.entries(obj(value));
}

function list(value: unknown): unknown[] {
    return entries(value).map(([, item]) => item);
}

const totals = computed(() => obj(props.report?.totals));
const progress = computed(() => obj(props.report?.progress));
const scanDate = computed(() => e(props.report?.scan_date));
const asOf = computed(() => e(props.report?.as_of));

const status = computed(
    () =>
        `Showing ${e(totals.value.apps)} apps from the scan of ${scanDate.value}.` +
        (props.internal ? ' This page lists every app; keep it inside the signed-in app.' : ''),
);

const stats = computed((): [string, string][] => [
    ['Apps present', e(totals.value.apps)],
    [`Apps that moved since ${text(progress.value.since, 'the first scan')}`, e(progress.value.moved)],
    ['Apps upgraded from an EOL image to a supported one', e(progress.value.upgraded_to_supported)],
]);

const tables = computed((): Table[] => {
    const report = props.report ?? {};
    const out: Table[] = VERSION_TABLES.map(([key, title]) => ({
        key,
        title,
        caption: `${title}: apps by version, with the state the policy gives it today`,
        columns: ['Version', 'Apps', 'State', 'Security support ends', 'Days left'],
        rows: list(report[key]).map((item) => {
            const row = obj(item);

            return [
                e(row.version),
                e(row.apps),
                words(row.state),
                text(row.security_until, 'not in the policy'),
                text(row.days_to_eol, 'unknown'),
            ];
        }),
    }));

    out.push({
        key: 'drift',
        title: 'Drift',
        caption: 'Apps showing each kind of drift',
        columns: ['Kind', 'Apps'],
        rows: entries(report.drift).map(([kind, count]) => [words(kind), e(count)]),
    });

    out.push({
        key: 'burn',
        title: 'EOL burn-down',
        caption: 'Apps on an EOL PHP image, by scan day',
        columns: ['Scan day', 'Apps', 'EOL', 'Supported', 'Unknown'],
        rows: list(report.burn_down).map((item) => {
            const point = obj(item);

            return [e(point.date), e(point.apps), e(point.eol), e(point.supported), e(point.unknown)];
        }),
    });

    if (props.internal) {
        out.push({
            key: 'apps',
            title: 'Apps',
            caption: "Each app's versions, PROCTOR result and drift",
            columns: ['App', 'PHP image', 'PHP (running)', 'httpd (running)', 'Image state', 'PHP floor', 'Laravel', 'Auth package', 'PROCTOR', 'Drift'],
            rows: list(report.apps).map((item) => {
                const app = obj(item);
                const drift = list(app.drift).map(words);
                const version = app.auth_version;
                const auth = text(app.auth_package, 'none') + (isScalar(version) && truthy(version) ? ` ${str(version)}` : '');

                return [
                    e(app.id),
                    text(app.php_image, 'unknown'),
                    // The runtime versions stay blank until the image source reports them.
                    e(app.runtime_php),
                    e(app.runtime_httpd),
                    words(app.image_state),
                    text(app.php_floor, 'unbounded'),
                    text(app.laravel, 'none'),
                    auth,
                    text(app.proctor, 'no report'),
                    drift.length === 0 ? 'none' : drift.join(', '),
                ];
            }),
        });
    }

    return out;
});
</script>

<template>
    <h1>ROLLCALL</h1>

    <p v-if="report === null">
        No scan has been stored yet. Run <code>{{ scanCommand }}</code> and reload.
    </p>

    <template v-else>
        <p>
            Scan of {{ scanDate }}, read against the policy as of {{ asOf }}.
            <strong v-if="internal">Internal view: do not publish.</strong>
        </p>
        <p id="rc-status" role="status">{{ status }}</p>

        <section aria-labelledby="totals">
            <h2 id="totals">Apps present</h2>
            <div v-for="[label, value] in stats" :key="label" class="wsu-stat">
                <span class="wsu-stat__label">{{ label }}</span><span class="wsu-stat__value">{{ value }}</span>
            </div>
        </section>

        <!-- Each table sits in its own keyboard-reachable scroll region (WCAG 1.4.10, 2.1.1). -->
        <section v-for="table in tables" :key="table.key" :aria-labelledby="`h-${table.key}`">
            <h2 :id="`h-${table.key}`">{{ table.title }}</h2>
            <div class="wsu-table-wrap" tabindex="0" role="region" :aria-labelledby="`cap-${table.key}`">
                <table class="wsu-table wsu-table--zebra">
                    <caption :id="`cap-${table.key}`">{{ table.caption }}</caption>
                    <thead>
                        <tr>
                            <th v-for="column in table.columns" :key="column" scope="col">{{ column }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in table.rows" :key="index">
                            <th scope="row">{{ row[0] }}</th>
                            <td v-for="(cell, column) in row.slice(1)" :key="column">{{ cell }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </template>
</template>
