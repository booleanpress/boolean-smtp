import * as React from "react"
import { Eye, EyeOff } from "lucide-react"

import { Button } from "@/components/ui/button"
import { InputGroup, InputGroupAddon, InputGroupInput } from "@/components/ui/input-group"
import { useTranslations } from "@/hooks/useTranslations"

/**
 * Project composition: shadcn InputGroup with a show/hide toggle. The field holds a provider
 * secret, never the WordPress login, so it opts out of password-manager autofill (which would
 * otherwise put the saved admin password here and the admin username in the field before it).
 *
 * @since 1.0.0
 */
function PasswordInput({ className, ...props }) {
  const { t } = useTranslations()
  const [visible, setVisible] = React.useState(false)
  const label = visible ? t("common.hide_password", "Hide password") : t("common.show_password", "Show password")

  return (
    <InputGroup className={className}>
      <InputGroupInput type={visible ? "text" : "password"} autoComplete="new-password" data-1p-ignore="" data-lpignore="true" {...props} />
      <InputGroupAddon align="inline-end">
        <Button
          type="button"
          variant="ghost"
          size="icon-xs"
          onClick={() => setVisible(v => !v)}
          aria-label={label}
          aria-pressed={visible}
        >
          {visible ? <EyeOff /> : <Eye />}
        </Button>
      </InputGroupAddon>
    </InputGroup>
  )
}

export { PasswordInput }
