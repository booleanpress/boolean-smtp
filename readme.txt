=== BooleanSMTP ===
Contributors: booleanpress
Tags: smtp, email, wp_mail, email log, mailer
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable email delivery for WordPress: SMTP and API mailers, email logging, retries with fallback, delivery alerts and developer tools.

== Description ==

BooleanSMTP replaces the way WordPress sends email so messages from your site actually arrive. Connect one or more mail services, log every message with its delivery result, retry failures on a fallback connection, and get told when something breaks.

**Mail services**

* SMTP with any provider, plus one-click presets for Gmail / Google Workspace, Microsoft 365 / Outlook, Zoho Mail, Amazon SES, SendGrid, Mailgun, Postmark, Brevo, SparkPost, MailerSend, Mandrill, Netcore, SendLayer, SMTP2GO, SMTP.com and Elastic Email.
* API delivery for Gmail, Microsoft 365 and Amazon SES (no SMTP port needed).
* OAuth sign-in for Google, Microsoft and Zoho using your own OAuth application.
* Several connections at once, with a primary connection and automatic fallback when the primary fails. Each From address uses one connection, so every message goes out through the connection that owns its sender.
* SMTP or API delivery for providers that offer both (SendGrid, Amazon SES, Gmail, and others).

**Logging and reliability**

* Every email logged with recipient, subject, status, provider, error message and delivery time; resend any message from the log.
* Automatic retries on your other connections when a send fails, and a background queue developers can route mail into.
* Log retention you control (default 30 days).
* Test email tool with full diagnostics and connection health checks.

**Alerts**

* Slack, Discord and Telegram notifications when a message finally fails to send.

**Import**

* Bring connections and email logs over from FluentSMTP, WP Mail SMTP, Post SMTP, Easy WP SMTP, SureMail and GoSMTP — assessed first, imported as inactive drafts, tested before anything is switched on. When an imported connection uses a From address this site already has, you choose whether to keep your connection (the default) or replace it.

**For developers and site operators**

* WP-CLI: `wp boolean-smtp test-email`, `connections:list`, `logs:export`, `queue:work`, `import`, `migrate`, `hooks:list`.
* A documented REST API (`booleansmtp/v1`) protected by the `manage_options` capability, and public hooks under the `boolean_smtp_` prefix (`wp boolean-smtp hooks:list` prints them).
* Credentials are encrypted at rest (AES-256-CBC with HMAC-SHA256) using your site's authentication key, or a key generated for the site when none is defined (`BOOLEAN_SMTP_ENCRYPTION_KEY` in `wp-config.php` overrides both).

= Source code =

The admin screen ships compiled. Its readable source, with the build instructions, is at https://github.com/booleanpress/boolean-smtp.

== External services ==

The plugin only contacts a service when you configure a connection or an alert for it, and only to do what that connection or alert is for. Nothing is sent anywhere by default, and no usage data is collected.

**Mail providers** — when WordPress sends an email through a connection you created, the message (recipients, subject, body, headers, attachments) and the credentials you entered for that connection are sent to the provider you chose:

