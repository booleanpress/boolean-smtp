/**
 * Shared runtime contract between the free admin bundle (host) and the Pro bundle.
 *
 * The host bundles React (and the libraries that carry React context or singleton
 * state) exactly once and publishes them on `window.BooleanSmtpApp.runtime`.
 * The Pro build externalises the same module ids to that object, so both bundles
 * share ONE React instance — hooks, context and Radix providers require it.
 *
 * Keep the two halves in sync:
 *   - `src/main.jsx`                      publishes `runtime`
 *   - `boolean-smtp-pro/resources/vite.config.js` consumes it via `hostRuntimePlugin`
 */
export const HOST_RUNTIME_GLOBAL = 'window.BooleanSmtpApp.runtime';

/** Module id → property on the runtime object. */
export const HOST_RUNTIME_MODULES = {
    'react': 'React',
    'react-dom': 'ReactDOM',
    'react-dom/client': 'ReactDOMClient',
    'react/jsx-runtime': 'jsxRuntime',
    'react-router': 'ReactRouter',
    'radix-ui': 'Radix',
    'sonner': 'Sonner',
    '@/components/theme-provider': 'Theme',
};

/**
 * Guaranteed named exports per module (the host publishes exactly these; anything
 * else resolves to `undefined` in the Pro bundle):
 *   react, react-dom, react-dom/client, react/jsx-runtime → full module namespaces
 *   react-router → HashRouter Link NavLink Navigate Outlet Route Routes useLocation useNavigate useParams useSearchParams
 *   sonner       → Toaster toast
 *   @/components/theme-provider → ThemeProvider useTheme
 *   radix-ui     → HOST_RADIX_PRIMITIVES below
 */
/** Radix primitives the host guarantees (everything `components/ui/*` imports). */
export const HOST_RADIX_PRIMITIVES = [
    'AlertDialog', 'Avatar', 'Checkbox', 'Collapsible', 'Dialog', 'DropdownMenu', 'Label', 'Popover',
    'Progress', 'RadioGroup', 'ScrollArea', 'Select', 'Separator', 'Slot', 'Switch', 'Tabs', 'Toggle',
    'ToggleGroup', 'Tooltip',
];
