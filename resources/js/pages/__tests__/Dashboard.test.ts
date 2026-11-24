import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import Dashboard from '@/pages/Dashboard.vue';
import type { Estimate, Generation } from '@/types';

const poll = vi.hoisted(() => ({
    start: vi.fn(),
    stop: vi.fn(),
    usePoll: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({ props: {} }),
    usePoll: poll.usePoll,
}));

const estimate = {
    charged: {
        max: '0.100000000',
        max_nano: 100_000_000,
        max_display: '$0.1',
        typical: '0.080000000',
        typical_nano: 80_000_000,
        typical_display: '$0.08',
    },
    provider_cost: {
        typical: '0.060000000',
        max: '0.080000000',
        typical_nano: 60_000_000,
        max_nano: 80_000_000,
    },
    text_tokens: { typical: 1, max: 2 },
    image_tokens: 0,
    per_reference_image: 765,
    output_tokens: 1,
    reasoning_tokens: { typical: 1, max: 2 },
} as Estimate;

const balance = {
    credit_balance_nano: 10_000_000_000,
    reserved_nano: 0,
    available_nano: 10_000_000_000,
    credit_balance_display: '$10',
    reserved_display: '$0',
    available_display: '$10',
};

function mountDashboard(active = false, available: typeof balance = balance) {
    return mount(Dashboard, {
        props: {
            generations: active
                ? [{ id: 'active', is_active: true } as Generation]
                : [],
            options: {
                sizes: ['1024x1024'],
                qualities: ['medium'],
                prompt_max: 4000,
                reference_images_max: 4,
            },
            balance: available,
        },
        global: { stubs: { GenerationCard: true, Link: true } },
    });
}

const quoteResponse = () => Response.json({ estimate });

beforeEach(() => {
    vi.useFakeTimers();
    poll.usePoll.mockReturnValue(poll);
});

afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

