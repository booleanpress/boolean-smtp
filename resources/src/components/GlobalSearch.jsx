import * as React from "react"
import { useNavigate } from "react-router"
import {
  LayoutDashboard,
  Plug,
  FileText,
  Send,
  Database,
  Info,
  Settings,
  Bell,
  Plus,
  Mail,
  Zap,
  Search,
} from "lucide-react"

import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command"
import { Button } from "@/components/ui/button"
import { Kbd, KbdGroup } from "@/components/ui/kbd"
import api from "@/services/api"
import { useTranslations } from "@/hooks/useTranslations"

// Shown in the shortcut hint only; the handler below accepts either modifier.
const isMac = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)

export function GlobalSearch() {
  const { t } = useTranslations()
  const [open, setOpen] = React.useState(false)
  const [logs, setLogs] = React.useState([])
  const [connections, setConnections] = React.useState([])
  const navigate = useNavigate()

  React.useEffect(() => {
    const down = (e) => {
      if (e.key === "k" && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        setOpen((open) => !open);
      }
    }

    // Use capture phase to intercept the event before Astra/other plugins
    document.addEventListener("keydown", down, { capture: true });
    return () => document.removeEventListener("keydown", down, { capture: true });
  }, [])

  React.useEffect(() => {
    if (open) {
      loadData()
    }
  }, [open])

  async function loadData() {
    try {
      const [logsRes, connRes] = await Promise.all([
        api.get('logs', { per_page: 3 }),
        api.get('connections')
      ])
      setLogs(logsRes.data?.data || logsRes.data || [])
      setConnections(connRes.data?.slice(0, 3) || [])
    } catch (err) {
      console.error('Failed to load search data:', err)
    }
  }

  const runCommand = React.useCallback((command) => {
    setOpen(false)
    command()
  }, [])

  return (
    <>
      {/* Input-styled trigger (icon-only below `md`); the real input lives in the dialog. */}
      <Button
        variant="outline"
        size="icon-sm"
        onClick={() => setOpen(true)}
        title={t('global_search.trigger_title', 'Search')}
        aria-label={t('global_search.trigger_title', 'Search')}
        aria-keyshortcuts={isMac ? "Meta+K" : "Control+K"}
        className="text-muted-foreground shadow-none md:w-48 md:justify-start md:px-2.5 md:font-normal lg:w-64"
      >
        <Search className="shrink-0" />
        <span className="hidden truncate md:inline lg:hidden">{t('global_search.trigger_short', 'Search…')}</span>
        <span className="hidden truncate lg:inline">{t('global_search.trigger_placeholder', 'Type to search…')}</span>
        <KbdGroup className="ml-auto hidden md:inline-flex">
          <Kbd>{isMac ? "⌘" : "Ctrl"}</Kbd>
          <Kbd>K</Kbd>
        </KbdGroup>
      </Button>
      <CommandDialog
        open={open}
        onOpenChange={setOpen}
        title={t('global_search.title', 'Global Search')}
        description={t('global_search.placeholder', 'Type a command or search...')}
      >
        <CommandInput placeholder={t('global_search.placeholder', 'Type a command or search...')} />
        <CommandList>
          <CommandEmpty>{t('global_search.empty', 'No results found.')}</CommandEmpty>
          
          {logs.length > 0 && (
            <>
              <CommandGroup heading={t('global_search.group_recent_logs', 'Recent Email Logs')}>
                {logs.map(log => (
                  <CommandItem key={log.id} onSelect={() => runCommand(() => navigate(`/logs/${log.id}`))}>
                    <Mail className="opacity-70" />
                    <div className="flex flex-col flex-1 min-w-0">
                      <span className="truncate font-medium">{log.subject || t('global_search.no_subject', '(No Subject)')}</span>
                      <span className="truncate text-xs text-muted-foreground">{t('global_search.log_to_provider', 'To: {{to}} • {{provider}}', { to: log.to, provider: log.provider })}</span>
                    </div>
                  </CommandItem>
                ))}
                <CommandItem onSelect={() => runCommand(() => navigate("/logs"))}>
                  <FileText  />
                  <span className="font-medium text-primary">{t('global_search.view_all_logs', 'View all logs')}</span>
                </CommandItem>
              </CommandGroup>
              <CommandSeparator />
            </>
          )}

          {connections.length > 0 && (
            <>
              <CommandGroup heading={t('global_search.group_mailers', 'Mailers')}>
                {connections.map(conn => (
                  <CommandItem key={conn.id} onSelect={() => runCommand(() => navigate(`/connections`))}>
                    <Zap className="opacity-70" />
                    <div className="flex flex-col flex-1 min-w-0">
                      <span className="truncate font-medium">{conn.name}</span>
                      <span className="truncate text-xs text-muted-foreground uppercase">{conn.driver} • {conn.status}</span>
                    </div>
                  </CommandItem>
                ))}
                <CommandItem onSelect={() => runCommand(() => navigate("/connections"))}>
                  <Plug  />
                  <span className="font-medium text-primary">{t('global_search.view_all_mailers', 'View all mailers')}</span>
                </CommandItem>
              </CommandGroup>
              <CommandSeparator />
            </>
          )}

          <CommandGroup heading={t('global_search.group_navigation', 'Navigation')}>
            <CommandItem onSelect={() => runCommand(() => navigate("/"))}>
              <LayoutDashboard  />
              <span>{t('global_search.nav_overview', 'Overview')}</span>
            </CommandItem>
            <CommandItem onSelect={() => runCommand(() => navigate("/connections"))}>
              <Plug  />
              <span>{t('global_search.nav_mailers', 'Mailers')}</span>
            </CommandItem>
            <CommandItem onSelect={() => runCommand(() => navigate("/logs"))}>
              <FileText  />
              <span>{t('global_search.nav_logs', 'Email Logs')}</span>
            </CommandItem>
          </CommandGroup>
          <CommandSeparator />
          <CommandGroup heading={t('global_search.group_tools', 'Tools')}>
            <CommandItem onSelect={() => runCommand(() => navigate("/tools/test"))}>
              <Send  />
              <span>{t('global_search.tool_test_email', 'Email Deliverability Test')}</span>
            </CommandItem>
            <CommandItem onSelect={() => runCommand(() => navigate("/tools/migration"))}>
              <Database  />
              <span>{t('global_search.tool_migration', 'Migration')}</span>
            </CommandItem>
            <CommandItem onSelect={() => runCommand(() => navigate("/connections/new"))}>
               <Plus  />
               <span>{t('global_search.tool_new_connection', 'New Connection')}</span>
            </CommandItem>
          </CommandGroup>
          <CommandSeparator />
          <CommandGroup heading={t('global_search.group_settings', 'Settings')}>
            <CommandItem onSelect={() => runCommand(() => navigate("/settings"))}>
              <Settings  />
              <span>{t('global_search.settings_general', 'General Settings')}</span>
            </CommandItem>
            <CommandItem onSelect={() => runCommand(() => navigate("/settings/notifications"))}>
              <Bell  />
              <span>{t('global_search.settings_notifications', 'Alerts & Notifications')}</span>
            </CommandItem>
          </CommandGroup>
          <CommandSeparator />
          <CommandGroup heading={t('global_search.group_about', 'About')}>
            <CommandItem onSelect={() => runCommand(() => navigate("/about"))}>
              <Info />
              <span>{t('global_search.nav_about', 'About BooleanSMTP')}</span>
            </CommandItem>
          </CommandGroup>
        </CommandList>
      </CommandDialog>
    </>
  )
}
