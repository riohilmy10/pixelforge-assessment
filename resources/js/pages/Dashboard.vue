<script setup lang="ts">
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
import { index as creditsRoute } from '@/routes/credits';
import {
    estimate as estimateRoute,
    index as historyRoute,
    store as storeRoute,
} from '@/routes/generations';
import type {
    BalanceSummary,
    Estimate,
    Generation,
    GenerationOptions,
} from '@/types';

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
    balance: BalanceSummary;
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
    quoted_max: '',
});

const quote = ref<Estimate | null>(null);
const quotedSignature = ref<string | null>(null);
const quoting = ref(false);
const quoteError = ref<string | null>(null);
let quoteTimer: number | undefined;
let quoteController: AbortController | null = null;
let quoteRequestId = 0;
let quoteReferenceError: string | undefined;
const QUOTE_TIMEOUT_MS = 10000;

type GenerationFlash = {
    status?: string | null;
    error?: string | null;
    updated_quote?: Estimate | null;
};

/** Server feedback: queued confirmation, or "pricing unavailable, nothing submitted". */
const page = usePage();
const flash = computed(() => page.props.flash as GenerationFlash);

/** Blanks are not reference images and must not count towards the 765-token charge. */
const references = computed(() =>
    form.reference_images.map((url) => url.trim()).filter(Boolean),
);

/**
 * The exact inputs a quote was requested for. Keeping a signature lets the form reject
 * a stale quote and prevents an older request from overwriting a newer answer.
 */
const quoteInputs = computed(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_images: references.value,
}));

const quoteSignature = computed(() => JSON.stringify(quoteInputs.value));

const quoteMatchesInputs = computed(
    () =>
        quote.value !== null && quotedSignature.value === quoteSignature.value,
);

/**
 * The server holds the same worst-case quote before creating anything. Showing the gate
 * here too makes the refusal predictable instead of a surprise after submit.
 */
const insufficientCredits = computed(
    () =>
        quoteMatchesInputs.value &&
        quote.value !== null &&
        quote.value.charged.max_nano > props.balance.available_nano,
);

/**
 * Laravel's CSRF middleware accepts the XSRF-TOKEN cookie value in this header. The
 * quote is a plain fetch (not an Inertia visit), so it must send the token itself.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function clearQuoteReferenceError(): void {
    if (
        quoteReferenceError !== undefined &&
        form.errors.reference_images === quoteReferenceError
    ) {
        form.clearErrors('reference_images');
    }

    quoteReferenceError = undefined;
}

/**
 * Fetch the live quote with a 300 ms debounce. POST keeps a 4,000-character prompt out
 * of the URL. Wayfinder builds the endpoint, so the route stays in sync with the backend.
 * The server uses the same estimate code that the submit flow snapshots, which is why
 * the number shown here matches the stored promise.
 */
async function refreshQuote(): Promise<void> {
    const signature = quoteSignature.value;
    const payload = quoteInputs.value;
    const requestId = ++quoteRequestId;

    quoteController?.abort();
    const controller = new AbortController();
    quoteController = controller;
    const deadline = window.setTimeout(
        () => controller.abort(),
        QUOTE_TIMEOUT_MS,
    );
    clearQuoteReferenceError();
    quote.value = null;
    quotedSignature.value = null;
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
            signal: controller.signal,
            body: JSON.stringify(payload),
        });

        if (response.status === 422) {
            const { errors } = (await response.json()) as {
                errors?: Record<string, string[]>;
            };

            if (requestId !== quoteRequestId) {
                return;
            }

            const messages = Object.entries(errors ?? {})
                .filter(
                    ([key]) =>
                        key === 'reference_images' ||
                        key.startsWith('reference_images.'),
                )
                .flatMap(([, messages]) => messages);

            if (messages.length > 0) {
                quoteReferenceError = messages.join(' ');
                form.setError('reference_images', quoteReferenceError);
                quoteError.value =
                    'Correct the reference images to get an estimate.';

                return;
            }
        }

        if (!response.ok) {
            throw new Error(`Estimate failed (${response.status})`);
        }

        const data = (await response.json()) as { estimate: Estimate };

        // A newer request (or an input change) has superseded this response.
        if (requestId !== quoteRequestId) {
            return;
        }

        quote.value = data.estimate;
        quotedSignature.value = signature;
    } catch {
        if (requestId !== quoteRequestId) {
            return;
        }

        quoteError.value = 'Could not load the price. Try again in a moment.';
        quote.value = null;
        quotedSignature.value = null;
    } finally {
        window.clearTimeout(deadline);
        // Only the active request owns the loading flag; an aborted older request must
        // not clear the spinner of the request that replaced it.
        if (requestId === quoteRequestId) {
            quoting.value = false;
        }
    }
}

/**
 * Invalidate the displayed promise immediately. Until a quote for the exact current
 * inputs arrives, the form cannot submit.
 *
 * This is only called from client lifecycle hooks or the input watcher; Inertia may
 * evaluate this component in Node for SSR, where `window` does not exist.
 */
function scheduleQuote(): void {
    quoteRequestId++;
    quoteController?.abort();
    quoteController = null;
    clearQuoteReferenceError();
    quote.value = null;
    quotedSignature.value = null;
    quoteError.value = null;
    quoting.value = false;

    window.clearTimeout(quoteTimer);
    quoteTimer = window.setTimeout(() => void refreshQuote(), 300);
}

