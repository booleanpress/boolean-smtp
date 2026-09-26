=== BooleanSMTP ===
Contributors: booleanpress
Tags: smtp, email log, amazon ses, gmail smtp, email alerts
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email through Amazon SES, Gmail, Microsoft 365 or any SMTP, with automatic fallback, retries, a full email log and failure alerts.

== Description ==

By default WordPress sends email with PHP's `mail()` on your web server: usually unauthenticated, often rate-limited or blocked by the host, and easy for mailbox providers to reject or file as spam. BooleanSMTP sends every email your site writes (password resets, WooCommerce orders, form notifications) through a real email service, authenticated with your own domain, and keeps a record of what happened to each one.

When a provider has a bad minute, BooleanSMTP doesn't give up. The message is retried on your fallback connection and then on your other connections, and you hear about it only if every attempt fails. Your connections and sign-ins are checked on a schedule, so a revoked key or an expired token shows up on your screen before a customer's email needs it.

= Send through the service you already use =

* **Amazon SES:** over the SES API in any region, or over SMTP in every region where AWS offers it. Keep the keys in the plugin (encrypted), in `wp-config.php` constants or in environment variables, or on EC2 let the plugin use the instance's IAM role (opt-in).
* **Gmail and Google Workspace:** over the Gmail API or SMTP, including Workspace's SMTP relay, signed in with OAuth through your own Google app.
* **Microsoft 365 and Outlook:** over the Microsoft Graph API (Microsoft is retiring password sign-in for SMTP), signed in with OAuth through your own Microsoft Entra app.
* **Any SMTP server:** your host's mail server, or the SMTP relay of SendGrid, Mailgun, Postmark, Brevo, SMTP2GO or any other service that offers one. You set the host, port, encryption and authentication.
* **PHP mail():** the server's default, now logged and monitored like everything else.

= Several connections, one clear route =

* Each From address has its own connection, so `billing@` can send through Amazon SES while `hello@` sends through Google Workspace, automatically, by sender.
* Choose a default connection for everything else, and a fallback connection that takes over the moment a send fails.

= Failures are retried before they become lost email =

* A failed send is retried at once on your fallback connection.
* With *Retry on other connections* on, it is then retried on your other active connections: up to two more attempts, a minute or more apart.
* Alerts fire only when the last attempt has failed, so a passing hiccup doesn't page you.
* Developers can route bulk mail (newsletters, digests) into a background queue that WP-Cron works through within a minute, or that `wp boolean-smtp queue:work` sends at once.

= Know before your customers do =

* Health checks test your connections on a schedule, every 15 minutes by default, and show the result on the Mailers screen.
* Google and Microsoft sign-ins are refreshed on a schedule. If a refresh fails, you're alerted right away so you can reconnect.
* Alerts in Slack, Discord and Telegram: when an email finally fails, when a connection fails its health check, and when a sign-in can no longer be refreshed. Connect any or all three; for Telegram, the plugin finds your chat ID for you.

= Every email, on record =

* Every message is logged with its recipients, subject, status, connection, error, timing and content. Search and filter the log, open any message to preview it, and resend it.
* Keep logs from 7 days to a year (30 days by default), cleaned up daily.
* Send a test email through the default connection or any other, and read the full diagnostics on screen.
* **Email simulation** for staging and development sites: nothing leaves the site, every message is still logged, and a red "Email: Disabled" badge in the admin bar makes sure nobody forgets it's on.

= Switch from another SMTP plugin without breaking your email =

Bring your connections and email log over from FluentSMTP, WP Mail SMTP, Post SMTP, Easy WP SMTP, SureMail and GoSMTP, from the setup wizard or the Migration tool:

* See first what will be imported, converted or skipped, and why.
* Connections arrive as inactive drafts. Test them before switching anything on.
* SendGrid and Postmark API connections are converted to the same provider's SMTP relay, so they keep working.
* When an imported connection uses a From address you already have, you choose: keep yours (the default) or replace it.

= Built for developers and site operators =

* WP-CLI: `wp boolean-smtp test-email`, `connections:list`, `logs:export`, `queue:work`, `import`, `migrate` and `hooks:list`.
* A REST API (`booleansmtp/v1`) for administrators, and documented `boolean_smtp_` actions and filters; `wp boolean-smtp hooks:list` prints them.
* `boolean_smtp_mail()` sends through the full pipeline (routing, logging, fallback) from your own code, and `boolean_smtp_queue()` hands a message to the background worker.

= Secure by default =

* Passwords, API keys and OAuth tokens are encrypted at rest (AES-256-CBC with HMAC-SHA256) with `BOOLEAN_SMTP_ENCRYPTION_KEY` from `wp-config.php` when you define it, otherwise your site's authentication key, otherwise a key generated once for the site. They are never shown in full.
* Every admin screen and REST route requires the `manage_options` capability.
* No telemetry, no licence check, and no connection to anything you didn't set up.

= A modern admin =

