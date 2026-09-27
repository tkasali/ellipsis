# Ellipsis — deploy to Hostinger

Hostinger runs **LiteSpeed** with **hPanel**. Both `.htaccess` files are written for it: edge caching for static pages, no caching at all for the API, Brotli compression, and the auth header passed through to PHP.
Works on **Premium, Business and Cloud** plans. The Single plan works too, but has no cron and less storage.

```
public_html/
  .htaccess  index.html  404.html  robots.txt  sitemap.xml  support.js  icons/
  app/     ← the web app (installable PWA)
  mobile/  ← the phone build
  api/     ← the backend (PHP 8)
  admin/   ← operations console
  deck/    ← investor deck
  legal/   ← privacy, terms, copyright
…/YOURDOMAIN/ellipsis-data/   ← created by setup, outside the web root (database + stems)
```

## 1. Upload
1. hPanel → **Websites → Manage → Files → File Manager** → open `public_html`.
2. Delete Hostinger's placeholder `default.php` / `index.php` if they're there.
3. **Upload** the `ellipsis-web.zip` you downloaded, right-click it → **Extract** into `public_html`, then delete the zip. Extracting on the server is much faster than uploading hundreds of files one at a time.
4. Make sure the *contents* land in `public_html` (you should see `public_html/app/`), not `public_html/web/app/`. If they landed inside a `web` folder, select everything in it → **Move** → `public_html`.
5. Hidden files: File Manager shows `.htaccess` by default. Confirm there's one in `public_html` and one in `public_html/api`.

Prefer the terminal? hPanel → **Advanced → SSH Access** (Premium and up):
```
cd ~/domains/YOURDOMAIN/public_html && unzip -o ~/ellipsis-web.zip && rm ~/ellipsis-web.zip
```

## 2. Domain and SSL
- Domain bought at Hostinger: already connected.
- Domain bought elsewhere: point its nameservers to `ns1.dns-parking.com` and `ns2.dns-parking.com`, or add an **A record** to the IP shown in hPanel → *Hosting → Details*.
- hPanel → **Security → SSL** → install the free certificate for the domain (usually automatic within minutes).
- **HTTPS is not optional**: the microphone, the service worker and installing the app all refuse to run without it. If you must go live before the certificate arrives, temporarily rename `htaccess-no-ssl.txt` to `.htaccess`, and switch back once SSL is active.

## 3. PHP
hPanel → **Advanced → PHP Configuration**:
- **PHP version: 8.2** (8.1+ works).
- **PHP extensions**: `pdo_sqlite`, `pdo_mysql`, `fileinfo`, `curl`, `mbstring` are on by default; tick any that aren't.
- **PHP options**: `upload_max_filesize` 64M, `post_max_size` 70M, `max_execution_time` 120, `memory_limit` 256M. The API's `.htaccess` requests the same values; hPanel is where they're guaranteed.

## 4. Configure and install the backend
1. File Manager → `public_html/api/config.php` → **Edit**:
   - `secret`: a long random string (64+ characters).
   - `setup_key`: a second random string, used once.
   - `admin_emails`: `['you@yourdomain.com']`.
2. **Database**, choose one:
   - **SQLite (default, zero setup)** — nothing to do. Fine for the first few thousand users.
   - **MySQL** — hPanel → **Databases → Management** → create a database. Hostinger names it like `u123456789_ellipsis` with a user of the same name. Set
     `'db_dsn' => 'mysql:host=localhost;dbname=u123456789_ellipsis;charset=utf8mb4'`, plus `db_user` and `db_pass`.
3. Open **`https://YOURDOMAIN/api/setup?key=YOUR_SETUP_KEY`**. It creates the tables and seeds the network (200 creators, 46 tracks, rooms, chat, 30 days of plays). Expect `"ok": true`. Seeding takes a few seconds.
4. Check **`https://YOURDOMAIN/api/health`** → `"installed": true`.
5. Open `/app/`. The status dot in the left rail turns **mint** (live). Create your account; your email is in `admin_emails`, so it becomes an admin.

## 5. Cron (keeps it fast)
hPanel → **Advanced → Cron Jobs** → *Custom*:
- Command: `/usr/bin/php /home/uXXXXXXXXX/domains/YOURDOMAIN/public_html/api/cron.php`
  (your exact path is shown at the top of File Manager)
- Schedule: every 10 minutes (`*/10 * * * *`).

It clears expired sessions, rate-limit counters, stale presence and old challenges, and optimises SQLite nightly.

## 6. Lock the console
hPanel → **Advanced → Password Protect Directories** → choose `public_html/admin` → set a username and password.
`/admin/` is also `noindex` and disallowed in robots.txt, but only the hPanel lock actually protects it.

## 7. Caching and CDN
- **LiteSpeed Cache** is on for static pages. The API sends `no-store` and `X-LiteSpeed-Cache-Control: no-cache`, so it's never cached.
- **Hostinger CDN** (Business and up, hPanel → Performance → CDN): safe to enable. If you do, add `/api/*` to *Bypass cache* as a belt-and-braces rule.
- After updating files: bump `CACHE = 'ellipsis-vNN'` in `app/sw.js` and `mobile/sw.js`, then hPanel → Performance → **Flush cache**.

## 8. Fill in the placeholders
- `robots.txt`, `sitemap.xml`: replace `REPLACE-WITH-YOUR-DOMAIN`.
- `legal/*.html`: replace every `[bracketed]` value with your real entity, address and DMCA agent.

## 9. What runs on the server
Accounts (bcrypt passwords, bearer sessions), server-graded human verification, feed, charts, spikes, joining tracks, room chat and presence (polled every 2.5s), stem uploads (audio checked by file content, SHA-256 fingerprint, duplicates held for review), split sheets (must total 100%; edits reset signatures), offers, branches, versions, a publish gate enforced on the server, per-play ad and subscription revenue, per-track ledger, payouts, reports, admin moderation and an audit log.

**Optional keys** in `config.php`:
- `deepl_key` → chat translation.
- `stripe_key` (+ creator `payout_account`) → real Stripe Connect payouts. Without it, payouts are recorded as queued.
- `turnstile_secret` → Cloudflare Turnstile on top of the rhythm check.

**Scaling on Hostinger**
- **Shared plans** (Premium, Business): rooms sync by 2.5s polling, which is fine for chat and presence. Move to MySQL past a few thousand active users.
- **Cloud plans**: more PHP workers and memory. Same files, no changes needed.
- **Hostinger VPS**: needed for WebSockets, live voice (WebRTC + TURN) and sample-accurate co-editing. The API is structured so a socket service can run alongside it without changes to the app.

## 10. Verify
- `/` landing · `/app/` web app · `/mobile/` phone build (Share → Add to Home Screen) · `/legal/privacy.html` (the URL App Store Connect and Play Console ask for).
- Record a take: the browser asks for the microphone once, over https.
- Stems and the database live in `…/YOURDOMAIN/ellipsis-data/`. Back that folder up, or rely on hPanel → **Files → Backups** (daily on Business and up).
