# BooleanSMTP

Reliable email delivery for WordPress. This repository is the public, human-readable source of the free
plugin published at https://wordpress.org/plugins/boolean-smtp/ — version 1.0.0.

It holds exactly the PHP that ships in the plugin zip, plus the source of the admin screen (a React app built
with Vite) and the files needed to build it. It is published from the development repository at each release;
issues and pull requests are read, but changes land there first.

## What is here

| Path | What it is |
| --- | --- |
| `boolean-smtp.php`, `uninstall.php`, `readme.txt` | Plugin entry file, uninstall routine, WordPress.org readme |
| `app/` | The plugin's PHP (PSR-4 `BooleanSmtp\`): controllers, services, repositories, mail transports |
| `config/`, `routes/`, `database/`, `includes/` | Configuration, the REST routes (`booleansmtp/v1`), migrations, WordPress-facing helpers |
| `vendor/booleanpress/core/` | BooleanPress Core, the plugin's own small framework, prefixed to `BooleanSmtp\Core`. The plugin ships no third-party PHP; PHPMailer comes from WordPress core |
| `vendor/composer/` | Composer's generated autoloader for the above |
| `resources/src/` | The admin screen's source (React 19, Tailwind CSS 4, shadcn/ui components) |
| `resources/package.json`, `resources/vite.config.js`, `resources/vite/` | The admin screen's build: its packages, the `build` / `dev` scripts and the Vite configuration |
| `resources/languages/` | `boolean-smtp.pot` and the catalog that exposes the admin screen's strings to translators |

## Build the admin screen

The plugin zip on WordPress.org contains the built files in `public/`. To build them from this source you need
Node.js 22 or newer and [pnpm](https://pnpm.io/) 10 or newer:

```bash
cd resources
pnpm install
pnpm build        # writes ../public/manifest.json and ../public/assets/
```

`pnpm dev` starts the Vite dev server with hot reload; while it runs, the plugin loads the admin screen from it
instead of `public/`.

The shipped stylesheet can be slightly larger than a build from this repository; the admin screen is the same.

The PHP needs no build step: `vendor/` is the folder exactly as it ships, with the bundled framework already in
place and Composer's autoloader already generated. `composer.json` only describes that autoloader (the plugin's
namespaces and PHP 8.1); it requires no package, so there is nothing to `composer install`.

Every action and filter the plugin fires, with arguments and examples, is documented at
https://developers.booleansmtp.com/hooks/.

## Use it on a site

Copy or clone this folder into `wp-content/plugins/boolean-smtp`, run the build above, and activate the plugin.
PHP 8.1 or newer and WordPress 6.0 or newer are required.

## Licence

GPL-2.0-or-later. See `LICENSE`.
