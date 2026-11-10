<script setup lang="ts">
/**
 * Rollcall's dashboard as one of this app's own Inertia pages, inside its own layout.
 *
 * `php artisan vendor:publish --tag=rollcall-inertia` copied this file to
 * resources/js/pages/Rollcall/Dashboard.vue, and it is yours now: the layout, the
 * breadcrumbs and the title are this app's to change. It is written for a WELD-shaped
 * app (AppLayout, @/types); another layout is a one-line swap.
 *
 * The markup is not copied. It comes straight from the Composer package, so a
 * `composer update` keeps it in step with the Blade and raw-PHP pages, which print
 * the same report in the same words. Turn the page on with ROLLCALL_DASHBOARD_ENABLED
 * and ROLLCALL_DASHBOARD_INERTIA, then build your assets.
 */
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import RollcallDashboard from '../../../../vendor/waynestate/rollcall/resources/js/RollcallDashboard.vue';

defineProps<{
    report: Record<string, unknown> | null;
    internal: boolean;
    scanCommand: string;
}>();

const page = usePage();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Rollcall', href: page.url.split(/[?#]/)[0] },
];
</script>

<template>
    <Head title="Rollcall">
        <!-- The fleet census is for the people who run the fleet, not for search engines. -->
        <meta name="robots" content="noindex" />
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="rollcall-page wsu-stack">
            <RollcallDashboard :report="report" :internal="internal" :scan-command="scanCommand" />
        </div>
    </AppLayout>
</template>

<style scoped>
/*
 * Spacing for the report inside this app's layout, in WayneUI's tokens. The
 * component carries no styles of its own (it prints the PHP page's markup),
 * so the room it sits in sets the rhythm. Vite bundles this into the app's
 * stylesheet, so it needs no CSP nonce.
 */
.rollcall-page :deep(section) {
    display: flex;
    flex-direction: column;
    gap: var(--wsu-space-3);
    margin-block-start: var(--wsu-space-8);
}

/*
 * The totals, the forced upgrade counts and the MySQL 5.6 CI job counts sit
 * side by side when there is room, one per row on a phone. Their heading and
 * note span the row.
 */
.rollcall-page :deep(section[aria-labelledby='totals']),
.rollcall-page :deep(section[aria-labelledby='h-forced']),
.rollcall-page :deep(section[aria-labelledby='h-mysql56']) {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
}

.rollcall-page :deep(section[aria-labelledby='totals'] > h2),
.rollcall-page :deep(section[aria-labelledby='h-forced'] > :is(h2, p)),
.rollcall-page :deep(section[aria-labelledby='h-mysql56'] > :is(h2, p)) {
    grid-column: 1 / -1;
}
</style>
