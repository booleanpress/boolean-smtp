import * as React from 'react';
import * as ReactDOM from 'react-dom';
import * as ReactDOMClient from 'react-dom/client';
import * as jsxRuntime from 'react/jsx-runtime';
import { HashRouter, Link, NavLink, Navigate, Outlet, Route, Routes, useLocation, useNavigate, useParams, useSearchParams } from 'react-router';
import { TOOLTIP_DELAY_MS, TOOLTIP_SKIP_DELAY_MS } from '@/config/tooltips';
import { Toaster, toast } from 'sonner';
import { ThemeProvider, useTheme } from '@/components/theme-provider';
import {
    AlertDialog, Avatar, Checkbox, Collapsible, Dialog, DropdownMenu, Label, Popover, Progress,
    RadioGroup, ScrollArea, Select, Separator, Slot, Switch, Tabs, Toggle, ToggleGroup, Tooltip,
} from 'radix-ui';
import { TooltipProvider } from '@/components/ui/tooltip';
import App from './App';
import './index.css';

const { createRoot } = ReactDOMClient;

// Named subsets keep the host bundle tree-shaken; these are the guaranteed exports for Pro.
const ReactRouter = { HashRouter, Link, NavLink, Navigate, Outlet, Route, Routes, useLocation, useNavigate, useParams, useSearchParams };
const Sonner = { Toaster, toast };
const Theme = { ThemeProvider, useTheme };

const containerId =
    document.querySelector('[data-booleanpress-app]')?.id || 'boolean-smtp-app';

const container = document.getElementById(containerId);

// BooleanPress outputs bootstrap props on data-props; wp_localize_script also sets window.BooleanSmtpAdmin.
// Merge so oauthRedirectUris and other props always match PHP (avoids missing nested keys).
if (container && typeof window !== 'undefined') {
    const raw = container.getAttribute('data-props');
    if (raw) {
        try {
            const parsed = JSON.parse(raw);
            window.BooleanSmtpAdmin = { ...(window.BooleanSmtpAdmin || {}), ...parsed };
        } catch {
            /* ignore invalid JSON */
        }
    }

    const isRtl = Boolean(window.BooleanSmtpAdmin?.isRtl);
    document.documentElement.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
    document.documentElement.setAttribute('lang', String(window.BooleanSmtpAdmin?.locale || 'en'));
}

// Extension registry -- set up BEFORE React renders so the Pro bundle (loaded
// after this script via wp_enqueue_script dependency) can register routes
// and widgets synchronously before React's first paint.
// New array references on mutation so useSyncExternalStore detects changes.
const _ext = { routes: [], widgets: [], settingsPanels: [], connectionPanels: [], listeners: new Set() };

window.BooleanSmtpApp = {
    // Single shared runtime (see resources/vite/host-runtime.js). The Pro bundle
    // externalises these module ids to this object so both bundles share ONE
    // React instance and ONE set of Radix/Sonner/Router/Theme contexts.
    runtime: {
        React,
        ReactDOM,
        ReactDOMClient,
        jsxRuntime,
        ReactRouter,
        Sonner,
        Theme,
        Radix: {
            AlertDialog, Avatar, Checkbox, Collapsible, Dialog, DropdownMenu, Label, Popover, Progress,
            RadioGroup, ScrollArea, Select, Separator, Slot, Switch, Tabs, Toggle, ToggleGroup, Tooltip,
        },
    },
    registerRoutes(routes) {
        _ext.routes = [..._ext.routes, ...routes];
        _ext.listeners.forEach(fn => fn());
    },
    registerDashboardWidgets(widgets) {
        _ext.widgets = [..._ext.widgets, ...widgets];
        _ext.listeners.forEach(fn => fn());
    },
    registerSettingsPanels(panels) {
        _ext.settingsPanels = [..._ext.settingsPanels, ...panels];
        _ext.listeners.forEach(fn => fn());
    },
    // Each panel: { id, driver, slot, component }. driver+slot is how ConnectionPanelSlot
    // (resources/src/pages/ConnectionForm.jsx) finds where it belongs inside an existing page,
    // unlike registerRoutes/registerDashboardWidgets/registerSettingsPanels which cover whole
    // pages or dashboard slots, not a spot inside one.
    registerConnectionPanels(panels) {
        _ext.connectionPanels = [..._ext.connectionPanels, ...panels];
        _ext.listeners.forEach(fn => fn());
    },
    _subscribe(listener) {
        _ext.listeners.add(listener);
        return () => _ext.listeners.delete(listener);
    },
    _getRoutes: () => _ext.routes,
    _getWidgets: () => _ext.widgets,
    _getSettingsPanels: () => _ext.settingsPanels,
    _getConnectionPanels: () => _ext.connectionPanels,
};

if (container) {
    createRoot(container).render(
        <ThemeProvider storageKey="boolean_smtp_theme" defaultTheme="light">
            <TooltipProvider delayDuration={TOOLTIP_DELAY_MS} skipDelayDuration={TOOLTIP_SKIP_DELAY_MS}>
                <HashRouter>
                    <App />
                </HashRouter>
            </TooltipProvider>
        </ThemeProvider>
    );
}
