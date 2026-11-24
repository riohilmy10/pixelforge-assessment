<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { purchase as purchaseRoute } from '@/routes/credits';
import type { BalanceSummary, CreditPreset, CreditTransaction } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Credits',
                href: '/credits',
            },
        ],
    },
});

const props = defineProps<{
    balance: BalanceSummary;
    transactions: CreditTransaction[];
    presets: CreditPreset[];
}>();

const page = usePage();
const flash = computed(
    () => page.props.flash as { status?: string | null } | undefined,
);

const form = useForm({
    amount_nano: props.presets[0]?.amount_nano ?? 0,
});

/**
 * One fake top-up per click. The server accepts only preset amounts, so this form is a
 * convenience: the real validation lives in `PurchaseCreditsRequest`.
 */
function buy(amountNano: number): void {
    if (form.processing) {
        return;
    }

    form.amount_nano = amountNano;
    form.post(purchaseRoute.url(), { preserveScroll: true });
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '';
}
</script>

<template>
    <Head title="Credits" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <div
            v-if="flash?.status"
            class="rounded-md border border-emerald-600/30 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
        >
            {{ flash.status }}
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
                <CardHeader>
                    <CardTitle>Buy credits</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p class="text-sm text-muted-foreground">
                        Credits are US dollars. This is a simulated purchase for
                        the demo — no payment is taken, and the credits are
                        available immediately.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="preset in props.presets"
                            :key="preset.amount_nano"
                            type="button"
                            :disabled="form.processing"
                            @click="buy(preset.amount_nano)"
                        >
                            Add {{ preset.display }}
                        </Button>
                    </div>

                    <InputError :message="form.errors.amount_nano" />
                </CardContent>
            </Card>

            <Card class="h-fit">
                <CardHeader>
                    <CardTitle class="text-base">Your balance</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div>
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Available to spend
                        </p>
                        <p class="text-3xl font-semibold tabular-nums">
                            {{ props.balance.available_display }}
                        </p>
                    </div>

                    <dl class="space-y-1 border-t pt-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Owned</dt>
                            <dd class="tabular-nums">
                                {{ props.balance.credit_balance_display }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                Held for in-progress generations
                            </dt>
                            <dd class="tabular-nums">
                                {{ props.balance.reserved_display }}
                            </dd>
                        </div>
                    </dl>

                    <p class="border-t pt-3 text-xs text-muted-foreground">
                        We hold the worst-case quote while a generation runs,
                        then charge the exact final cost when it succeeds. A
                        failed generation releases the hold and is free.
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Ledger</CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    v-if="props.transactions.length === 0"
                    class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    No credit activity yet. Add credits above to start
                    generating.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th class="py-2 pr-4 font-medium">When</th>
                                <th class="py-2 pr-4 font-medium">Activity</th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Amount
                                </th>
                                <th class="py-2 pr-4 text-right font-medium">
                                    Available after
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="transaction in props.transactions"
                                :key="transaction.id"
                                class="border-b last:border-0"
                            >
                                <td
                                    class="py-2 pr-4 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ formatDate(transaction.created_at) }}
                                </td>
                                <td class="py-2 pr-4">
                                    <p class="font-medium">
                                        {{ transaction.label }}
                                    </p>
                                    <p
                                        v-if="transaction.description"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ transaction.description }}
                                    </p>
                                </td>
                                <td
                                    class="py-2 pr-4 text-right font-medium whitespace-nowrap tabular-nums"
                                    :class="
                                        transaction.amount_nano >= 0
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-destructive'
                                    "
                                >
                                    {{ transaction.amount_display }}
                                </td>
                                <td
                                    class="py-2 pr-4 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ transaction.balance_after_display }}
                                    <p
                                        v-if="
                                            transaction.reserved_after_display !==
                                            '$0'
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ transaction.reserved_after_display }}
                                        held
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
