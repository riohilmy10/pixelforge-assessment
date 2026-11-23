/**
 * Stand-in for the Wayfinder-generated `@/routes/generations` module.
 *
 * The real file is gitignored and only exists after `npm run build`. Component tests must
 * run on a clean checkout, so the Vitest config aliases this module to this deterministic
 * stub instead of depending on generated output.
 */
type Route = (() => string) & { url: () => string };

const route = (path: string): Route =>
    Object.assign(() => path, { url: () => path });

export const estimate = route('/generations/estimate');
export const index = route('/generations');
export const store = route('/generations');
