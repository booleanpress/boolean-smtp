import { NavLink, useLocation } from "react-router"
import {
    Bell,
    ExternalLink,
    FileText,
    Info,
    LayoutDashboard,
    Plug,
    Puzzle,
    Send,
    Settings,
} from "lucide-react"

import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
    useSidebar,
} from "@/components/ui/sidebar"
import { TooltipProvider } from "@/components/ui/tooltip"
import { TOOLTIP_DELAY_MS, TOOLTIP_SKIP_DELAY_MS } from "@/config/tooltips"
import { useTranslations } from "@/hooks/useTranslations"
import { useExtensions } from "@/hooks/useExtensions"
import { useAdminExtensions } from "@/hooks/useAdminExtensions"
import sidebarLogo from "@/assets/brand/boolean-smtp-mark.png"

const mainNavItems = [
    { to: "/", labelKey: "layout.nav_overview", fallback: "Overview", icon: LayoutDashboard },
    { to: "/connections", labelKey: "layout.nav_mailers", fallback: "Mailers", icon: Plug },
    { to: "/logs", labelKey: "layout.nav_logs", fallback: "Logs", icon: FileText },
]
const toolsNavItems = [
    { to: "/tools/test", labelKey: "layout.nav_test_email", fallback: "Test Email", icon: Send },
]
const settingsNavItems = [
    { to: "/settings", labelKey: "layout.nav_settings", fallback: "Settings", icon: Settings },
    { to: "/settings/notifications", labelKey: "layout.nav_alerts", fallback: "Alerts", icon: Bell },
]
const aboutNavItems = [
    { to: "/about", labelKey: "layout.nav_about", fallback: "About", icon: Info },
]

/**
 * Sidebar entries for the routes other plugins register with a `nav` entry, for one group.
 *
 * A route registered through `window.BooleanSmtpApp.registerRoutes()` may carry
 * `nav: { group: 'main' | 'tools' | 'settings', label, labelKey?, icon? }`.
 *
 * @since 1.0.0
 *
 * @param {Array<object>} routes Registered routes.
 * @param {string}        group  The sidebar group.
 * @returns {Array<{ to: string, labelKey: string, fallback: string, icon: import('react').ComponentType }>}
 */
function extensionNavItems(routes, group) {
    return routes
        .filter(route => route?.nav?.group === group && typeof route.path === "string")
        .map(route => ({
            to: `/${route.path.replace(/^\/+/, "")}`,
            labelKey: route.nav.labelKey || "",
            fallback: String(route.nav.label || route.path),
            icon: route.nav.icon || Puzzle,
        }))
}

function isRouteActive(pathname, to) {
    const exact = to === "/" || to === "/settings"
    return exact ? pathname === to : pathname === to || pathname.startsWith(`${to}/`)
}

/**
 * Whether the sidebar's labels are hidden, so its buttons need a tooltip to be readable. An
 * expanded sidebar gets none: a mounted tooltip re-renders its trigger on every hover, and the
 * label is right there on screen.
 *
 * @since 1.0.0
 *
 * @returns {boolean}
 */
function useTooltipsNeeded() {
    const { state, isMobile } = useSidebar()
    return state === "collapsed" && !isMobile
}

function NavGroup({ label, items }) {
    const { t } = useTranslations()
    const { pathname } = useLocation()
    const tooltips = useTooltipsNeeded()
    return (
        <SidebarGroup>
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
            <SidebarGroupContent>
                <SidebarMenu>
                    {items.map(({ to, icon: Icon, labelKey, fallback }) => {
                        const label = t(labelKey, fallback)
                        return (
                            <SidebarMenuItem key={to}>
                                <SidebarMenuButton asChild isActive={isRouteActive(pathname, to)} tooltip={tooltips ? label : undefined}>
                                    <NavLink to={to}>
                                        <Icon />
                                        <span>{label}</span>
                                    </NavLink>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        )
                    })}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    )
}

/**
 * Links other plugins add through the `boolean_smtp_admin_menu_items` filter; rendered only
 * when at least one entry exists.
 *
 * @since 1.0.0
 */
function ExtensionNavGroup({ label, items }) {
    const tooltips = useTooltipsNeeded()
    if (items.length === 0) return null
    return (
        <SidebarGroup>
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarGroupContent>
                <SidebarMenu>
                    {items.map(({ slug, label: itemLabel, url }) => (
                        <SidebarMenuItem key={slug}>
                            <SidebarMenuButton asChild tooltip={tooltips ? itemLabel : undefined}>
                                <a href={url}>
                                    <ExternalLink />
                                    <span>{itemLabel}</span>
                                </a>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ))}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    )
}

/**
 * Render the BooleanSMTP navigation and its product branding.
 *
 * @since 1.0.0
 */
export function AppSidebar(props) {
    const { t } = useTranslations()
    const { menuItems } = useAdminExtensions()
    const { routes } = useExtensions()

    return (
        <Sidebar collapsible="icon" className="h-full" {...props}>
            <SidebarHeader className="h-12 shrink-0 justify-center border-b border-border px-2 py-0">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" className="h-10" asChild>
                            <NavLink to="/">
                                <img src={sidebarLogo} alt="" width="32" height="32" className="size-8 shrink-0 object-contain" />
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold">BooleanSMTP</span>
                                </div>
                            </NavLink>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {/* The primitive nests its own provider with no delay; these are the app's settings. */}
            <TooltipProvider delayDuration={TOOLTIP_DELAY_MS} skipDelayDuration={TOOLTIP_SKIP_DELAY_MS}>
                <SidebarContent>
                    <NavGroup label={t("layout.section_main", "Main menu")} items={[...mainNavItems, ...extensionNavItems(routes, "main")]} />
                    <NavGroup label={t("layout.section_tools", "Tools")} items={[...toolsNavItems, ...extensionNavItems(routes, "tools")]} />
                    <NavGroup label={t("layout.section_settings", "Settings")} items={[...settingsNavItems, ...extensionNavItems(routes, "settings")]} />
                    <ExtensionNavGroup label={t("layout.section_extensions", "Extensions")} items={menuItems} />
                    <NavGroup label={t("layout.section_about", "About")} items={aboutNavItems} />
                </SidebarContent>
            </TooltipProvider>
            <SidebarRail />
        </Sidebar>
    )
}