* A fast admin app with light and dark themes.
* A dashboard with delivery totals for the period you choose, a traffic chart, system status and recent activity.

= Works with everything that sends through wp_mail() =

WooCommerce, contact-form and membership plugins, and WordPress itself: anything that sends through `wp_mail()` goes through BooleanSMTP, with nothing to change on its side.

= Source code =

The admin screen ships compiled. Its readable source, with the build instructions, is on [GitHub](https://github.com/booleanpress/boolean-smtp).

== External services ==

BooleanSMTP connects only to the services you set up, and only for what you set them up to do. Nothing is contacted when the plugin is activated, and no usage data is collected. Each service's own terms and privacy policy apply to what it receives.

**Email providers.** BooleanSMTP contacts a connection's provider when you save or test the connection, when its health check or sign-in refresh runs, and whenever WordPress sends an email through it. Sending transmits the message (sender, recipients, subject, body, headers and attachments) together with the connection's sign-in (password, API key or OAuth token).

* Google (Gmail, Google Workspace): `gmail.googleapis.com`, `oauth2.googleapis.com`, `accounts.google.com`, `smtp.gmail.com`, `smtp-relay.gmail.com`.
* Microsoft (Microsoft 365, Outlook): `graph.microsoft.com`, `login.microsoftonline.com`.
* Amazon Web Services (Amazon SES): `email.<region>.amazonaws.com` for the API and `email-smtp.<region>.amazonaws.com` for SMTP. If you turn on EC2 instance-role credentials, the plugin also reads temporary credentials from the instance metadata address `169.254.169.254`, which stays inside the server.
* SendGrid and Postmark SMTP relays: only when you import a SendGrid or Postmark connection from another SMTP plugin; it becomes a Custom SMTP connection to `smtp.sendgrid.net` or `smtp.postmarkapp.com`.
* Custom SMTP: the server you enter.

**Sign-in relay (BooleanPress).** When you connect Google or Microsoft with OAuth, the provider sends its one-time authorization code to `https://oauth.booleansmtp.com/<provider>`, operated by BooleanPress, which immediately redirects your browser back to your site's admin with it. The relay receives only that single-use code and the signed state value that identifies your site, stores neither, and never sees your client secret or any token; your site exchanges the code with the provider directly. To use your site's own callback URL instead, define `BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS` as `true` in `wp-config.php`. [Terms](https://booleansmtp.com/terms/), [Privacy Policy](https://booleansmtp.com/privacy/)

**Alerts.** BooleanSMTP posts a short notice to the webhook or bot you configured when you set up or test an alert, and whenever the alert fires: an email finally failed, a connection failed its health check, or a sign-in could not be refreshed. The notice says what happened. For a failed email it carries the recipient, the subject and the mail provider, with a link to the entry in your site's email log; for a connection or sign-in problem, the connection's name and provider, and for a sign-in the kind of failure (for example, token expired). Raw error messages, credentials and message bodies are never included.

* Slack: your incoming-webhook URL on `hooks.slack.com`.
* Discord: your webhook URL on `discord.com` or `discordapp.com` (including their `canary.` and `ptb.` subdomains).
* Telegram: the Bot API at `api.telegram.org`, using your bot token. The **Detect** button also asks the Bot API for your bot's recent updates (`getUpdates`) to find the chat ID.
* If you mark a Slack or Discord webhook as proxied through a third-party relay, alerts go to the HTTPS address you entered instead.

== Privacy ==

* Email logs, including message bodies, are stored only in your own database and are pruned by the retention setting. Deleting the plugin keeps them, along with your connections and settings, unless you turn on **Settings → Delete data on uninstall** first.
* Passwords, API keys and OAuth tokens are encrypted before they are stored and are never shown in full in the admin screens.
* Log files are off by default. The SMTP transcript of a send is kept only when a developer enables it with the `boolean_smtp_should_store_smtp_transcript` filter: for 7 days, under `wp-content/uploads/boolean-smtp/logs/debug-sessions/`, with the login exchange hidden and a file name that cannot be guessed.

== Installation ==

1. Install BooleanSMTP from **Plugins → Add New**, or upload it to `/wp-content/plugins/`, then activate it.
2. Open **BooleanSMTP** in the admin menu, choose **Mailers** and add your first connection: Amazon SES, Google, Microsoft, any SMTP server or PHP mail. The setup wizard that opens after activation walks you through the same steps.
3. Send a test email from **Test Email**. The result and full diagnostics appear on screen and in the log.
4. Optional: choose a fallback connection under **Settings**, and connect Slack, Discord or Telegram under **Alerts**.

== Frequently Asked Questions ==

= Why are my WordPress emails not arriving, or landing in spam? =

WordPress sends mail with PHP's `mail()` by default, usually without authentication, so hosts throttle it and mailbox providers reject it or file it as spam. BooleanSMTP sends through an authenticated email service or SMTP server using your own domain. For the best inbox placement, also publish SPF, DKIM and DMARC records for your domain as your email provider describes.

= Does the plugin phone home or need a licence? =

No. The plugin contacts only the mail providers and alert services you configure (see *External services*). There is no licence check and no telemetry.

= Can I send through Amazon SES? =

Yes, over the SES API in any region, or over SMTP in every region where AWS offers it. Enter the keys in the plugin (they are encrypted), define them in `wp-config.php` (`BOOLEANSMTP_AWS_ACCESS_KEY_ID`, `BOOLEANSMTP_AWS_SECRET_ACCESS_KEY`, `BOOLEANSMTP_AWS_REGION`), set them as environment variables, or on EC2 let the plugin use the instance's IAM role by defining `BOOLEANSMTP_AWS_ENABLE_IMDS_ROLE_SOURCE` as `true`.

= Can I send through Gmail or Google Workspace? =

Yes. Create an OAuth client in your Google Cloud project, paste its ID and secret into a Google connection, and sign in. Mail then goes out over the Gmail API, or over SMTP if you prefer.

= Can I send through Microsoft 365 or Outlook? =

Yes. Register an app in Microsoft Entra, paste its details into a Microsoft connection, and sign in. Mail goes out over the Microsoft Graph API.

= Can I use SendGrid, Mailgun, Postmark or Brevo? =

Yes, through each service's SMTP relay: create a Custom SMTP connection with the host, port and credentials from the provider's dashboard.

= Can I use it without the OAuth relay? =

Yes. Define `BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS` as `true` in `wp-config.php`, and register your site's own callback URL (shown on the connection form) in your Google or Microsoft OAuth application.

= What happens when a send fails? =

The message is retried once on the fallback connection you chose. With *Retry on other connections* on, it is then retried on your other active connections, up to two more attempts a minute or more apart. Alerts fire only when the last attempt has failed.

= Is there a queue? =

A developer can route mail into a background queue: return `true` from the `boolean_smtp_queue_should_enqueue` filter for the messages that should wait (a newsletter, for example), or call `boolean_smtp_queue()` directly. The queue runs from WP-Cron within a minute of a message being queued, or at once with `wp boolean-smtp queue:work`. Nothing is queued unless a developer asks for it.

= Can I move from another SMTP plugin? =

Yes. The setup wizard offers the import when it finds FluentSMTP, WP Mail SMTP, Post SMTP, Easy WP SMTP, SureMail or GoSMTP, and the **Migration** tool is always one search away (press Cmd+K or Ctrl+K in the BooleanSMTP admin). It shows what can be imported, and brings the connections over as inactive drafts, with the email log if you want it. `wp boolean-smtp import` does the same from the command line.

= What happens to my data when I delete the plugin? =

Nothing, by default. Deactivating never deletes anything, and deleting the plugin keeps your connections, email logs and settings in the database, so a reinstall picks up where you left off; only its scheduled tasks and log files are removed. To delete everything along with the plugin, turn on **Settings → Delete data on uninstall** before deleting it. Adding `define('BOOLEAN_SMTP_PRESERVE_DATA', true);` to `wp-config.php` keeps the data even when that setting is on.

= Where are the email logs stored, and for how long? =

In your site's database, in the plugin's own tables. The retention period is set under **Settings** (from 7 days to 1 year, 30 days by default) and enforced by a daily cleanup. A developer can keep logs forever by returning `0` from the `boolean_smtp_log_retention_days` filter.

= Does BooleanSMTP write log files? =

Not unless you ask it to. A developer can switch on the `app` channel (the plugin's warnings and errors) and the `core` channel (the framework's messages) with the `boolean_smtp_log_file_enabled` filter from a must-use plugin. The files go to `wp-content/uploads/boolean-smtp/logs/` with unguessable names. Each channel starts a new file every day and at 5 MB, and keeps 14 days (`boolean_smtp_log_file_retention_days`); the whole folder is held under 50 MB. On nginx, which ignores `.htaccess`, also deny the folder in your server configuration: `location ~* /wp-content/uploads/boolean-smtp/logs/ { deny all; }`.

= Does it work with WP-CLI? =

Yes: `wp boolean-smtp` lists the commands (test email, connections, log export, queue worker, import from another SMTP plugin, migrations, hooks).

== Screenshots ==

1. Overview: delivery totals for the chosen period, the traffic chart, system status and recent activity.
2. Mailers: every connection with its sender, mode, status and health; the default and fallback are marked.
3. Add a mailer: Amazon SES, Google, Microsoft, any SMTP server or PHP mail.
4. Email logs: search and filter every message, see failures at a glance and resend them.
5. A logged email: recipient, sender, mailer, delivery result and a preview of the message.
6. Test email: send through the default or any connection and read the result.
7. Settings: default and fallback connection, retries on other connections, logging and retention.
8. Alerts: Slack, Discord and Telegram, each connected once.
9. Migration: bring the connections and email log of another SMTP plugin over as drafts.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
