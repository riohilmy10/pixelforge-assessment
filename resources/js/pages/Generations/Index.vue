<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'History',
                href: '/generations',
            },
        ],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
}>();

/**
 * Refresh the table while any generation on this page is still moving. This is the
 * fallback UX mirror of the backend poller: even if a webhook is delayed, the user sees
 * the final state once the server has reconciled it.
 */
const hasActive = computed(() =>
    props.generations.data.some((generation) => generation.is_active),
);

const poll = usePoll(
    4000,
    { only: ['generations'] },
    { mode: 'rest', background: 'pause', autoStart: false },
);

watch(
    hasActive,
    (active) => {
        if (active) {
            poll.start();
        } else {
            poll.stop();
        }
    },
    { immediate: true },
);
</script>

<template>
    <Head title="History" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Generation history</h1>
                <p class="text-sm text-muted-foreground">
                    {{ props.generations.total }} generation{{
                        props.generations.total === 1 ? '' : 's'
                    }}
                </p>
            </div>
            <Button as-child variant="outline">
                <Link href="/dashboard">New generation</Link>
            </Button>
        </div>

        <div
            v-if="props.generations.data.length === 0"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No generations yet.
        </div>

        <div class="space-y-3">
            <GenerationCard
                v-for="generation in props.generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <!-- Inertia's paginator links are plain URLs; preserve them exactly so filters
             (when added later) survive page changes. -->
        <nav
            v-if="props.generations.last_page > 1"
            class="flex flex-wrap items-center gap-1"
            aria-label="Pagination"
        >
            <template
                v-for="(link, index) in props.generations.links"
                :key="index"
            >
                <Button
                    v-if="link.url"
                    as-child
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                >
                    <Link :href="link.url" preserve-scroll>
                        <span v-html="link.label" />
                    </Link>
                </Button>
                <Button v-else variant="ghost" size="sm" disabled>
                    <span v-html="link.label" />
                </Button>
            </template>
        </nav>
    </div>
</template>
