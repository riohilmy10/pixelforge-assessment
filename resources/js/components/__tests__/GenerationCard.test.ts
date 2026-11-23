import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import GenerationCard from '@/components/GenerationCard.vue';
import type { Generation } from '@/types';

function generation(overrides: Partial<Generation> = {}): Generation {
    return {
        id: 'gen-1',
        status: 'processing',
        is_active: true,
        is_terminal: false,
        prompt: 'Cat eats fish',
        size: '1536x1024',
        quality: 'high',
        reference_images: [],
        image_url: null,
        error_code: null,
        error_message: null,
        estimated_cost: '0.086281250',
        estimated_cost_display: '$0.08628125',
        actual_cost: null,
        actual_cost_display: null,
        created_at: '2026-11-23T00:00:00+00:00',
        completed_at: null,
        ...overrides,
    };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('GenerationCard', () => {
    it('shows the stage label and percentage for each active status', () => {
        const cases = [
            ['pending', 10, 'Queued on this server'],
            ['queued', 30, 'Waiting for Pixelforge'],
            ['processing', 70, 'Generating your image'],
        ] as const;

        for (const [status, percent, label] of cases) {
            const wrapper = mount(GenerationCard, {
                props: { generation: generation({ status }) },
            });

            const bar = wrapper.get('[role="progressbar"]');

            expect(bar.attributes('aria-valuenow')).toBe(String(percent));
            expect(bar.attributes('aria-label')).toBe(label);
            expect(wrapper.text()).toContain(label);
            expect(wrapper.text()).toContain(`${percent}%`);
        }
    });

    it('replaces the progress bar with the settled charge on success', () => {
        const wrapper = mount(GenerationCard, {
            props: {
                generation: generation({
                    status: 'succeeded',
                    is_active: false,
                    is_terminal: true,
                    image_url: 'https://pixelforge.test/images/done.png',
                    actual_cost: '0.073525000',
                    actual_cost_display: '$0.073525',
                }),
            },
        });

        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('$0.073525 charged');
        expect(wrapper.text()).not.toContain('%');
    });

    it('explains that a failed generation is free and shows the error', () => {
        const wrapper = mount(GenerationCard, {
            props: {
                generation: generation({
                    status: 'failed',
                    is_active: false,
                    is_terminal: true,
                    error_message: 'The model could not produce an image.',
                }),
            },
        });

        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No charge');
        expect(wrapper.text()).toContain(
            'The model could not produce an image.',
        );
    });

    it('opens the image in a dialog when the thumbnail is clicked', async () => {
        const wrapper = mount(GenerationCard, {
            props: {
                generation: generation({
                    status: 'succeeded',
                    is_active: false,
                    is_terminal: true,
                    image_url: 'https://pixelforge.test/images/done.png',
                    actual_cost: '0.073525000',
                    actual_cost_display: '$0.073525',
                }),
            },
            attachTo: document.body,
        });

        await wrapper.get('button[aria-label^="Open image"]').trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[role="dialog"]');

        expect(dialog).not.toBeNull();
        expect(dialog?.textContent).toContain('Cat eats fish');
        expect(dialog?.textContent).toContain('1536x1024');
        expect(
            document.body.querySelectorAll(
                'img[src="https://pixelforge.test/images/done.png"]',
            ).length,
        ).toBe(2);

        wrapper.unmount();
    });

    it('collapses long prompts behind Read more without resizing the image', async () => {
        const longPrompt = 'A lighthouse on a cliff at dawn. '.repeat(20);

        const wrapper = mount(GenerationCard, {
            props: {
                generation: generation({
                    status: 'succeeded',
                    is_active: false,
                    is_terminal: true,
                    prompt: longPrompt,
                    image_url: 'https://pixelforge.test/images/done.png',
                    actual_cost: '0.073525000',
                    actual_cost_display: '$0.073525',
                }),
            },
            attachTo: document.body,
        });

        await wrapper.get('button[aria-label^="Open image"]').trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[role="dialog"]');
        const image = dialog?.querySelector('img');

        expect(dialog?.querySelector('p.line-clamp-3')).not.toBeNull();

        const toggle = Array.from(
            dialog?.querySelectorAll('button') ?? [],
        ).find((button) => button.textContent?.trim() === 'Read more');

        expect(toggle).toBeDefined();
        expect(toggle?.getAttribute('aria-expanded')).toBe('false');

        toggle?.click();
        await flushPromises();

        expect(dialog?.querySelector('p.line-clamp-3')).toBeNull();
        expect(dialog?.querySelector('.overflow-y-auto')).not.toBeNull();
        expect(dialog?.textContent).toContain('Show less');
        expect(image).toBe(dialog?.querySelector('img'));

        wrapper.unmount();
    });

    it('does not show Read more for a short prompt', async () => {
        const wrapper = mount(GenerationCard, {
            props: {
                generation: generation({
                    status: 'succeeded',
                    is_active: false,
                    is_terminal: true,
                    image_url: 'https://pixelforge.test/images/done.png',
                    actual_cost: '0.073525000',
                    actual_cost_display: '$0.073525',
                }),
            },
            attachTo: document.body,
        });

        await wrapper.get('button[aria-label^="Open image"]').trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector('[role="dialog"]');

        expect(dialog?.textContent).not.toContain('Read more');

        wrapper.unmount();
    });

    it('expands a long prompt on the card without opening the image dialog', async () => {
        const wrapper = mount(GenerationCard, {
            props: {
                generation: generation({
                    status: 'failed',
                    is_active: false,
                    is_terminal: true,
                    prompt: 'A lighthouse on a cliff at dawn. '.repeat(20),
                    image_url: null,
                    error_message: 'The model could not produce an image.',
                }),
            },
        });

        const readMore = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Read more');

        expect(readMore).toBeDefined();
        expect(wrapper.find('p.line-clamp-2').exists()).toBe(true);

        await readMore?.trigger('click');

        expect(wrapper.find('p.line-clamp-2').exists()).toBe(false);
        expect(wrapper.find('.max-h-40.overflow-y-auto').exists()).toBe(true);
        expect(wrapper.text()).toContain('Show less');
        expect(document.querySelector('[role="dialog"]')).toBeNull();

        wrapper.unmount();
    });
});