/**
 * Re-quote whenever an input that affects token counts changes. Joining the URL list
 * gives Vue one stable scalar to watch while still reacting to add/remove/edit. The
 * initial request is started in onMounted so SSR never touches browser globals.
 */
watch(quoteSignature, scheduleQuote);

onBeforeUnmount(() => {
    quoteRequestId++;
    window.clearTimeout(quoteTimer);
    quoteController?.abort();
});

/**
 * Submit only validated fields. The backend re-filters blanks, but doing it here keeps
 * the POST body identical to what the quote counted.
 */
function submit(): void {
    if (
        form.processing ||
        quoting.value ||
        !quoteMatchesInputs.value ||
        !quote.value ||
        insufficientCredits.value
    ) {
        return;
    }

    const submittedSignature = quoteSignature.value;
    const submittedReferences = [...form.reference_images];
    form.quoted_max = quote.value.charged.max;

    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images
            .map((url) => url.trim())
            .filter(Boolean),
    }));

    form.post(storeRoute.url(), {
        preserveScroll: true,
        onBefore: () => {
            poll.stop();
            cancelPoll?.();
        },
        onSuccess: (page) => {
            const feedback = page.props.flash as GenerationFlash | undefined;
            const sameDraft =
                quoteSignature.value === submittedSignature &&
                form.reference_images.length === submittedReferences.length &&
                form.reference_images.every(
                    (url, index) => url === submittedReferences[index],
                );

            if (feedback?.error) {
                if (
                    feedback.updated_quote &&
                    quoteSignature.value === submittedSignature
                ) {
                    quoteRequestId++;
                    quoteController?.abort();
                    window.clearTimeout(quoteTimer);
                    clearQuoteReferenceError();
                    quoteError.value = null;
                    quoting.value = false;
                    quote.value = feedback.updated_quote;
                    quotedSignature.value = submittedSignature;
                }
            } else if (sameDraft) {
                form.prompt = '';
                form.reference_images = [''];
            }
        },
        onFinish: () => {
            if (hasActive.value) {
                poll.start();
            }
        },
    });
}

/** Only poll while something can still change; idle dashboards sleep. */
const hasActive = computed(() =>
    props.generations.some((generation) => generation.is_active),
);

let cancelPoll: (() => void) | undefined;

const poll = usePoll(
    3000,
    {
        // Balance changes when a running generation settles and its hold is released,
        // so keep the money strip in the same partial reload as the cards.
        only: ['generations', 'balance'],
        onBefore: () => !form.processing,
        onCancelToken: (token) => {
            cancelPoll = () => token.cancel();
        },
        onFinish: () => {
            cancelPoll = undefined;
        },
    },
    { mode: 'rest', background: 'pause', autoStart: false },
);

watch(hasActive, (active) => {
    if (active && !form.processing) {
        poll.start();
    } else {
        poll.stop();
    }
});

onMounted(() => {
    scheduleQuote();

    if (hasActive.value && !form.processing) {
        poll.start();
    }
});
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

        <!-- Credits are the product's money unit, so the spendable amount is always on
             screen next to the generator rather than hidden on a separate page. -->
        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-lg border bg-card px-4 py-3"
        >
            <div>
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Available credits
                </p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ props.balance.available_display }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ props.balance.credit_balance_display }} owned
                    <template v-if="props.balance.reserved_nano > 0">
                        · {{ props.balance.reserved_display }} held for
                        generations in progress
                    </template>
                </p>
            </div>
            <Button as-child variant="outline">
                <Link :href="creditsRoute()">Buy credits</Link>
            </Button>
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
                                <Label id="generation-size-label">Size</Label>
                                <Select v-model="form.size">
                                    <SelectTrigger
                                        class="w-full"
                                        aria-labelledby="generation-size-label"
                                    >
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
                                <Label id="generation-quality-label"
                                    >Quality</Label
                                >
                                <Select v-model="form.quality">
                                    <SelectTrigger
                                        class="w-full"
                                        aria-labelledby="generation-quality-label"
                                    >
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
                            :disabled="
                                form.processing ||
                                quoting ||
                                !quoteMatchesInputs ||
                                insufficientCredits
                            "
                        >
                            {{ form.processing ? 'Submitting…' : 'Generate' }}
                        </Button>
                        <p
                            v-if="insufficientCredits && quote"
                            class="text-sm text-destructive"
                        >
                            You need up to {{ quote.charged.max_display }}, but
                            only {{ props.balance.available_display }} is
                            available.
                            <Link
                                :href="creditsRoute()"
                                class="font-medium underline underline-offset-4"
                            >
                                Buy credits
                            </Link>
                        </p>
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
                        <p>{{ quoteError }}</p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="mt-2"
                            @click="refreshQuote"
                        >
                            Retry
                        </Button>
                    </div>

                    <div
                        v-else-if="!quote"
                        class="text-sm text-muted-foreground"
                    >
                        Calculating…
                    </div>

                    <div
                        v-else-if="!quoteMatchesInputs"
                        class="text-sm text-muted-foreground"
                    >
                        Calculating…
                    </div>

                    <template v-else>
                        <div>
                            <p
                                class="text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                Estimated maximum
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
                            Pixelforge finishes. The final price can be lower.
                            If provider cost exceeds the estimate, you pay only
                            provider cost. Failed generations are free.
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
