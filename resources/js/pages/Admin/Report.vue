<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report as reportRoute } from '@/routes/admin';
import type { AdminReport } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Operator report',
                href: '/admin/report',
            },
        ],
    },
});

const props = defineProps<{
    report: AdminReport;
}>();

const filters = useForm({
    start_date: props.report.period.start_date,
    end_date: props.report.period.end_date,
    // Apply and the quick ranges ask the server to bypass its short usage cache.
    refresh: '1',
});

function apply(): void {
    filters.get(reportRoute.url(), {
        preserveScroll: true,
        preserveState: true,
    });
}

/** Quick windows are inclusive of today, matching Pixelforge's usage semantics. */
function setRange(days: number): void {
    const end = new Date();
    const start = new Date(end);
    start.setUTCDate(start.getUTCDate() - (days - 1));

    filters.start_date = start.toISOString().slice(0, 10);
    filters.end_date = end.toISOString().slice(0, 10);
    apply();
}

const reconciliationTone = computed(() => {
    if (!props.report.provider.available) {
        return 'warning';
    }

    return props.report.totals.provider_matches ? 'success' : 'warning';
});
</script>

<template>
    <Head title="Operator report" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Operator report</h1>
                <p class="text-sm text-muted-foreground">
                    What users were charged, what Pixelforge charged us, and the
                    profit — grouped by UTC completion day and by user.
                </p>
            </div>

            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="apply"
            >
                <div class="grid gap-1">
                    <Label for="start_date" class="text-xs">Start (UTC)</Label>
                    <Input
                        id="start_date"
                        v-model="filters.start_date"
                        type="date"
                        class="w-36"
                    />
                    <InputError :message="filters.errors.start_date" />
                </div>
                <div class="grid gap-1">
                    <Label for="end_date" class="text-xs">End (UTC)</Label>
                    <Input
                        id="end_date"
                        v-model="filters.end_date"
                        type="date"
                        class="w-36"
                    />
                    <InputError :message="filters.errors.end_date" />
                </div>
                <Button type="submit" :disabled="filters.processing">
                    Apply
                </Button>
            </form>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-muted-foreground">Quick range:</span>
            <Button
                v-for="days in [7, 30, 90]"
                :key="days"
                type="button"
                variant="outline"
                size="sm"
                @click="setRange(days)"
            >
                Last {{ days }} days
            </Button>
            <span class="ml-auto text-xs text-muted-foreground">
                {{ props.report.period.start_date }} to
                {{ props.report.period.end_date }} ·
                {{ props.report.period.days }} day{{
                    props.report.period.days === 1 ? '' : 's'
                }}
            </span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardContent class="pt-6">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Charged to users
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ props.report.totals.charged_display }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ props.report.totals.generations }} succeeded
                        generation{{
                            props.report.totals.generations === 1 ? '' : 's'
                        }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="pt-6">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Provider cost (ours)
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ props.report.totals.provider_cost_display }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        usage × stored rates
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="pt-6">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Profit
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ props.report.totals.profit_display }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        charged minus provider cost
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="pt-6">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Pixelforge reported
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ props.report.provider.total?.cost_display ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            props.report.provider.available
                                ? 'from GET /v1/usage'
                                : 'unavailable right now'
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Reconciliation is the operator's trust check: our own provider totals
             against Pixelforge's report for the exact same UTC window. -->
        <div
            class="rounded-lg border px-4 py-3 text-sm"
            :class="
                reconciliationTone === 'success'
                    ? 'border-emerald-600/30 bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
                    : 'border-amber-600/30 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100'
            "
        >
            <div v-if="props.report.provider.available">
                <p class="font-medium">
                    <template v-if="props.report.totals.provider_matches">
                        Provider costs match Pixelforge to the nanodollar.
                    </template>
                    <template v-else>
                        Provider costs do not reconcile with Pixelforge for
                        every day.
                        <template
                            v-if="
                                props.report.totals.difference_nano !== null &&
                                props.report.totals.difference_nano !== 0
                            "
                        >
                            Total difference:
                            {{ props.report.totals.difference_display }}.
                        </template>
                        See the day rows below.
                    </template>
                </p>
                <p class="mt-1 text-xs opacity-80">
                    Our records:
                    {{ props.report.totals.provider_cost_display }} ·
                    Pixelforge:
                    {{ props.report.totals.provider_reported_cost_display }} ·
                    {{ props.report.totals.provider_reported_generations }}
                    provider generation{{
                        props.report.totals.provider_reported_generations === 1
                            ? ''
                            : 's'
                    }}. The provider reports by UTC completion day, so a
                    generation still finishing can move the current day's
                    number.
                </p>
            </div>
            <div v-else>
                <p class="font-medium">Reconciliation unavailable.</p>
                <p class="mt-1 text-xs opacity-80">
                    {{ props.report.provider.error }} Local records are still
                    shown below.
                </p>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">By day (UTC)</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th class="py-2 pr-4 font-medium">Day</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Generations
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Charged
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Provider (ours)
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Provider (Pixelforge)
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Difference
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in props.report.days"
                                :key="day.date"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    {{ day.date }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ day.generations }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ day.charged_display }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ day.provider_cost_display }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{
                                        day.provider_reported_cost_display ??
                                        '—'
                                    }}
                                </td>
                                <td
                                    class="py-2 pr-4 text-right tabular-nums"
                                    :class="
                                        day.provider_reported_cost_display ===
                                        null
                                            ? 'text-muted-foreground'
                                            : day.matches
                                              ? 'text-muted-foreground'
                                              : 'font-medium text-amber-700 dark:text-amber-400'
                                    "
                                >
                                    {{ day.difference_display ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ day.profit_display }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t font-medium">
                                <td class="py-2 pr-4">Total</td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ props.report.totals.generations }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ props.report.totals.charged_display }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{
                                        props.report.totals
                                            .provider_cost_display
                                    }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{
                                        props.report.totals
                                            .provider_reported_cost_display ??
                                        '—'
                                    }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{
                                        props.report.totals
                                            .difference_display ?? '—'
                                    }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ props.report.totals.profit_display }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">By user</CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    v-if="props.report.users.length === 0"
                    class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    No succeeded generations in this range.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th class="py-2 pr-4 font-medium">User</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Generations
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Charged
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Provider cost
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in props.report.users"
                                :key="user.user_id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4">
                                    <p class="font-medium">{{ user.name }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ user.email }}
                                    </p>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ user.generations }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ user.charged_display }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ user.provider_cost_display }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ user.profit_display }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
