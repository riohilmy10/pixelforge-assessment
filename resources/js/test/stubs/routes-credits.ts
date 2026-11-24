/**
 * Stand-in for the Wayfinder-generated `@/routes/credits` module.
 *
 * The real file is gitignored and only exists after `npm run build`, so component tests
 * alias this module to deterministic paths on a clean checkout.
 */
type Route = (() => string) & { url: () => string };

const route = (path: string): Route =>
    Object.assign(() => path, { url: () => path });

export const index = route('/credits');
export const purchase = route('/credits/purchase');
