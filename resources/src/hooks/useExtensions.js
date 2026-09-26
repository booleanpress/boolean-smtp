import { useSyncExternalStore } from 'react';

// Stable fallbacks: useSyncExternalStore requires getSnapshot to return a cached value.
const EMPTY = Object.freeze([]);
const noopSubscribe = () => () => {};

/**
 * Subscribe to the `window.BooleanSmtpApp` extension registry.
 *
 * Returns the routes, dashboard widgets, settings panels and connection-form panels other plugins
 * registered, and re-renders when one registers more.
 *
 * @since 1.0.0
 * @returns {{ routes: Array<object>, widgets: Array<object>, settingsPanels: Array<object>, connectionPanels: Array<object> }}
 */
export function useExtensions() {
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

    const connectionPanels = useSyncExternalStore(
        api?._subscribe || noopSubscribe,
        () => api?._getConnectionPanels?.() || EMPTY,
        () => EMPTY,
    );

    return { routes, widgets, settingsPanels, connectionPanels };
}
