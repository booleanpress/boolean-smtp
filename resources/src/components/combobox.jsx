import * as React from "react"
import { Check, ChevronsUpDown } from "lucide-react"

import { cn } from "@/lib/utils"
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
 * Searchable single-select, built on the same Popover + Command pattern as MultiSelect.
 * Use for long option lists (e.g. AWS regions) where a plain Select would otherwise grow
 * to fit every option instead of staying a compact, scrollable list.
 *
 * @param {{ value: string, label: string }[]} options
 */
export function Combobox({
  id,
  value,
  onValueChange,
  options = [],
  placeholder,
  searchPlaceholder,
  emptyText,
  className,
  invalid,
}) {
  const { t } = useTranslations()
  const [open, setOpen] = React.useState(false)
  const selected = options.find(option => option.value === value)

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-invalid={invalid}
          className={cn("w-full justify-between font-normal", className)}
        >
          <span className={cn("truncate text-left", !selected && "text-muted-foreground")}>
            {selected ? selected.label : (placeholder || t("common.select_option", "Select..."))}
          </span>
          <ChevronsUpDown className="opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start" side="bottom" sideOffset={4}>
        <Command>
          <CommandInput placeholder={searchPlaceholder || t("common.search", "Search")} />
          <CommandList className="max-h-[200px]">
            <CommandEmpty>{emptyText || t("common.no_items_found", "No items found.")}</CommandEmpty>
            <CommandGroup>
              {options.map(option => (
                <CommandItem
                  key={option.value}
                  value={option.label}
                  onSelect={() => {
                    onValueChange(option.value)
                    setOpen(false)
                  }}
                >
                  <Check className={cn(option.value === value ? "opacity-100" : "opacity-0")} />
                  {option.label}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
