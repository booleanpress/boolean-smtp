/**
 * How every tooltip in the admin behaves.
 *
 * The shadcn primitive ships `delayDuration = 0`, so a tooltip appears the instant the pointer
 * touches its trigger and disappears the instant it leaves. Sweeping the mouse across a row of
 * triggers — the collapsed sidebar rail, the header's quick-navigation buttons — then strobes a
 * tooltip on every icon it passes. A short delay, and no "skip" window that would make the next
 * one instant, means a tooltip only appears where the pointer actually rests.
 *
 * @since 1.0.0
 */
export const TOOLTIP_DELAY_MS = 500;

/**
 * Radix keeps tooltips instant for this long after one has closed; zero makes every tooltip wait
 * its own delay, which is what stops a sweep from strobing.
 *
 * @since 1.0.0
 */
export const TOOLTIP_SKIP_DELAY_MS = 0;
