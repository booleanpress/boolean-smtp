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

**BooleanSMTP is a WordPress or WP Mail SMTP plugin that gets your site's email delivered.** It fixes the most common WordPress email problem, messages that never arrive or land in spam, by sending every email through a real, authenticated email service: Amazon SES, Gmail and Google Workspace, Microsoft 365 and Outlook, or any SMTP server.

Out of the box, WordPress sends email with PHP's `mail()` on your web server: usually unauthenticated, often rate-limited or blocked by the host, and easy for Gmail, Outlook and other mailbox providers to reject or file as spam. Password resets go missing, WooCommerce order emails never reach customers, and contact form notifications quietly disappear. BooleanSMTP sends them through your email provider instead, authenticated with your own domain for better deliverability, and keeps a record of what happened to every message.

Sending is only half the job. When a provider has a bad minute, BooleanSMTP doesn't give up: the message is retried on your fallback connection and then on your other connections, and you hear about it only if every attempt fails. Your connections and sign-ins are checked on a schedule, so a revoked key or an expired token shows up on your screen, or in Slack, Discord or Telegram, before a customer's email needs it.

**At a glance**

* **Reliable email delivery** for password resets, WooCommerce orders, contact forms, memberships and every other plugin that sends through `wp_mail()`.
* **Your choice of provider:** Amazon SES, Gmail and Google Workspace, Microsoft 365 and Outlook through the SES, Gmail and Microsoft Graph APIs, or any SMTP server, including the relays of SendGrid, Mailgun, Postmark, Brevo and SMTP2GO.
* **Automatic failover and retries:** a fallback connection takes over the moment a send fails, then your other connections take their turn.
* **A complete email log:** every message with its status, error, timing and content. Search it, preview any email and resend it.
* **Alerts that matter:** Slack, Discord and Telegram notices when an email has finally failed, a connection fails its health check or a sign-in can't be refreshed.
* **Sender-based routing:** each From address sends through its own connection.
* **Email simulation for staging:** nothing leaves a test site, and every message is still logged.
* **Painless switching:** bring your connections and email log over from six popular SMTP plugins, tested before anything goes live.
* **Private and secure:** no telemetry and no licence checks; passwords, API keys and tokens are encrypted, and logs stay in your own database.
* **Built for developers:** WP-CLI commands, a REST API, documented hooks and a background queue.

= Send through the service you already use =

* **Amazon SES:** over the SES API in any region, or over SMTP in every region where AWS offers it. Keep the keys in the plugin (encrypted), in `wp-config.php` constants or in environment variables, or on EC2 let the plugin use the instance's IAM role (opt-in).
* **Gmail and Google Workspace:** over the Gmail API, signed in with OAuth through your own Google app, or over SMTP with an app password, including Workspace's SMTP relay.
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

= A modern admin =

* A fast admin app with light and dark themes.
* A dashboard with delivery totals for the period you choose, a traffic chart, system status and recent activity.

= Works with everything that sends through wp_mail() =

WooCommerce, contact-form and membership plugins, and WordPress itself: anything that sends through `wp_mail()` goes through BooleanSMTP, with nothing to change on its side.

= Source code =

