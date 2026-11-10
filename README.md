# Client Page Review

Let clients comment directly on your WordPress pages, like Figma or Google Docs comments, with a point-and-click toolbar. Feedback is stored on a small review server **you** host, not in the client's browser, so everyone sees the same board across devices.

Built on the open source [annotate.js](https://github.com/reviewjs/annotate) reference backend (MIT), wrapped with a small admin API and a WordPress plugin.

![status](https://img.shields.io/badge/status-production-green)

## What you get

- **Point-and-click review toolbar** on any page: notes, text highlights, rectangles, circles, freehand, and pins.
- **Shared board** so the whole team and the client see every comment, reply to each other, and resolve items together.
- **No accounts.** A reviewer just opens a link ending in `?review` and types their name. Optional per-person codes (`?review=theirname`) give a stable identity across devices.
- **Custom colour picker** added into annotate's own colour menu (a rainbow `+` beside the presets).
- **Figma-style draft outline:** a drawn rectangle/circle stays visible while you write the comment.
- Works on normal pages **and** on pages served as a full raw HTML document (see the companion [raw HTML pages](#raw-html-pages) note).

## Architecture

```
Browser (client)                WordPress site                 Review server (you host)
┌───────────────┐   ?review     ┌──────────────────┐  admin API ┌──────────────────────┐
│ annotate.js   │ ────────────► │ rzr-client-review │ ─────────► │ app.cjs (Node)       │
│ toolbar + UI  │ ◄──comments── │ plugin (settings, │  Bearer    │  annotate backend +  │
└───────────────┘   /v1/annotate│ toolbar, feedback)│   token    │  admin API, /data    │
                                └──────────────────┘            └──────────────────────┘
```

| Folder | What it is |
| --- | --- |
| `server/` | The annotate.js reference backend (pinned to `1.6.0`), patched at build time (`patch.cjs`) and wrapped with an admin API (`app.cjs`). Ships as a Docker image. |
| `wp-plugin/rzr-client-review/` | A single-file WordPress plugin: a settings page, the front-end toolbar loader, and an admin feedback table. |

## 1. Deploy the review server

The server is a tiny Node app in a Docker image. It needs one secret (`ADMIN_TOKEN`) and a persistent volume for `/data`.

**Environment**

| Var | Required | Default | Notes |
| --- | --- | --- | --- |
| `ADMIN_TOKEN` | yes | — | 24+ char random string. Admin API bearer token. |
| `PORT` | no | `80` | Port the server listens on. |
| `DATA_DIR` | no | `/data` | Reviews + comments JSON. **Put this on a persistent volume.** |
| `PURGE_AFTER_DAYS` | no | `30` | Soft-deleted comments are purged after this many days. |
| `WRITES_PER_MINUTE` | no | `60` | Simple write rate limit per review. |

Generate a token:

```bash
node -e "console.log(require('crypto').randomBytes(24).toString('base64url'))"
```

### Option A — CapRover (what this repo is set up for)

`captain-definition` points at the `Dockerfile`. Create an app, add a persistent directory at `/data`, set `ADMIN_TOKEN` (and optionally the others), enable HTTPS, then deploy:

```bash
npx caprover deploy -a <your-app> -t ./server-build.tar.gz   # or use the CapRover UI / git
```

### Option B — plain Docker

```bash
cd server
docker build -t client-review .
docker run -d --name review \
  -e ADMIN_TOKEN="<your-long-token>" \
  -v review-data:/data \
  -p 8787:80 \
  client-review
```

Put it behind a reverse proxy with TLS (Caddy, nginx, Traefik). Then `GET https://review.example.com/health` should return `{"ok":true,...}`.

### Create a review

Reviews are created/updated through the admin API, but **the WordPress plugin does this for you** when you save its settings. If you want to script it:

```bash
curl -X PUT https://review.example.com/admin/reviews/rvw_mysite-com \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"My site","origins":["https://mysite.com"],"open":true,"shared":true,"selfServe":true}'
```

## 2. Install the WordPress plugin

Copy `wp-plugin/rzr-client-review/` into `wp-content/plugins/` (or zip the folder and upload it), then activate **Client Review**. Go to **Settings → Client Review** and set:

| Setting | Meaning |
| --- | --- |
| **Review server URL** | e.g. `https://review.example.com` |
| **Admin token** | Your `ADMIN_TOKEN`. Prefer the constant/env method below to keep it out of the DB. |
| **Mode** | `Off`, `Per page` (toolbar only when the URL ends with `?review`), or `Full site` (every page — staging only). |
| **Name only access** | Anyone with the link comments after typing their name. No codes to hand out. |
| **Shared board** | Everyone sees everyone's comments (recommended). Off = each person sees only their own. |
| **Self-serve codes** | Allow `?review=CODE` so a person keeps the same identity across devices. |
| **Note to reviewers** | Short instruction shown in the toolbar. |

Press **Check connection** to confirm the server URL + token work. The **Feedback** table on the same page lists everything clients have posted.

### Keep the admin token out of the database (recommended)

The plugin reads the token from, in order: a `RZR_CR_TOKEN` PHP constant, a `RZR_CR_TOKEN` environment variable, then the saved option. To avoid storing the secret in the WP database, drop a must-use plugin at `wp-content/mu-plugins/rzr-cr-token.php`:

```php
<?php
if ( ! defined( 'RZR_CR_TOKEN' ) ) {
    define( 'RZR_CR_TOKEN', getenv( 'RZR_CR_TOKEN' ) ?: 'your-admin-token-here' );
}
```

Leave the Settings token field blank and the plugin will use the constant.

## 3. Share a review

- **Simplest:** send the page URL with `?review` on the end, e.g. `https://mysite.com/landing/?review`. The reviewer types their name and starts commenting.
- **Stable identity:** `https://mysite.com/landing/?review=alex` — the code `alex` becomes a saved identity, same across devices.
- **Full-site mode** on staging: every page shows the toolbar, no `?review` needed.

Pages opened with `?review` are served with `X-Robots-Tag: noindex` so review links are never indexed.

## Server admin API

All admin routes need `Authorization: Bearer <ADMIN_TOKEN>`.

| Method and path | Does |
| --- | --- |
| `GET /admin/reviews` | List reviews |
| `GET /admin/reviews/:id` | One review |
| `PUT /admin/reviews/:id` | Create/update (`name`, `note`, `origins`, `pages`, `open`, `closed`, `shared`, `selfServe`) |
| `POST /admin/reviews/:id/invites` | Create a named/author link (`name`, `author`) |
| `DELETE /admin/reviews/:id/invites/:token` | Revoke a link |
| `GET /admin/reviews/:id/comments` | All comments of a review |

`GET /health` needs no token. Everything under `/v1/annotate/*` is the annotate.js wire protocol (origin-checked against the review's `origins`).

## How the server patch works

`patch.cjs` applies a handful of **asserted** string replacements to the pinned annotate reference server at Docker build time, so there is no fork to maintain:

1. Read reviews from an external map so they can change without a restart.
2. Add a per-review **`shared`** board: when `shared` is true every reviewer can view, reply to, resolve, edit and delete any comment. In a non-shared review the original owner-only checks apply.
3. Add **self-serve codes**: an unknown `?review=CODE` on a `selfServe` review becomes a stable identity derived from `sha256(reviewId + ':' + code)`.

If annotate changes its internals in a future version, the assertions in `patch.cjs` fail the build loudly instead of shipping something broken. Bump the pinned version in the `Dockerfile` and adjust the patch targets.

## Raw HTML pages

If some of your pages are a full `<!doctype html>` document pasted into a single HTML block (common with page builders), the plugin also injects the toolbar through a `rzr_raw_html_output` filter, so a companion "raw HTML pages" plugin that serves those documents verbatim can expose the review toolbar too. Normal pages use the standard `wp_footer` hook.

## Security notes

- The admin token is the only secret. Keep it out of the WP database with the constant/env method above, and never commit it.
- `origins` on each review is an allow-list: the public annotate endpoints reject requests from any other origin.
- Review links carry `noindex`.
- This is built for client feedback on your own staging/production pages. It is not a general-purpose public commenting system.

## Credits & licence

- [annotate.js](https://github.com/reviewjs/annotate) by the reviewjs project (MIT) — the review backend and front-end toolbar.
- This wrapper (server admin API, patch, WordPress plugin) is released under the MIT licence. See `LICENSE`.