* Google (Gmail / Google Workspace) — `smtp.gmail.com` / `smtp-relay.gmail.com`, `gmail.googleapis.com`, `oauth2.googleapis.com`, `accounts.google.com`. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy)
* Microsoft (Microsoft 365 / Outlook) — `graph.microsoft.com`, `login.microsoftonline.com`. [Terms](https://www.microsoft.com/servicesagreement), [Privacy](https://privacy.microsoft.com/privacystatement)
* Zoho Mail — `smtp.zoho.com`, `mail.zoho.com` and the `accounts.zoho.*` sign-in host for your region. [Terms](https://www.zoho.com/terms.html), [Privacy](https://www.zoho.com/privacy.html)
* Amazon SES — the SES endpoint for your region (`email.<region>.amazonaws.com`). On an EC2 instance the plugin can read temporary credentials from the instance's own metadata service (`169.254.169.254`, local to the instance). [Terms](https://aws.amazon.com/service-terms/), [Privacy](https://aws.amazon.com/privacy/)
* SendGrid ([Terms](https://www.twilio.com/legal/tos), [Privacy](https://www.twilio.com/legal/privacy)), Mailgun ([Terms](https://www.mailgun.com/legal/terms/), [Privacy](https://www.mailgun.com/legal/privacy-policy/)), Postmark ([Terms](https://postmarkapp.com/terms-of-service), [Privacy](https://postmarkapp.com/privacy-policy)), Brevo ([Terms](https://www.brevo.com/legal/termsofuse/), [Privacy](https://www.brevo.com/legal/privacypolicy/)), SparkPost ([Terms](https://www.sparkpost.com/policies/tou/), [Privacy](https://www.sparkpost.com/policies/privacy/)), MailerSend ([Terms](https://www.mailersend.com/legal/terms-of-service), [Privacy](https://www.mailersend.com/legal/privacy-policy)), Mandrill / Mailchimp ([Terms](https://mailchimp.com/legal/terms/), [Privacy](https://www.intuit.com/privacy/statement/)), Netcore ([Terms](https://netcorecloud.com/terms-of-use/), [Privacy](https://netcorecloud.com/privacy-policy/)), SendLayer ([Terms](https://sendlayer.com/terms/), [Privacy](https://sendlayer.com/privacy-policy/)), SMTP2GO ([Terms](https://www.smtp2go.com/terms/), [Privacy](https://www.smtp2go.com/privacy/)), SMTP.com ([Terms](https://www.smtp.com/terms-of-service/), [Privacy](https://www.smtp.com/privacy-policy/)), Elastic Email ([Terms](https://elasticemail.com/terms-of-use), [Privacy](https://elasticemail.com/privacy-policy)) — each reached at its own SMTP or API host with the credentials you entered.

**OAuth sign-in relay** — when you connect Google, Microsoft or Zoho with OAuth, the provider sends its one-time authorization code to `https://oauth.booleansmtp.com/<provider>` (operated by BooleanPress), which immediately redirects your browser back to BooleanSMTP in your site's admin with it. The relay receives only that single-use code and the signed state value that names your site, and stores neither; it never receives your client secret or any token, and your site exchanges the code for tokens directly with the provider. Define `BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS` as `true` in `wp-config.php` to use your site's callback URL directly and skip the relay. [BooleanPress privacy policy](https://booleansmtp.com/privacy/)

**Alerts** — when you enabled an alert channel, a short notice is posted to the webhook or bot you configured: when a message finally fails (recipient, subject, provider, error, a link to your site's log), and when a connection fails its health check or its sign-in can no longer be refreshed (the connection's name and provider, never its credentials). The services are Slack (`hooks.slack.com`; [Terms](https://slack.com/terms-of-service), [Privacy](https://slack.com/privacy-policy)), Discord (`discord.com`; [Terms](https://discord.com/terms), [Privacy](https://discord.com/privacy)) or Telegram (`api.telegram.org`; [Terms](https://telegram.org/tos), [Privacy](https://telegram.org/privacy)).


== Privacy ==

* Email logs, including message bodies, are stored in your own database only and pruned by the retention setting. Deleting the plugin keeps them, with your connections and settings, unless you turn on **Settings → Delete data on uninstall** first.
* Passwords, API keys and OAuth tokens are encrypted before they are stored and are never shown in full in the admin screens.
* Debug logging is off by default; when you turn a channel on, secret-shaped values are masked in the log files. The SMTP transcript of a send is kept as a file under `wp-content/uploads/booleanpress/boolean-smtp/logs/debug-sessions/` only when a developer enables it with the `boolean_smtp_should_store_smtp_transcript` filter — for 7 days, with the login exchange hidden and a name that cannot be guessed.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
2. Open **BooleanSMTP → Mailers** and add your first connection (SMTP, API or OAuth).
3. Send a test message from **BooleanSMTP → Test Email**; the result and full diagnostics appear on screen and in the log.

== Frequently Asked Questions ==

= Does the plugin phone home or need a licence? =

No. The plugin contacts only the mail providers and alert services you configure (see *External services*). There is no licence check and no telemetry.

= Can I use it without the OAuth relay? =

Yes. Define `BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS` as `true` in `wp-config.php` and register your site's own callback URL (shown on the connection form) in your Google, Microsoft or Zoho OAuth application.

= What happens to my data when I delete the plugin? =

Nothing, by default. Deactivating never deletes anything, and deleting the plugin keeps your connections, email logs and settings in the database, so a reinstall picks up where you left off; only its scheduled tasks and debug log files are removed. To delete everything along with the plugin, turn on **Settings → Delete data on uninstall** before deleting it. Adding `define('BOOLEAN_SMTP_PRESERVE_DATA', true);` to `wp-config.php` keeps the data even when that setting is on.

= Where are the logs stored and for how long? =

In your site's database, in the plugin's own tables. The retention period is set under **Settings** (from 7 days to 1 year, 30 days by default) and enforced by a daily cleanup; a developer can keep logs forever with the `boolean_smtp_log_retention_days` filter returning `0`.

= Does BooleanSMTP write log files? =

Not unless you ask it to. Email logs live in the database (above); log files are off by default. A developer can switch a channel on — `app`, `mail`, `requests`, `queries` or `core` — with the `boolean_smtp_log_file_enabled` filter from a must-use plugin, or with the `BOOLEAN_SMTP_DEBUG_*` constants. The files go to `wp-content/uploads/booleanpress/boolean-smtp/logs/` with unguessable names; each channel starts a new file every day and at 5 MB, keeps 14 days (`boolean_smtp_log_file_retention_days`), and the whole folder is held under 50 MB. On nginx, which ignores `.htaccess`, also deny the folder in your server configuration: `location ~* /wp-content/uploads/booleanpress/.+/logs/ { deny all; }`.

= What happens when a send fails? =

The message is retried once on the fallback connection you chose, and — with *Retry on other connections* on — on your other active connections afterwards, up to two more attempts a minute or more apart. Alerts fire only when the last attempt has failed.

= Is there a queue? =

A developer can route mail into a background queue: return `true` from the `boolean_smtp_queue_should_enqueue` filter for the messages that should wait (a newsletter, for example), or call `boolean_smtp_queue()` directly. The queue runs from WP-Cron within a minute of a message being queued, or at once with `wp boolean-smtp queue:work`. Nothing is queued unless a developer asks for it.

= Does it work with WP-CLI? =

Yes: `wp boolean-smtp` lists the commands (test email, connections, log export, queue worker, import from another SMTP plugin, migrations, hooks).

== Screenshots ==

1. Overview: delivery totals for the chosen period, the traffic chart, system status and recent activity.
2. Mailers: every connection with its sender, mode, status and health; the default and fallback are marked.
3. Add a mailer: pick a provider, from Amazon SES and Google Workspace to any SMTP server.
4. Email logs: search and filter every message, see failures at a glance and resend them.
5. A logged email: recipient, sender, mailer, delivery result and a preview of the message.
6. Test email: send through the default or any connection and read the result.
7. Settings: default and fallback connection, retries on other connections, logging and retention.
8. Alerts: failure alerts to Slack, Discord or Telegram.
9. Migration: bring the connections and email log of another SMTP plugin over as drafts.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
