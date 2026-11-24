import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/Dashboard.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({ props: {} }),
    usePoll: () => ({ start: vi.fn(), stop: vi.fn() }),
}));

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('Dashboard SSR', () => {
    it('renders without a window global', async () => {
        vi.stubGlobal('window', undefined);

        const app = createSSRApp(Dashboard, {
            generations: [],
            options: {
                sizes: ['1024x1024'],
                qualities: ['medium'],
                prompt_max: 4000,
                reference_images_max: 4,
            },
            balance: {
                credit_balance_nano: 10_000_000_000,
                reserved_nano: 0,
                available_nano: 10_000_000_000,
                credit_balance_display: '$10',
                reserved_display: '$0',
                available_display: '$10',
            },
        });

        await expect(renderToString(app)).resolves.toContain('Price estimate');
    });
});
