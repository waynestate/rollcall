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
        <RollcallDashboard :report="report" :internal="internal" :scan-command="scanCommand" />
    </AppLayout>
</template>
