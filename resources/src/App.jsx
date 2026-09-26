import { Suspense, lazy } from 'react';
import { Routes, Route, Navigate, useLocation } from 'react-router';
import Layout from './components/Layout';
import OnboardingQueryRedirect from './components/OnboardingQueryRedirect';
import RouteErrorBoundary from './components/RouteErrorBoundary';
import { Toaster } from '@/components/ui/sonner';
import { Spinner } from '@/components/ui/spinner';
import { useExtensions } from './hooks/useExtensions';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const About = lazy(() => import('./pages/About'));
const Connections = lazy(() => import('./pages/Connections'));
const ConnectionNew = lazy(() => import('./pages/ConnectionNew'));
const ConnectionEdit = lazy(() => import('./pages/ConnectionEdit'));
const EmailLogs = lazy(() => import('./pages/EmailLogs'));
const EmailLogDetail = lazy(() => import('./pages/EmailLogDetail'));
const Settings = lazy(() => import('./pages/Settings'));
const TestEmail = lazy(() => import('./pages/TestEmail'));
const MigrationWizard = lazy(() => import('./pages/MigrationWizard'));
const Notifications = lazy(() => import('./pages/Notifications'));
const OnboardingWizard = lazy(() => import('./pages/OnboardingWizard'));
const NotFound = lazy(() => import('./pages/NotFound'));

const PageLoader = () => (
    <div className="flex min-h-[50vh] items-center justify-center p-8">
        <Spinner className="size-8 text-primary" />
    </div>
);

// One lazy component per extension loader, created once. `lazy()` returns a new component *type* on
// every call, so creating it inside render would unmount the whole extension page and re-show the
// fallback each time App re-renders (a registration, HMR updates, any parent state change).
const extensionRouteComponents = new WeakMap();

/**
 * Resolve (and cache) the lazy component for a route loader another plugin registered.
 *
 * @since 1.0.0
 * @param {() => Promise<{ default: import('react').ComponentType }>} loader
 * @returns {import('react').LazyExoticComponent<import('react').ComponentType>}
 */
function lazyExtensionComponent(loader) {
    let Component = extensionRouteComponents.get(loader);
    if (!Component) {
        Component = lazy(loader);
        extensionRouteComponents.set(loader, Component);
    }
    return Component;
}

function ExtensionRoute({ loader }) {
    const Component = lazyExtensionComponent(loader);
    return (
        <Suspense fallback={<PageLoader />}>
            <Component />
        </Suspense>
    );
}

export default function App() {
    const { routes: extensionRoutes } = useExtensions();
    const location = useLocation();

    return (
        <>
            <Layout>
                <OnboardingQueryRedirect />
                {/* A screen that fails to load or render never blanks the app; another screen clears it. */}
                <RouteErrorBoundary resetKey={location.pathname}>
                    <Suspense fallback={<PageLoader />}>
                        <Routes>
                            <Route path="/" element={<Dashboard />} />
                            <Route path="/about" element={<About />} />
                            <Route path="/connections" element={<Connections />} />
                            <Route path="/connections/new" element={<ConnectionNew />} />
                            <Route path="/connections/:id" element={<ConnectionEdit />} />
                            <Route path="/logs" element={<EmailLogs />} />
                            <Route path="/logs/:id" element={<EmailLogDetail />} />
                            <Route path="/settings" element={<Settings />} />
                            <Route path="/settings/notifications" element={<Notifications />} />
                            <Route path="/tools/test" element={<TestEmail />} />
                            <Route path="/tools/migration" element={<MigrationWizard />} />
                            <Route path="/onboard" element={<OnboardingWizard />} />
                            <Route path="/setup" element={<Navigate to="/onboard" replace />} />
                            {extensionRoutes.map(r => (
                                <Route
                                    key={r.path}
                                    path={r.path}
                                    element={<ExtensionRoute loader={r.component} />}
                                />
                            ))}
                            <Route path="*" element={<NotFound />} />
                        </Routes>
                    </Suspense>
                </RouteErrorBoundary>
            </Layout>
            {/* Offset below the WP admin bar (32px desktop / 46px mobile) using WP core's own CSS variable. */}
            <Toaster
                position="top-right"
                offset={{ top: 'calc(var(--wp-admin--admin-bar--height, 32px) + 16px)' }}
                mobileOffset={{ top: 'calc(var(--wp-admin--admin-bar--height, 46px) + 12px)' }}
            />
        </>
    );
}