describe('Dashboard', () => {
    it('retries a failed and then stalled quote with the unchanged draft', async () => {
        const fetchQuote = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(new Response(null, { status: 503 }))
            .mockImplementationOnce(
                (_url, options) =>
                    new Promise((_resolve, reject) => {
                        options?.signal?.addEventListener('abort', () =>
                            reject(
                                new DOMException(
                                    'Deadline reached',
                                    'AbortError',
                                ),
                            ),
                        );
                    }),
            )
            .mockResolvedValueOnce(quoteResponse());

        vi.stubGlobal('fetch', fetchQuote);
        const wrapper = mountDashboard();
        const retry = () =>
            wrapper
                .findAll('button')
                .find((button) => button.text() === 'Retry');

        await wrapper.get('textarea').setValue('Current draft');
        await vi.advanceTimersByTimeAsync(300);
        expect(retry()).toBeDefined();
        await retry()?.trigger('click');
        await vi.advanceTimersByTimeAsync(10000);
        expect(retry()).toBeDefined();
        await retry()?.trigger('click');
        await vi.advanceTimersByTimeAsync(0);

        expect(wrapper.text()).toContain('$0.1');
        expect(retry()).toBeUndefined();
        expect(fetchQuote.mock.calls[2]?.[1]?.body).toEqual(
            fetchQuote.mock.calls[0]?.[1]?.body,
        );
        wrapper.unmount();
    });

    it('puts indexed quote validation errors in the reference field', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                Response.json(
                    {
                        errors: {
                            'reference_images.0': ['Enter a valid image URL.'],
                        },
                    },
                    { status: 422 },
                ),
            ),
        );
        const wrapper = mountDashboard();
        await wrapper.get('textarea').setValue('A lighthouse');
        await wrapper.get('input[type="url"]').setValue('bad URL');
        await vi.advanceTimersByTimeAsync(300);

        expect(wrapper.text()).toContain('Enter a valid image URL.');
        expect(wrapper.text()).not.toContain('Could not load the price');
        wrapper.unmount();
    });

    it('shows a zero price but keeps the static token counts for an empty draft', async () => {
        const fetchQuote = vi
            .fn()
            .mockImplementation(async () => quoteResponse());
        vi.stubGlobal('fetch', fetchQuote);

        const wrapper = mountDashboard();

        await vi.advanceTimersByTimeAsync(300);

        expect(fetchQuote).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('$0.0000');
        expect(wrapper.text()).toContain('1100 tokens');
        expect(wrapper.text()).toContain('1100–2000 tokens');
        expect(wrapper.text()).not.toContain('Calculating');
        expect(
            wrapper.get('button[type="submit"]').attributes('disabled'),
        ).toBeDefined();

        await wrapper.get('textarea').setValue('A lighthouse');
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchQuote).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('$0.1');
        expect(wrapper.text()).not.toContain('$0.0000');

        wrapper.unmount();
    });

    it('shows skeletons in the price panel while a new quote is pending', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation(async () => quoteResponse()),
        );

        const wrapper = mountDashboard();
        await vi.advanceTimersByTimeAsync(300);

        await wrapper.get('textarea').setValue('A lighthouse');

        expect(wrapper.text()).toContain('Estimated maximum');
        expect(wrapper.text()).not.toContain('Calculating');
        expect(wrapper.find('[data-slot="skeleton"]').exists()).toBe(true);

        await vi.advanceTimersByTimeAsync(300);
        expect(wrapper.find('[data-slot="skeleton"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('blocks submit when the quote exceeds the available balance', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation(async () => quoteResponse()),
        );
        const wrapper = mountDashboard(false, {
            ...balance,
            credit_balance_nano: 0,
            available_nano: 0,
            credit_balance_display: '$0',
            available_display: '$0',
        });

        await wrapper.get('textarea').setValue('A lighthouse');
        await vi.advanceTimersByTimeAsync(300);
        await nextTick();

        const generate = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Generate');

        expect(generate?.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('You need up to');
        expect(wrapper.text()).toContain('$0');
        wrapper.unmount();
    });

    it('cancels a running poll, posts the quote, and preserves newer edits', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation(async () => quoteResponse()),
        );
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        const wrapper = mountDashboard(true);
        const cancel = vi.fn();

        await wrapper.get('textarea').setValue('Submitted draft');
        await vi.advanceTimersByTimeAsync(300);
        poll.usePoll.mock.lastCall?.[1].onCancelToken({ cancel });
        await wrapper.get('form').trigger('submit');
        const [, payload, visit] = post.mock.calls[0]!;
        expect(payload).toMatchObject({
            prompt: 'Submitted draft',
            quoted_max: estimate.charged.max,
        });
        visit?.onBefore?.({} as never);
        expect(poll.stop).toHaveBeenCalled();
        expect(cancel).toHaveBeenCalledOnce();

        await wrapper.get('textarea').setValue('Newer draft');
        visit?.onSuccess?.({ props: { flash: {} } } as never);
        visit?.onFinish?.({} as never);
        await nextTick();
        expect(wrapper.get('textarea').element.value).toBe('Newer draft');
        expect(poll.start).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it('keeps the draft for a changed price and clears it after confirmation', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockImplementation(async () => quoteResponse()),
        );
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        const wrapper = mountDashboard();
        await wrapper.get('textarea').setValue('A lighthouse');
        await wrapper
            .get('input[type="url"]')
            .setValue('https://example.com/image.png');
        await vi.advanceTimersByTimeAsync(300);

        await wrapper.get('form').trigger('submit');
        const updatedQuote = {
            ...estimate,
            charged: {
                ...estimate.charged,
                max: '0.200000000',
                max_display: '$0.2',
            },
        };
        post.mock.calls[0]?.[2]?.onSuccess?.({
            props: {
                flash: {
                    error: 'The price changed.',
                    updated_quote: updatedQuote,
                },
            },
        } as never);
        await nextTick();
        expect(wrapper.get('textarea').element.value).toBe('A lighthouse');
        expect(wrapper.text()).toContain('$0.2');

        await wrapper.get('form').trigger('submit');
        expect(post.mock.calls[1]?.[1]).toMatchObject({
            quoted_max: '0.200000000',
        });
        post.mock.calls[1]?.[2]?.onSuccess?.({
            props: { flash: {} },
        } as never);
        await nextTick();
        expect(wrapper.get('textarea').element.value).toBe('');
        expect(
            wrapper.get<HTMLInputElement>('input[type="url"]').element.value,
        ).toBe('');
        wrapper.unmount();
    });
});
