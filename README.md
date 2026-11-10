# Client Page Review

Let clients comment directly on your web pages, the way they do in Figma or Google Docs: a point-and-click toolbar for notes, highlights, boxes, and pins. Feedback is stored on a small server **you** host, so everyone sees the same board across devices, and nothing lives only in one person's browser.

It has two pieces that work together or separately:

- **A review server** (Node, shipped as a Docker image) — the open-source [annotate.js](https://github.com/reviewjs/annotate) reference backend (MIT), patched at build time and wrapped with a small admin API. Runs anywhere Docker or Node runs.
- **A WordPress plugin** — the easiest way to put the toolbar on a site and read the feedback. **Optional:** any website can load the toolbar with a few lines of HTML (see [Use it without WordPress](#use-it-without-wordpress)).

![status](https://img.shields.io/badge/status-production-brightgreen) ![license](https://img.shields.io/badge/license-MIT-blue) ![backend](https://img.shields.io/badge/backend-Node%2022-339933) ![deploy](https://img.shields.io/badge/deploy-Docker-2496ED)

---

## Table of contents

- [Features](#features)
- [How it fits together](#how-it-fits-together)
- [Quick start](#quick-start)
- [Deploy the review server](#deploy-the-review-server)
  - [Docker Compose](#option-a--docker-compose-recommended) · [Plain Docker](#option-b--plain-docker) · [Bare Node](#option-c--bare-node) · [CapRover / PaaS](#option-d--caprover--other-paas)
- [Put a TLS proxy in front](#put-a-tls-proxy-in-front)
- [Use it on WordPress](#use-it-on-wordpress)
- [Use it without WordPress](#use-it-without-wordpress)
- [Sharing a review](#sharing-a-review)
- [Admin API](#admin-api)
- [Data, backup & restore](#data-backup--restore)
- [How the server patch works](#how-the-server-patch-works)
- [Updating the annotate version](#updating-the-annotate-version)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Credits & licence](#credits--licence)

---

## Features

- **Full review toolbar** — notes, text highlights, rectangles, circles, freehand, and pins, anchored to the page.
- **Shared board** — the whole team and the client see every comment, reply to each other, and resolve items together. (Can be switched to private-per-person.)
- **No accounts, no sign-up** — a reviewer opens a link ending in `?review` and types their name. Optional per-person codes (`?review=theirname`) give a stable identity across devices.
- **Custom colour picker** — a rainbow `+` added beside the preset swatches, so reviewers can pick any colour.
- **Figma-style draft outline** — a drawn box/circle stays on screen while the comment is being written.
- **Self-hosted & private** — data is plain JSON on a volume you control; nothing goes to a third party.
- **Works anywhere** — WordPress plugin included, but the toolbar is just a script tag, so any site can use it.

## How it fits together

```
Reviewer's browser                Your website                    Review server (you host)
┌────────────────────┐  ?review   ┌────────────────────┐  admin   ┌────────────────────────┐
│ annotate.js toolbar │ ─────────► │ WP plugin   OR     │  API +   │ app.cjs (Node)         │
│ draws comments,     │ ◄────────  │ a <script> snippet │  Bearer  │  annotate backend +    │
│ sees the board      │ /v1/annotate                    │  token   │  admin API, /data vol  │
└────────────────────┘            └────────────────────┘          └────────────────────────┘
```

- The browser talks to the review server directly over the public `/v1/annotate/*` protocol (origin-checked).
- Your site (via the plugin or a snippet) only needs to load annotate.js and tell it the server URL + review id.
- You talk to the server's **admin API** (bearer token) to create reviews and read all feedback. The WordPress plugin does this for you.

## Quick start

```bash
git clone https://github.com/crxssrazr93/client-page-review.git
cd client-page-review
cp .env.example .env
# edit .env: set ADMIN_TOKEN to a long random string
#   node -e "console.log(require('crypto').randomBytes(24).toString('base64url'))"
docker compose up -d --build
curl -s http://127.0.0.1:8787/health      # -> {"ok":true,"reviews":0}
```

That's the server. Now [put TLS in front of it](#put-a-tls-proxy-in-front) and point your site at it with the [WordPress plugin](#use-it-on-wordpress) or a [plain snippet](#use-it-without-wordpress).

---

## Deploy the review server

One secret and one volume. That's the whole contract.

| Variable | Required | Default | Notes |
| --- | --- | --- | --- |
| `ADMIN_TOKEN` | **yes** | — | 24+ char random string. Bearer token for the admin API. |
| `PORT` | no | `80` | Port the app listens on inside the container. |
| `DATA_DIR` | no | `/data` | Where reviews + comments JSON are written. **Persist this.** |
| `PURGE_AFTER_DAYS` | no | `30` | Soft-deleted comments are purged after this long. |
| `WRITES_PER_MINUTE` | no | `60` | Per-review write rate limit. |

### Option A — Docker Compose (recommended)

```bash
cp .env.example .env    # set ADMIN_TOKEN
docker compose up -d --build
```

`docker-compose.yml` binds the app to `127.0.0.1:8787` and stores data in a named volume. Add TLS with a reverse proxy (below).

### Option B — plain Docker

```bash
cd server
docker build -t client-review .
docker run -d --name review --restart unless-stopped \
  -e ADMIN_TOKEN="$(node -e 'console.log(require("crypto").randomBytes(24).toString("base64url"))')" \
  -v review-data:/data \
  -p 127.0.0.1:8787:80 \
  client-review
```

### Option C — bare Node

No Docker required (Node 18+). The app has **zero runtime dependencies of its own**; it only needs the pinned annotate package, which the Dockerfile installs. To run without Docker, replicate those two build steps:

```bash
cd server
npm init -y >/dev/null
npm install --omit=dev @reviewjs/annotate@1.6.0
cp node_modules/@reviewjs/annotate/examples/annotate-server.cjs ./annotate-server.cjs
node patch.cjs annotate-server.cjs           # applies the shared-board + self-serve patches
ADMIN_TOKEN=yourlongtoken DATA_DIR=./data PORT=8787 node app.cjs
```

### Option D — CapRover / other PaaS

`captain-definition` in the repo points at the `Dockerfile`, so CapRover (and most Dockerfile-based PaaS) can deploy it directly. Create the app, add a persistent directory at `/data`, set `ADMIN_TOKEN`, enable HTTPS, and deploy. The same `Dockerfile` works on Fly.io, Render, Railway, Google Cloud Run, etc.

## Put a TLS proxy in front

The server speaks plain HTTP and expects to sit behind a proxy that terminates TLS. Pick one:

- **Caddy** (automatic HTTPS, simplest) — see [`examples/Caddyfile`](examples/Caddyfile).
- **nginx** (with certbot) — see [`examples/nginx.conf`](examples/nginx.conf).
- **Traefik**, a cloud load balancer, or your PaaS's built-in HTTPS all work too.

After that, `https://review.example.com/health` should return `{"ok":true,...}`.

---

## Use it on WordPress

1. Copy `wp-plugin/annotate-client-review/` into `wp-content/plugins/` (or zip the folder and upload it via **Plugins → Add New → Upload**), then activate **Annotate Client Review**.
2. Open **Settings → Client Review** and fill in:

| Setting | Meaning |
| --- | --- |
| **Review server URL** | e.g. `https://review.example.com` |
| **Admin token** | Your `ADMIN_TOKEN`. Prefer the [constant/env method](#keep-the-token-out-of-the-database) to keep it out of the DB. |
| **Mode** | `Off`, `Per page` (toolbar only when the URL ends with `?review`), or `Full site` (every page — staging only). |
| **Name only access** | Anyone with the link comments after typing a name. No codes to hand out. |
| **Shared board** | Everyone sees everyone's comments (recommended). Off = each person sees only their own. |
| **Self-serve codes** | Allow `?review=CODE` so a person keeps the same identity across devices. |
| **Note to reviewers** | Short instruction shown in the toolbar. |

3. Press **Check connection** to confirm the URL + token work. Saving the settings also creates/updates the review on the server automatically. The **Feedback** table on the same page lists everything clients post.

### Keep the token out of the database

The plugin reads the token from, in order: a `ACR_TOKEN` PHP constant → a `ACR_TOKEN` env var → the saved option. To avoid storing the secret in the WP database, add `wp-content/mu-plugins/acr-token.php`:

```php
<?php
if ( ! defined( 'ACR_TOKEN' ) ) {
    define( 'ACR_TOKEN', getenv( 'ACR_TOKEN' ) ?: 'your-admin-token-here' );
}
```

Leave the Settings token field blank and the plugin uses the constant.

### Raw-HTML / page-builder pages

If some pages are a full `<!doctype html>` document (common with builders that output one big HTML block), the plugin also injects the toolbar through a `acr_raw_html_output` filter, so a companion plugin that serves such documents verbatim can still show the toolbar. Normal pages use the standard `wp_footer` hook — nothing extra needed.

## Use it without WordPress

The WordPress plugin is only a convenience wrapper. Under the hood there are **three parts**, and the plugin just automates parts 1 and 3. Any site can do them directly.

> **Where the admin token is used:** only server-side, in steps 1 and 3 (create the review, read feedback). The **browser never sees the token** — the toolbar's requests go to the public `/v1/annotate/*` endpoints and are authorised by the page's origin matching the review's `origins`.

### 1. Create the review — server-side, uses the admin token

Do this once, from a terminal, a deploy script, or your own backend:

```bash
curl -X PUT https://review.example.com/admin/reviews/rvw_mysite-com \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"My site","origins":["https://mysite.com"],"open":true,"shared":true,"selfServe":true}'
```

`origins` **must** list the exact origin(s) the toolbar loads on (e.g. `https://mysite.com`), or the server rejects the browser's requests with `origin_not_allowed`.

### 2. Load the toolbar on your pages — browser, no token

Add this before `</body>` on any page you want reviewable:

```html
<script
  src="https://cdn.jsdelivr.net/npm/@reviewjs/annotate@1.6.0/annotate.js"
  data-api="https://review.example.com"
  data-review-id="rvw_mysite-com"
  data-project="rvw_mysite-com"
  data-page="/the/path/"
  data-start-open="true"
  defer></script>

<!-- Optional: let pure viewers (who never type a name) see the shared board,
     by seeding a stable identity so annotate always opens a session. -->
<script>
(function(){
  var RID='rvw_mysite-com', K='annotate:invite:'+RID;
  try{ if(!localStorage.getItem(K) && !localStorage.getItem('an-author'))
    localStorage.setItem(K,'g-'+Math.random().toString(36).slice(2,12)); }catch(e){}
})();
</script>
```

There is no token here — this is exactly what every reviewer's browser runs.

### 3. Read the feedback — server-side, uses the admin token

Pull every comment as JSON and do whatever you want with it (a dashboard, Slack, email, a static report):

```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  https://review.example.com/admin/reviews/rvw_mysite-com/comments
```

The WordPress plugin's **Feedback** table is literally this call. See the full [admin API](#admin-api) for updating the review, listing reviews, and managing links.

> The custom-colour `+` and the Figma-style draft outline are small front-end extras implemented in the plugin's inline bootstrap. If you want them on a non-WordPress site, lift that JS from `wp-plugin/annotate-client-review/annotate-client-review.php` (the `acr_bootstrap_js` function). Plain annotate.js works fine without them.

## Sharing a review

- **Simplest:** send the page URL with `?review` on the end — `https://mysite.com/landing/?review`. The reviewer types their name and comments.
- **Stable identity:** `https://mysite.com/landing/?review=alex` — the code `alex` becomes a saved identity, the same on any device.
- **Full-site mode** (WordPress, staging only): every page shows the toolbar with no `?review` needed.

Pages opened with `?review` are served `X-Robots-Tag: noindex` so review links are never indexed.

## Admin API

Base URL is your server. All admin routes require `Authorization: Bearer <ADMIN_TOKEN>`.

| Method & path | Does |
| --- | --- |
| `GET /admin/reviews` | List all reviews |
| `GET /admin/reviews/:id` | One review (with its invites) |
| `PUT /admin/reviews/:id` | Create/update: `name`, `note`, `origins[]`, `pages[]`, `open`, `closed`, `shared`, `selfServe` |
| `POST /admin/reviews/:id/invites` | Create a named/author link: `{ "name": "...", "author": true }` |
| `DELETE /admin/reviews/:id/invites/:token` | Revoke a link |
| `GET /admin/reviews/:id/comments` | Every comment in a review |
| `GET /health` | Liveness, **no token** |
| `* /v1/annotate/*` | The annotate.js wire protocol (origin-checked, used by the browser) |

Create a review from the command line:

```bash
curl -X PUT https://review.example.com/admin/reviews/rvw_mysite-com \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{
        "name": "My site",
        "origins": ["https://mysite.com"],
        "open": true, "shared": true, "selfServe": true
      }'
```

Review ids are your choice; a safe convention is `rvw_` + the host with non-alphanumerics replaced by `-` (e.g. `rvw_mysite-com`). The WordPress plugin uses exactly that.

## Data, backup & restore

Everything lives under `DATA_DIR` (`/data`) as plain JSON:

```
/data/reviews.json                 # all reviews + their invites
/data/comments/<review-id>.json    # comments per review
```

- **Back up:** copy the `/data` volume (or `docker run --rm -v review-data:/data -v "$PWD":/out alpine tar czf /out/review-backup.tgz -C /data .`).
- **Restore:** stop the container, put the JSON back, start it.
- **Export one review's comments** any time via `GET /admin/reviews/:id/comments`. Reviewers can also export/import their own copy from the toolbar.

Because it's just files, there is no database to run and migrations are trivial.

## How the server patch works

`patch.cjs` applies a few **asserted** string replacements to the pinned annotate reference server at Docker build time, so there is no fork to maintain:

1. **External reviews map** — reviews are read from a shared map, so they can change without restarting the server.
2. **Shared board** — when a review's `shared` is true, every reviewer can view, reply to, resolve, edit, and delete any comment or reply. In a non-shared review the original owner-only rules apply.
3. **Self-serve codes** — on a `selfServe` review, an unknown `?review=CODE` becomes a stable identity derived from `sha256(reviewId + ':' + code)`, so the same code is the same person on any device.

Each replacement asserts its target exists and is unique, and a final check counts the guards it rewrote. If a future annotate version changes those internals, **the build fails loudly** instead of shipping something subtly broken.

## Updating the annotate version

1. Bump the pinned version in `server/Dockerfile` (the `@reviewjs/annotate@x.y.z` line).
2. Rebuild. If annotate moved the code the patch targets, `patch.cjs` throws during the build and names the target that no longer matches — update that string and rebuild.

Pinning keeps your deploy reproducible; nothing updates from under you.

## Security

- The **admin token** is the only secret. Keep it out of app source and the WP database (use the constant/env method), rotate it by changing `ADMIN_TOKEN` and the plugin's token together.
- Each review's **`origins`** is an allow-list; the public annotate endpoints reject any other origin.
- Review links carry **`noindex`**.
- Bind the container to `127.0.0.1` and expose it only through your TLS proxy.
- This is for client feedback on **your own** pages. It is not a hardened public commenting system for untrusted internet traffic.

## Troubleshooting

| Symptom | Likely cause / fix |
| --- | --- |
| Plugin shows **401 unauthorized** under Feedback | The admin token is wrong or a placeholder. Paste the real `ADMIN_TOKEN`, or set the `ACR_TOKEN` constant. Use **Check connection**. |
| Browser console **`origin_not_allowed` / 403** | The page's origin isn't in the review's `origins`. Add it via `PUT /admin/reviews/:id` (the WP plugin sets it from your site URL automatically). |
| Toolbar doesn't appear | Mode is `Off`, or the URL doesn't end in `?review` (in Per-page mode), or an ad-blocker blocked the CDN script. |
| Incognito/new device sees an empty board | Seed a session identity (the WP plugin does this; for the snippet see [Use it without WordPress](#use-it-without-wordpress)). |
| Comments vanished after redeploy | `/data` wasn't on a persistent volume. Always mount it. |
| `Set ADMIN_TOKEN (24+ characters).` on boot | The token is missing or too short. |

## FAQ

**Does it need a database?** No. Data is JSON files on a volume.

**Can multiple sites share one server?** Yes — one server hosts many reviews (one per site/review id), each with its own `origins`.

**Is client data sent anywhere else?** No. The browser talks only to your server and loads annotate.js from a CDN (you can self-host that file too).

**Can I use it on a static site / React / plain HTML?** Yes — see [Use it without WordPress](#use-it-without-wordpress).

**Can reviewers edit each other's comments?** On a `shared` review, yes (that's the point). Turn `shared` off for private-per-person feedback.

## Credits & licence

- [annotate.js](https://github.com/reviewjs/annotate) by the reviewjs project (MIT) — the review backend and front-end toolbar this builds on.
- The server admin API, the build-time patch, and the WordPress plugin in this repo are released under the **MIT licence** (see [`LICENSE`](LICENSE)).

PRs and issues welcome.
