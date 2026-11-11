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

/** A table cell: plain words, or a state that needs a person, shown in its badge. */
type Cell = string | { badge: string };

interface Table {
    key: string;
    title: string;
    caption: string;
    columns: string[];
    rows: [string, ...Cell[]][];
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

/** The MySQL 5.6 CI job states, in page order: report key => label (DashboardPage::MYSQL56_STATES). */
const MYSQL56_STATES: [string, string][] = [
    ['held', 'held'],
    ['missing', 'missing'],
    ['not_applicable', 'not applicable'],
    ['unknown', 'unknown'],
];

/** The one state that needs a person: an app with migrations and no MySQL 5.6 job (DashboardPage::MYSQL56_BAD). */
const MYSQL56_BAD = 'missing';

/** Booster's hop results, in page order: report key => label (DashboardPage::BOOSTER_RESULTS). */
const BOOSTER_RESULTS: [string, string][] = [
    ['pass', 'pass'],
    ['fail', 'fail'],
    ['none', 'no record'],
];

/** The one result that needs a person: the app's last upgrade run did not land (DashboardPage::BOOSTER_BAD). */
const BOOSTER_BAD = 'fail';

/** What a row's Citadel date says once it is the report's day or earlier (DashboardPage::DUE_COME). */
const DUE_COME = 'date has come';

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

/** An app's MySQL 5.6 CI job state in words; "missing" in its badge (DashboardPage::mysql56State()). */
function mysql56State(value: unknown): Cell {
    const state = words(text(value, 'unknown'));

    return state === MYSQL56_BAD ? { badge: state } : state;
}

/** An app's Booster hop result: "no record" without one, and "fail" in its badge (DashboardPage::boosterResult()). */
function boosterResult(value: unknown): Cell {
    const result = text(value, 'no record');

    return result === BOOSTER_BAD ? { badge: result } : result;
}

/**
 * An app's forced upgrade date: "none" without one, and in its badge once the report's
 * day has reached it, the rule behind the report's overdue count (DashboardPage::upgradeDue()).
 * Dates are YYYY-MM-DD, so comparing them as strings is comparing the days.
 */
function upgradeDue(due: unknown, asOf: unknown): Cell {
    if (typeof due !== 'string' || due === '') {
        return 'none';
    }

    return typeof asOf === 'string' && asOf !== '' && due <= asOf ? { badge: `${due}, ${DUE_COME}` } : due;
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

/** Citadel's forced image upgrades, counted: [label, value, wears the badge]. "Due today or earlier" does while any is. */
const forced = computed((): [string, string, boolean][] => {
    const counts = obj(props.report?.forced_upgrades);
    const overdue = counts.overdue;

    return [
        ['Apps with an upgrade pending', e(counts.pending), false],
        ['Due today or earlier', e(overdue), typeof overdue === 'number' && Number.isInteger(overdue) && overdue > 0],
        ['Next date ahead', text(counts.next_due, 'none'), false],
    ];
});

/** CREED's database rule, counted: [label, count, wears the badge]. "missing" does while any app is missing the job. */
const mysql56 = computed((): [string, string, boolean][] => {
    const counts = obj(props.report?.mysql56_ci);

    return MYSQL56_STATES.map(([key, label]) => {
        const count = counts[key];

        return [label, e(count), key === MYSQL56_BAD && typeof count === 'number' && Number.isInteger(count) && count > 0];
    });
});

/** Booster's hop records, counted: [label, count, wears the badge]. "fail" does while any app's last hop failed. */
const booster = computed((): [string, string, boolean][] => {
    const counts = obj(props.report?.booster);

    return BOOSTER_RESULTS.map(([key, label]) => {
        const count = counts[key];

        return [label, e(count), key === BOOSTER_BAD && typeof count === 'number' && Number.isInteger(count) && count > 0];
    });
});

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
            caption: "Each app's versions, Citadel upgrade date, PROCTOR result, MySQL 5.6 CI job, Booster hop and drift",
            columns: ['App', 'PHP image', 'PHP (running)', 'httpd (running)', 'Image state', 'Upgrade due', 'PHP floor', 'Laravel', 'Auth package', 'PROCTOR', 'MySQL 5.6 CI', 'Booster', 'Drift'],
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
                    upgradeDue(app.upgrade_due, report.as_of),
                    text(app.php_floor, 'unbounded'),
                    text(app.laravel, 'none'),
                    auth,
                    text(app.proctor, 'no report'),
                    mysql56State(app.mysql56_ci),
                    boosterResult(app.booster),
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

        <section aria-labelledby="h-forced">
            <h2 id="h-forced">Forced image upgrades</h2>
            <p>
                On each date in Citadel's registrations export, Citadel moves an app to a newer base image, ready or not. The
                counts come from that export, so with any other image source they stay at zero.
            </p>
            <div v-for="[label, value, warn] in forced" :key="label" class="wsu-stat">
                <span class="wsu-stat__label"
                    ><span v-if="warn" class="wsu-badge wsu-badge--danger">{{ label }}</span
                    ><template v-else>{{ label }}</template></span
                ><span class="wsu-stat__value">{{ value }}</span>
            </div>
        </section>

        <section aria-labelledby="h-mysql56">
            <h2 id="h-mysql56">MySQL 5.6 CI job</h2>
            <p>
                CREED says migrations run in CI against MySQL 5.6.51, the version of Citadel's shared server; PROCTOR reports
                which apps hold the job. Unknown means no PROCTOR report, or one older than 0.15.1.
            </p>
            <div v-for="[label, value, warn] in mysql56" :key="label" class="wsu-stat">
                <span class="wsu-stat__label"
                    ><span v-if="warn" class="wsu-badge wsu-badge--danger">{{ label }}</span
                    ><template v-else>{{ label }}</template></span
                ><span class="wsu-stat__value">{{ value }}</span>
            </div>
        </section>

        <section aria-labelledby="h-booster">
            <h2 id="h-booster">Booster hop record</h2>
            <p>
                Booster writes booster-hop.json when an upgrade run finishes; an app that commits it shows how its last hop
                ended. No record means the app has none, or one that could not be read.
            </p>
            <div v-for="[label, value, warn] in booster" :key="label" class="wsu-stat">
                <span class="wsu-stat__label"
                    ><span v-if="warn" class="wsu-badge wsu-badge--danger">{{ label }}</span
                    ><template v-else>{{ label }}</template></span
                ><span class="wsu-stat__value">{{ value }}</span>
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
                            <td v-for="(cell, column) in row.slice(1)" :key="column">
                                <span v-if="typeof cell === 'object'" class="wsu-badge wsu-badge--danger">{{ cell.badge }}</span
                                ><template v-else>{{ cell }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </template>
</template>
