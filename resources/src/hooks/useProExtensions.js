import { useSyncExternalStore } from 'react';

/**
 * React hook that subscribes to the window.BooleanSmtpApp extension registry.
 *
 * Returns registered Pro routes, dashboard widgets, and settings panels.
 * Re-renders automatically when Pro bundle registers new items.
 */
// Stable fallbacks: useSyncExternalStore requires getSnapshot to return a cached value.
const EMPTY = Object.freeze([]);
const noopSubscribe = () => () => {};

export function useProExtensions() {
    const api = window.BooleanSmtpApp;

    const routes = useSyncExternalStore(
        api?._subscribe || noopSubscribe,
        () => api?._getRoutes() || EMPTY,
        () => EMPTY,
    );

    const widgets = useSyncExternalStore(
        api?._subscribe || noopSubscribe,
        () => api?._getWidgets() || EMPTY,
        () => EMPTY,
    );

    const settingsPanels = useSyncExternalStore(
        api?._subscribe || noopSubscribe,
        () => api?._getSettingsPanels?.() || EMPTY,
        () => EMPTY,
    );

    const proConnectionPanels = useSyncExternalStore(
        api?._subscribe || noopSubscribe,
        () => api?._getConnectionPanels?.() || EMPTY,
        () => EMPTY,
    );

    return { proRoutes: routes, proWidgets: widgets, settingsPanels, proConnectionPanels };
}
