import { Copy } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { translate } from '@/hooks/useTranslations';

function copyToClipboard(text) {
    navigator.clipboard?.writeText(text);
    toast.success(translate('common.copied_to_clipboard', 'Copied to clipboard'));
}

// A plain error message can occasionally be long (a raw transport response, a
// multi-line SMTP error). Rendering that directly as a toast description lets
// the toast grow tall enough to break the page layout. This keeps the toast a
// fixed, scrollable size with its own copy button — not sonner's `action`
// slot, which the theme's toast already uses for the absolute-positioned
// close button and collides with it in the same corner.
export function showApiErrorToast(title, message, options = {}) {
    const text = typeof message === 'string' ? message : String(message ?? '');

    toast.error(title, {
        ...options,
        description: (
            <div className="relative mt-1 max-h-28 overflow-y-auto rounded-md border bg-muted/30 p-2 pr-7 font-mono text-xs break-words whitespace-pre-wrap">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    onClick={() => copyToClipboard(text)}
                    aria-label={translate('common.copy_error_message', 'Copy error message')}
                    className="absolute top-1 right-1 size-6 text-muted-foreground hover:bg-background hover:text-foreground"
                >
                    <Copy className="size-3.5" />
                </Button>
                {text}
            </div>
        ),
    });
}
