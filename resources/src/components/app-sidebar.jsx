import { NavLink, useLocation } from "react-router"
import {
    Bell,
    ExternalLink,
    FileText,
    Info,
    LayoutDashboard,
    Plug,
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
import { useProCapability } from "@/hooks/useProCapability"
import { useAdminExtensions } from "@/hooks/useAdminExtensions"
import { PRO_NAV_ITEMS, PRO_TOOLS_NAV_ITEMS } from "@/config/proPages"

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

export function AppSidebar(props) {
    const { t } = useTranslations()
    const { isProInstalled } = useProCapability()
    const { menuItems } = useAdminExtensions()
    const admin = window.BooleanSmtpAdmin || {}
    const version = admin.version || ""

    return (
        <Sidebar collapsible="icon" className="h-full" {...props}>
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <NavLink to="/">
                                <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                    <Send className="size-4" />
                                </div>
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold">BooleanSMTP</span>
                                    {version && <span className="truncate text-xs text-muted-foreground">v{version}</span>}
                                </div>
                            </NavLink>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {/* The primitive nests its own provider with no delay; these are the app's settings. */}
            <TooltipProvider delayDuration={TOOLTIP_DELAY_MS} skipDelayDuration={TOOLTIP_SKIP_DELAY_MS}>
                <SidebarContent>
                    <NavGroup label={t("layout.section_main", "Main menu")} items={mainNavItems} />
                    {/* The add-on's pages are listed as soon as it runs; each one says for itself
                        what a licence unlocks, which is friendlier than a menu that hides them. */}
                    {isProInstalled && (
                        <NavGroup label={t("layout.section_pro", "Pro")} items={PRO_NAV_ITEMS} />
                    )}
                    <NavGroup label={t("layout.section_tools", "Tools")} items={isProInstalled ? [...toolsNavItems, ...PRO_TOOLS_NAV_ITEMS] : toolsNavItems} />
                    <NavGroup label={t("layout.section_settings", "Settings")} items={settingsNavItems} />
                    <ExtensionNavGroup label={t("layout.section_extensions", "Extensions")} items={menuItems} />
                    <NavGroup label={t("layout.section_about", "About")} items={aboutNavItems} />
                </SidebarContent>
            </TooltipProvider>
            <SidebarRail />
        </Sidebar>
    )
}
