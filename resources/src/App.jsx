import { Suspense, lazy } from 'react';
import { Routes, Route, Navigate } from 'react-router';
import Layout from './components/Layout';
import OnboardingQueryRedirect from './components/OnboardingQueryRedirect';
import { Toaster } from '@/components/ui/sonner';
import { Spinner } from '@/components/ui/spinner';
import { useProExtensions } from './hooks/useProExtensions';

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

// One lazy component per Pro loader, created once. `lazy()` returns a new component *type* on every
// call, so creating it inside render would unmount the whole Pro page and re-show the fallback each
// time App re-renders (Pro registration, HMR updates, any parent state change).
const proRouteComponents = new WeakMap();

/**
 * Resolve (and cache) the lazy component for a Pro-registered route loader.
 *
 * @since 1.0.0
 * @param {() => Promise<{ default: import('react').ComponentType }>} loader
 * @returns {import('react').LazyExoticComponent<import('react').ComponentType>}
 */
function lazyProComponent(loader) {
    let Component = proRouteComponents.get(loader);
    if (!Component) {
        Component = lazy(loader);
        proRouteComponents.set(loader, Component);
    }
    return Component;
}

function ProRoute({ loader }) {
    const Component = lazyProComponent(loader);
    return (
        <Suspense fallback={<PageLoader />}>
            <Component />
        </Suspense>
    );
}

export default function App() {
    const { proRoutes } = useProExtensions();

    return (
        <>
            <Layout>
                <OnboardingQueryRedirect />
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
                        {proRoutes.map(r => (
                            <Route
                                key={r.path}
                                path={r.path}
                                element={<ProRoute loader={r.component} />}
                            />
                        ))}
                        <Route path="*" element={<NotFound />} />
                    </Routes>
                </Suspense>
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
