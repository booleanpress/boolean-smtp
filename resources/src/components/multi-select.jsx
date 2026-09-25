import * as React from "react"
import { Check, ChevronsUpDown, X } from "lucide-react"

import { cn } from "@/lib/utils"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { useTranslations } from "@/hooks/useTranslations"

/**
 * Multi-select built on the shadcn Combobox pattern (Popover + Command).
 *
 * @param {{ value: string, label: string }[]} options
 * @param {string[]} selected
 * @param {(next: string[]) => void} onChange
 */
export function MultiSelect({ options = [], selected = [], onChange, placeholder, className, id }) {
  const { t } = useTranslations()
  const [open, setOpen] = React.useState(false)
  const selectedItems = options.filter(option => selected.includes(option.value))

  const toggle = value => {
    onChange(selected.includes(value) ? selected.filter(v => v !== value) : [...selected, value])
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className={cn("h-auto min-h-9 w-full justify-between font-normal", className)}
        >
          <span className="flex flex-1 flex-wrap items-center gap-1 text-left">
            {selectedItems.length === 0 ? (
              <span className="text-muted-foreground">{placeholder || t("common.select_items", "Select items...")}</span>
            ) : (
              selectedItems.map(item => (
                <Badge key={item.value} variant="secondary" className="gap-1 pr-1">
                  {item.label}
                  <span
                    role="button"
                    tabIndex={0}
                    aria-label={t("common.remove_item", "Remove {{item}}", { item: item.label })}
                    className="rounded-sm opacity-70 outline-none hover:opacity-100 focus-visible:ring-2 focus-visible:ring-ring"
                    onClick={event => {
                      event.stopPropagation()
                      toggle(item.value)
                    }}
                    onKeyDown={event => {
                      if (event.key === "Enter" || event.key === " ") {
                        event.preventDefault()
                        event.stopPropagation()
                        toggle(item.value)
                      }
                    }}
                  >
                    <X className="size-3" />
                  </span>
                </Badge>
              ))
            )}
          </span>
          <ChevronsUpDown className="opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
        <Command>
          <CommandInput placeholder={t("common.search", "Search")} />
          <CommandList>
            <CommandEmpty>{t("common.no_items_found", "No items found.")}</CommandEmpty>
            <CommandGroup>
              {options.map(option => {
                const isSelected = selected.includes(option.value)
                return (
                  <CommandItem key={option.value} value={option.label} onSelect={() => toggle(option.value)}>
                    <Check className={cn(isSelected ? "opacity-100" : "opacity-0")} />
                    {option.label}
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