The admin screen ships compiled in `public/assets/`. Its human-readable source, the build configuration and the build instructions are public at [https://github.com/booleanpress/boolean-smtp](https://github.com/booleanpress/boolean-smtp).

To build it, install Node.js 22.12 or newer and pnpm 10 or newer, then run `pnpm install` and `pnpm build` in the repository's `resources/` folder. The build writes `public/manifest.json` and `public/assets/`.

== External services ==

BooleanSMTP contacts a service only after you set it up in the plugin, and only to do what you set it up for. Nothing is contacted when the plugin is activated, and no usage data is collected. Sending an email, including a test, a resend or a retry, transmits its sender, recipients, subject, body, headers and attachments to the connection's provider. Connection tests and the scheduled health check (every 15 minutes by default) contact the same service without sending a message, except where noted below. Passwords, API keys and tokens go only to the service they belong to.

= Google (Gmail, Google Workspace) =

Used when you add a Google connection, to send your site's email from your Google account.

* Gmail API, signed in with OAuth: when you sign in to Google from the connection form, your browser opens Google's consent page at `accounts.google.com` with your OAuth client ID, the redirect address, the `gmail.send` permission and a signed state value. Your site then sends the client ID, the client secret and the one-time code to `oauth2.googleapis.com` to get access tokens, and sends the refresh token there whenever access needs renewing (on a schedule, or before a send when the token has expired). Each email goes to `gmail.googleapis.com` with the access token; checks read the account's address there.
* SMTP, signed in with an app password: each email goes to `smtp.gmail.com`, or to Google Workspace's relay at `smtp-relay.gmail.com`, with your Google address and app password.

Google [Terms of Service](https://policies.google.com/terms), [Google APIs Terms of Service](https://developers.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).

= Microsoft (Microsoft 365, Outlook) =

Used when you add a Microsoft connection, to send your site's email over the Microsoft Graph API through your own Microsoft Entra app.

* Sign-in: checking the app's details in the connection form sends your tenant ID, client ID and client secret to `login.microsoftonline.com`. When you sign in to Microsoft, your browser opens Microsoft's sign-in page there with your client ID, the redirect address, the `Mail.Send`, `Mail.Send.Shared`, `User.Read` and `offline_access` permissions, a signed state value and your From address as a sign-in hint. Your site then sends the client ID, the client secret and the one-time code to `login.microsoftonline.com` to get access tokens, and sends the refresh token there whenever access needs renewing.
* Sending: each email goes to `graph.microsoft.com` with the access token. After sign-in, the site reads the signed-in mailbox's address there. Testing the connection also sends a short "[BooleanSMTP] Microsoft capability check" email to the connection's From address.

Microsoft [Services Agreement](https://www.microsoft.com/en-us/servicesagreement), [Microsoft APIs Terms of Use](https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use), [Privacy Statement](https://www.microsoft.com/en-us/privacy/privacystatement).

= Amazon Web Services (Amazon SES) =

Used when you add an Amazon SES connection, to send your site's email through your AWS account.

* API: each email goes to `email.<region>.amazonaws.com`, or to the custom endpoint you set. Requests carry your access key ID and a signature made with your secret key (plus a session token when temporary credentials are used); the secret key itself is never sent. Checking the keys reads your sending quota and whether your From address or its domain is verified in SES; connection checks read the sending quota.
* SMTP: each email goes to `email-smtp.<region>.amazonaws.com` with your SES SMTP user name and password.
* EC2 instance role (off unless you define `BOOLEANSMTP_AWS_ENABLE_IMDS_ROLE_SOURCE` as `true`): the plugin requests temporary credentials from the instance metadata address `169.254.169.254`, which stays inside the server.

AWS [Service Terms](https://aws.amazon.com/service-terms/), [Privacy Notice](https://aws.amazon.com/privacy/).

= SendGrid and Postmark SMTP relays =

When you import a SendGrid or Postmark connection from another SMTP plugin, it becomes a Custom SMTP connection to `smtp.sendgrid.net` or `smtp.postmarkapp.com`, with the imported API key or server token as the password. The import creates an inactive draft and sends nothing; once you turn the connection on, its tests and emails go to that server.

SendGrid (Twilio) [Terms of Service](https://www.twilio.com/en-us/legal/tos), [Privacy Notice](https://www.twilio.com/en-us/legal/privacy). Postmark (ActiveCampaign) [Terms of Service](https://postmarkapp.com/terms-of-service), [Privacy Policy](https://www.activecampaign.com/legal/privacy-policy).

= Custom SMTP =

Each email goes to the SMTP server you enter, with the user name and password you enter; connection checks connect to it without sending a message. That server's operator receives the message, and its own terms and privacy policy apply.

= BooleanSMTP OAuth relay =

Used when you sign in to Google or Microsoft, unless you turn it off. The provider sends its one-time sign-in code (or its error, if sign-in was refused) to `oauth.booleansmtp.com`, operated by BooleanPress, which immediately sends your browser back to your site's admin with it. Along with the code, the relay receives the state value your site created: your site's address, the connection's ID, the provider, a timestamp and the admin screen to return to, signed so it cannot be altered (it is not encrypted). The relay does not store them, and never receives your client secret, any token or any email; your site exchanges the code with Google or Microsoft directly. The relay runs on Cloudflare, which, like any web host, sees your browser's IP address and request headers. To skip the relay, define `BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS` as `true` in `wp-config.php` and register your site's own callback URL, shown on the connection form, in your Google or Microsoft app.

BooleanPress [Terms of Service](https://booleansmtp.com/terms/), [Privacy Policy](https://booleansmtp.com/privacy/).

= Slack, Discord and Telegram (alerts) =

Used when you connect an alert. Saving an alert sends nothing. A notice is sent when you send a test, and whenever an enabled alert fires: an email finally failed, a connection failed its health check, or a sign-in could not be refreshed. Each notice names the alert, its severity and time, with a link to the matching screen in your site's admin. For a failed email it adds the recipient, the subject, the mail provider, the plugin or theme that sent it, and the email log and connection IDs. For a connection or sign-in problem it adds the connection's name and provider, and for a sign-in the kind of failure (for example, token expired). Raw error messages, credentials and message bodies are never included.

* Slack: posted to your incoming-webhook URL on `hooks.slack.com`; its header also carries your site's name. Slack [Terms of Service](https://slack.com/main-services-agreement), [Privacy Policy](https://slack.com/trust/privacy/privacy-policy).
* Discord: posted to your webhook URL on `discord.com` or `discordapp.com` (including their `canary.` and `ptb.` subdomains), under the bot name you set. Discord [Terms of Service](https://discord.com/terms), [Privacy Policy](https://discord.com/privacy).
* Telegram: sent through the Bot API at `api.telegram.org` with your bot token, your chat ID and, if you set one, a topic ID. The **Detect** button also asks the Bot API for your bot's recent updates (`getUpdates`) to list your chat IDs. Telegram [Terms of Service](https://telegram.org/tos), [Bot Developer Terms](https://telegram.org/tos/bot-developers), [Privacy Policy](https://telegram.org/privacy).
* Proxied webhooks: if you mark a Slack or Discord webhook as proxied through a third-party relay, the notice goes to the HTTPS address you entered instead, and that relay's operator receives it under its own terms.

== Privacy ==

* Email logs, including message bodies, are stored only in your own database and are pruned by the retention setting. Deleting the plugin keeps them, along with your connections and settings, unless you turn on **Settings → Delete data on uninstall** first.
* Passwords, API keys and OAuth tokens are encrypted before they are stored and are never shown in full in the admin screens.
* The plugin's own log files are off by default; its warnings and errors go to PHP's error log, as any plugin's do. The SMTP transcript of a send is kept only when a developer enables it with the `boolean_smtp_should_store_smtp_transcript` filter: for 7 days, under `wp-content/uploads/boolean-smtp/logs/debug-sessions/`, with the login exchange hidden and a file name that cannot be guessed.

== Installation ==

1. Install BooleanSMTP from **Plugins → Add New**, or upload it to `/wp-content/plugins/`, then activate it.
2. Open **BooleanSMTP** in the admin menu, choose **Mailers** and add your first connection: Amazon SES, Google, Microsoft, any SMTP server or PHP mail. The setup wizard that opens after activation walks you through the same steps.
3. Send a test email from **Test Email**. The result and full diagnostics appear on screen and in the log.
4. Optional: choose a fallback connection under **Settings**, and connect Slack, Discord or Telegram under **Alerts**.

== Frequently Asked Questions ==

= Does the plugin phone home or need a licence? =

No. There is no licence check and no telemetry. Besides the mail providers and alert services you configure, the only other service is the BooleanPress OAuth relay, which passes the one-time sign-in code back to your site when you connect Google or Microsoft, unless you turn it off (see *External services*).

= Can I send through Amazon SES? =

Yes, over the SES API in any region, or over SMTP in every region where AWS offers it. Enter the keys in the plugin (they are encrypted), define them in `wp-config.php` (`BOOLEANSMTP_AWS_ACCESS_KEY_ID`, `BOOLEANSMTP_AWS_SECRET_ACCESS_KEY`, `BOOLEANSMTP_AWS_REGION`), set them as environment variables, or on EC2 let the plugin use the instance's IAM role by defining `BOOLEANSMTP_AWS_ENABLE_IMDS_ROLE_SOURCE` as `true`.

= Can I send through Gmail or Google Workspace? =

Yes, two ways. Over the Gmail API: create an OAuth client in your Google Cloud project, paste its ID and secret into a Google connection, and sign in. Over SMTP: enter your Google address and an app password; Google Workspace's SMTP relay works the same way.

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
