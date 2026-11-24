import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite-plus';

/**
 * Vitest runs on a standalone Vite config on purpose.
 *
 * The application config registers `laravel-vite-plugin`, which refuses to start in CI
 * because it is meant for the dev server. Component tests need only Vue SFC support and
 * the `@` alias, so they get their own minimal config and never touch the Laravel plugin.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: [
            {
                find: /^@\/routes\/generations$/,
                replacement: fileURLToPath(
                    new URL(
                        './resources/js/test/stubs/routes-generations.ts',
                        import.meta.url,
                    ),
                ),
            },
            {
                find: /^@\/routes\/credits$/,
                replacement: fileURLToPath(
                    new URL(
                        './resources/js/test/stubs/routes-credits.ts',
                        import.meta.url,
                    ),
                ),
            },
            {
                find: '@',
                replacement: fileURLToPath(
                    new URL('./resources/js', import.meta.url),
                ),
            },
        ],
    },
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.test.ts'],
    },
});
