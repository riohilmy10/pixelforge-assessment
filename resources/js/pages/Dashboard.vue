<script setup lang="ts">
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import ReferenceImagesInput from '@/components/ReferenceImagesInput.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    estimate as estimateRoute,
    index as historyRoute,
    store as storeRoute,
} from '@/routes/generations';
import type { Estimate, Generation, GenerationOptions } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: '/dashboard',
            },
        ],
    },
});

const props = defineProps<{
    generations: Generation[];
    options: GenerationOptions;
}>();

/**
 * The generator form. Reference images start with one empty row so the repeater has a
 * place to type, but blanks are stripped before submit and before quoting.
 */
const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [''] as string[],
});

const quote = ref<Estimate | null>(null);
const quoting = ref(false);
const quoteError = ref<string | null>(null);
let quoteTimer: number | undefined;
let quoteController: AbortController | null = null;

/** Server feedback: queued confirmation, or "pricing unavailable, nothing submitted". */
const page = usePage();
const flash = computed(
    () => page.props.flash as { status?: string | null; error?: string | null },
);

/** Blanks are not reference images and must not count towards the 765-token charge. */
const references = computed(() =>
    form.reference_images.map((url) => url.trim()).filter(Boolean),
);

/**
 * Laravel's CSRF middleware accepts the XSRF-TOKEN cookie value in this header. The
 * quote is a plain fetch (not an Inertia visit), so it must send the token itself.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Fetch the live quote with a 300 ms debounce. POST keeps a 4,000-character prompt out
 * of the URL. Wayfinder builds the endpoint, so the route stays in sync with the backend.
 * The server uses the same estimate code that the submit flow snapshots, which is why
 * the number shown here matches the stored promise.
 */
async function refreshQuote(): Promise<void> {
    quoteController?.abort();
    quoteController = new AbortController();
    quoting.value = true;
    quoteError.value = null;

    const url = estimateRoute.url();

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            signal: quoteController.signal,
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: references.value,
            }),
        });

        if (!response.ok) {
            throw new Error(`Estimate failed (${response.status})`);
        }

        const data = (await response.json()) as { estimate: Estimate };
        quote.value = data.estimate;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            quoteError.value =
                'Could not load the price. Try again in a moment.';
        }
    } finally {
        quoting.value = false;
    }
}

/**
 * Re-quote whenever an input that affects token counts changes. Joining the URL list
 * gives Vue one stable scalar to watch while still reacting to add/remove/edit.
 */
watch(
    () => [
        form.prompt,
        form.size,
        form.quality,
        form.reference_images.join('\u0000'),
    ],
    () => {
        window.clearTimeout(quoteTimer);
        quoteTimer = window.setTimeout(() => void refreshQuote(), 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    window.clearTimeout(quoteTimer);
    quoteController?.abort();
});

/**
 * Submit only validated fields. The backend re-filters blanks, but doing it here keeps
 * the POST body identical to what the quote counted.
 */
function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images
            .map((url) => url.trim())
            .filter(Boolean),
    }));

    form.post(storeRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'reference_images');
            form.reference_images = [''];
        },
    });
}

/** Only poll while something can still change; idle dashboards sleep. */
const hasActive = computed(() =>
    props.generations.some((generation) => generation.is_active),
);

const poll = usePoll(
    3000,
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
    <Head title="Generate" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <div
            v-if="flash?.status"
            class="rounded-md border border-emerald-600/30 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
        >
            {{ flash.status }}
        </div>
        <div
            v-if="flash?.error"
            class="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            {{ flash.error }}
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
                <CardHeader>
                    <CardTitle>Generate an image</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="space-y-5" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <div class="flex items-center justify-between">
                                <Label for="prompt">Prompt</Label>
                                <span class="text-xs text-muted-foreground">
                                    {{ form.prompt.length }} /
                                    {{ options.prompt_max }}
                                </span>
                            </div>
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                :maxlength="options.prompt_max"
                                rows="4"
                                required
                                placeholder="A lighthouse on a cliff at dawn, oil painting"
                                class="min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError :message="form.errors.prompt" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label>Size</Label>
                                <Select v-model="form.size">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="size in options.sizes"
                                            :key="size"
                                            :value="size"
                                        >
                                            {{ size }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.size" />
                            </div>

                            <div class="grid gap-2">
                                <Label>Quality</Label>
                                <Select v-model="form.quality">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="quality in options.qualities"
                                            :key="quality"
                                            :value="quality"
                                        >
                                            {{ quality }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.quality" />
                            </div>
                        </div>

                        <ReferenceImagesInput
                            v-model="form.reference_images"
                            :error="form.errors.reference_images"
                            :max="options.reference_images_max"
                        />

                        <Button
                            type="submit"
                            :disabled="form.processing || quoting"
                        >
                            {{ form.processing ? 'Submitting…' : 'Generate' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <!-- Money panel: the worst case is prominent because that is the promise,
                 while the typical figure explains what most generations cost. -->
            <Card class="h-fit">
                <CardHeader>
                    <CardTitle class="text-base">Price estimate</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="quoteError" class="text-sm text-destructive">
                        {{ quoteError }}
                    </div>

                    <div
                        v-else-if="!quote"
                        class="text-sm text-muted-foreground"
                    >
                        Calculating…
                    </div>

                    <template v-else>
                        <div>
                            <p
                                class="text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                You pay at most
                            </p>
                            <p class="text-3xl font-semibold">
                                {{ quote.charged.max_display }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                usually about
                                {{ quote.charged.typical_display }}
                            </p>
                        </div>

                        <dl
                            class="space-y-1 border-t pt-3 text-xs text-muted-foreground"
                        >
                            <div class="flex justify-between">
                                <dt>Input text</dt>
                                <dd>
                                    {{ quote.text_tokens.typical }}–{{
                                        quote.text_tokens.max
                                    }}
                                    tokens
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Input images</dt>
                                <dd>{{ quote.image_tokens }} tokens</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Output</dt>
                                <dd>{{ quote.output_tokens }} tokens</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Reasoning</dt>
                                <dd>
                                    {{ quote.reasoning_tokens.typical }}–{{
                                        quote.reasoning_tokens.max
                                    }}
                                    tokens
                                </dd>
                            </div>
                        </dl>

                        <p class="border-t pt-3 text-xs text-muted-foreground">
                            Text and reasoning counts are only known when
                            Pixelforge finishes. The final price can be lower,
                            never higher than the amount above. Failed
                            generations are free.
                        </p>
                    </template>
                </CardContent>
            </Card>
        </div>

        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Recent generations</h2>
                <Link
                    :href="historyRoute()"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    View all
                </Link>
            </div>

            <div
                v-if="props.generations.length === 0"
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nothing yet. Write a prompt to generate your first image.
            </div>

            <GenerationCard
                v-for="generation in props.generations"
                :key="generation.id"
                :generation="generation"
            />
        </div>
    </div>
</template>
