<script setup lang="ts">
import { Maximize2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types';

/**
 * One generation row: status, prompt, image, and the cost promise vs. the final charge.
 * The same component is used on the dashboard and the history page so both tell the
 * same story about money.
 */
const props = defineProps<{
    generation: Generation;
}>();

/** `pending` exists only before the queued job has reached Pixelforge. */
const isActive = computed(() => props.generation.is_active);

/**
 * Pixelforge terminal states map to badge styles: succeeded is quiet green, failed is
 * destructive, in-flight work is neutral.
 */
const badgeVariant = computed<
    'default' | 'secondary' | 'destructive' | 'outline'
>(() => {
    switch (props.generation.status) {
        case 'failed':
            return 'destructive';
        case 'succeeded':
            return 'outline';
        default:
            return 'secondary';
    }
});

const statusLabel = computed(
    () =>
        props.generation.status.charAt(0).toUpperCase() +
        props.generation.status.slice(1),
);

/**
 * A rough but honest progress indicator. Pixelforge does not report a percentage, so
 * the bar marks the stages we do know about and never pretends processing is done
 * before the terminal state arrives.
 */
const progress = computed(() => {
    switch (props.generation.status) {
        case 'pending':
            return 10;
        case 'queued':
            return 30;
        case 'processing':
            return 70;
        case 'succeeded':
        case 'failed':
            return 100;
        default:
            return 0;
    }
});

const progressLabel = computed(() => {
    switch (props.generation.status) {
        case 'pending':
            return 'Queued on this server';
        case 'queued':
            return 'Waiting for Pixelforge';
        case 'processing':
            return 'Generating your image';
        default:
            return '';
    }
});

/** Only long prompts get the read-more toggle; short ones stay fully visible. */
const isLongPrompt = computed(
    () =>
        props.generation.prompt.length > 240 ||
        props.generation.prompt.split('\n').length > 3,
);

const cardPromptExpanded = ref(false);
const dialogPromptExpanded = ref(false);

const createdAt = computed(() => {
    if (!props.generation.created_at) {
        return '';
    }

    return new Date(props.generation.created_at).toLocaleString();
});

/**
 * The money sentence shown under each generation. Failed generations are explicitly
 * free; in-flight ones show the worst-case quote because that is what the user agreed to.
 */
const costText = computed(() => {
    const generation = props.generation;

    if (generation.status === 'succeeded' && generation.actual_cost_display) {
        return {
            primary: `${generation.actual_cost_display} charged`,
            secondary: `estimated up to ${generation.estimated_cost_display}`,
        };
    }

    if (generation.status === 'failed') {
        return {
            primary: 'No charge',
            secondary: `estimated up to ${generation.estimated_cost_display}`,
        };
    }

    return {
        primary: `Up to ${generation.estimated_cost_display}`,
        secondary: 'final price when it finishes',
    };
});
</script>

<template>
    <Card class="overflow-hidden">
        <CardContent class="flex gap-4 p-4">
            <!-- Image: provider URL or our stored copy. While processing, a skeleton
                 holds the aspect ratio so rows don't jump when the image arrives. -->
            <div
                class="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted"
            >
                <Dialog v-if="generation.image_url">
                    <DialogTrigger as-child>
                        <button
                            type="button"
                            class="group relative size-full cursor-zoom-in focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="`Open image for: ${generation.prompt}`"
                        >
                            <img
                                :src="generation.image_url"
                                :alt="generation.prompt"
                                class="size-full object-cover transition duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                            <span
                                class="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition duration-200 group-hover:bg-black/40 group-hover:opacity-100"
                            >
                                <Maximize2 class="size-5 text-white" />
                            </span>
                        </button>
                    </DialogTrigger>
                    <DialogContent
                        class="gap-0 overflow-hidden p-0 sm:max-w-3xl"
                    >
                        <DialogTitle class="sr-only">
                            Generated image
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            {{ generation.prompt }}
                        </DialogDescription>
                        <div
                            class="flex h-[min(55vh,34rem)] items-center justify-center bg-black"
                        >
                            <img
                                :src="generation.image_url"
                                :alt="generation.prompt"
                                class="max-h-full w-full object-contain"
                            />
                        </div>
                        <div class="flex h-44 flex-col gap-2 border-t p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p
                                    class="text-xs leading-5 text-muted-foreground"
                                >
                                    {{ generation.size }} ·
                                    {{ generation.quality }} · {{ createdAt }}
                                </p>
                                <Button
                                    v-if="isLongPrompt"
                                    type="button"
                                    variant="link"
                                    size="sm"
                                    class="h-5 shrink-0 px-0 text-xs"
                                    :aria-expanded="dialogPromptExpanded"
                                    @click="
                                        dialogPromptExpanded =
                                            !dialogPromptExpanded
                                    "
                                >
                                    {{
                                        dialogPromptExpanded
                                            ? 'Show less'
                                            : 'Read more'
                                    }}
                                </Button>
                            </div>
                            <!-- Fixed-height prompt box: expanding only scrolls the
                                 text, so the image above never changes size or moves. -->
                            <div
                                class="min-h-0 flex-1 rounded-md border bg-muted/30 px-3 py-2"
                                :class="
                                    dialogPromptExpanded
                                        ? 'overflow-y-auto'
                                        : 'overflow-hidden'
                                "
                            >
                                <p
                                    class="text-sm break-words whitespace-pre-wrap"
                                    :class="
                                        dialogPromptExpanded
                                            ? ''
                                            : 'line-clamp-3'
                                    "
                                >
                                    {{ generation.prompt }}
                                </p>
                            </div>
                        </div>
                    </DialogContent>
                </Dialog>
                <Skeleton v-else-if="isActive" class="size-full" />
                <span
                    v-else
                    class="px-2 text-center text-xs text-muted-foreground"
                >
                    No image
                </span>
            </div>

            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="badgeVariant">{{ statusLabel }}</Badge>
                    <span class="text-xs text-muted-foreground">
                        {{ generation.size }} · {{ generation.quality }}
                    </span>
                    <span
                        v-if="generation.reference_images.length"
                        class="text-xs text-muted-foreground"
                    >
                        · {{ generation.reference_images.length }} reference{{
                            generation.reference_images.length === 1 ? '' : 's'
                        }}
                    </span>
                    <span class="ml-auto text-xs text-muted-foreground">{{
                        createdAt
                    }}</span>
                </div>

                <div class="space-y-1">
                    <div
                        :class="
                            cardPromptExpanded ? 'max-h-40 overflow-y-auto' : ''
                        "
                    >
                        <p
                            class="text-sm break-words whitespace-pre-wrap"
                            :class="cardPromptExpanded ? '' : 'line-clamp-2'"
                        >
                            {{ generation.prompt }}
                        </p>
                    </div>
                    <Button
                        v-if="isLongPrompt"
                        type="button"
                        variant="link"
                        size="sm"
                        class="h-auto px-0 text-xs"
                        :aria-expanded="cardPromptExpanded"
                        @click="cardPromptExpanded = !cardPromptExpanded"
                    >
                        {{ cardPromptExpanded ? 'Show less' : 'Read more' }}
                    </Button>
                </div>

                <!-- Stage bar while the provider works. The width only advances on
                     status changes; polling supplies those every few seconds. -->
                <div
                    v-if="isActive"
                    class="space-y-1.5 pt-1"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="progress"
                    :aria-label="progressLabel"
                >
                    <div
                        class="flex items-center justify-between text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1.5 font-medium">
                            <Spinner class="size-3 text-emerald-600" />
                            {{ progressLabel }}
                        </span>
                        <span class="tabular-nums">{{ progress }}%</span>
                    </div>
                    <div
                        class="relative h-1.5 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="relative h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-400 transition-[width] duration-700 ease-out"
                            :style="{ width: `${progress}%` }"
                        >
                            <span
                                class="absolute inset-0 animate-pulse bg-white/30"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                    <span class="font-medium">{{ costText.primary }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        costText.secondary
                    }}</span>
                </div>

                <!-- Failures say what happened *and* that they were free. -->
                <p
                    v-if="generation.error_message"
                    class="text-sm text-destructive"
                >
                    {{ generation.error_message }}
                </p>
            </div>
        </CardContent>
    </Card>
</template>
