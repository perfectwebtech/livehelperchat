# Production deployment

This is the runbook for running Live Helper Chat in production with the Docker Compose stack in this repository: MariaDB, the web application, a scheduler for background jobs, and optional automatic HTTPS. Every step below is exercised by CI (`.github/scripts/smoke-compose.sh`).

## 1. Server

| Item | Minimum | Comfortable |
|---|---|---|
| CPU / RAM | 2 vCPU / 2 GB | 4 vCPU / 8 GB |
| Disk | 20 GB SSD | sized for uploads and backups |
| OS | any Linux with Docker Engine 24+ and the Compose plugin | Ubuntu 24.04 LTS / Debian 12 |
| Network | ports 80 and 443 open to the internet | SSH limited to your IPs |

DNS: create an `A` (and `AAAA` if you use IPv6) record for your chat host name, for example `chat.example.com`, pointing at the server.

## 2. First deployment

```bash
sudo mkdir -p /opt/livehelperchat && sudo chown "$USER" /opt/livehelperchat
git clone <this repository> /opt/livehelperchat && cd /opt/livehelperchat
cp .env.example .env && chmod 600 .env
```

Edit `.env`:

| Variable | Set to |
|---|---|
| `LHC_DB_PASSWORD`, `MARIADB_ROOT_PASSWORD` | two different long random passwords (`openssl rand -hex 24`) |
| `LHC_SECRET_HASH` | `openssl rand -hex 40` (never change it later) |
| `LHC_ADMIN_PASSWORD`, `LHC_ADMIN_EMAIL` | first administrator, used on first boot only |
| `LHC_DOMAIN` | your host name, for example `chat.example.com` |
| `LHC_PROXY_MODE` | `1` (required with the bundled HTTPS proxy) |
| `LHC_TRUSTED_HOST_PATTERNS` | a regular expression for your host name, e.g. `^chat\.example\.com$`: links the app generates (password reset, SSO redirect URI) then only use that host |
| `LHC_METRICS_TOKEN` | `openssl rand -hex 24` if you use Prometheus |

Start with automatic HTTPS (Caddy obtains a Let's Encrypt certificate on first request):

```bash
docker compose --profile tls up -d --build
docker compose ps                       # web shows (healthy) after ~30 s
curl -fsS https://chat.example.com/index.php/system/health
```

Log in at `https://chat.example.com/index.php/site_admin/` with the administrator from `.env`. Change that password in the back office, then remove `LHC_ADMIN_PASSWORD` from `.env`: it is only read on the first boot.

Once HTTPS works for every visitor, set `LHC_HSTS_MAX_AGE=31536000` and run `docker compose --profile tls up -d`.

Using your own load balancer or TLS proxy instead of Caddy: leave the `tls` profile off, point the proxy at the web port (`LHC_HTTP_BIND`/`LHC_HTTP_PORT`), forward `X-Forwarded-For` and `X-Forwarded-Proto`, and set `LHC_PROXY_MODE=1` only if the web port is reachable from the proxy alone.

## 3. Go-live checklist

Security
- [ ] `.env` has unique random passwords, `chmod 600`, and a copy is in your password manager or secret store.
- [ ] HTTPS works; `LHC_HSTS_MAX_AGE` set; `LHC_TRUSTED_HOST_PATTERNS` set.
- [ ] Initial admin password changed; `LHC_ADMIN_PASSWORD` removed from `.env`.
- [ ] Password policy and brute-force limits reviewed (System configuration -> Password requirements).
- [ ] Single sign-on configured if you have an identity provider (System configuration -> Single sign-on). Register the redirect URI shown on that page. Consider "hide the password form" once SSO works; the break-glass URL is `/index.php/site_admin/user/login/(local)/1`.
- [ ] REST API keys limited to the endpoints each integration needs, with an expiry date.
- [ ] Roles reviewed: who may export or delete chats, configure SSO (`lhsso/configure`), retention (`lhgdpr/configure`) and data subject requests (`lhgdpr/subject_requests`).

Data protection
- [ ] Retention policy set (System configuration -> Data retention), for example anonymise after 90 days, delete after 365.
- [ ] Privacy notice on your site mentions the chat and the retention period.
- [ ] Someone owns data subject requests (export / erase by e-mail or phone).

Operations
- [ ] Nightly backup in cron and a restore tested (section 5).
- [ ] Health check and metrics monitored (section 6).
- [ ] Widget embedded on the site and a test chat answered end to end.

## 4. Updates and rollback

```bash
cd /opt/livehelperchat
deploy/backup.sh                         # always back up first
git pull                                 # or set LHC_IMAGE to a new release tag
docker compose --profile tls up -d --build
docker compose logs web | tail -n 20     # "applying bundled database schema" / "up to date"
```

The web container applies the bundled database schema on every start (`LHC_AUTO_MIGRATE=1`). To preview the changes of a new version first: `docker compose run --rm --no-deps -e LHC_AUTO_MIGRATE=0 web php cli/update-db.php --dry-run`.

Released images: pushing a tag `vX.Y.Z` runs `.github/workflows/release.yml` and publishes `ghcr.io/<owner>/<repo>:vX.Y.Z`. On the server set `LHC_IMAGE=ghcr.io/<owner>/<repo>:vX.Y.Z` in `.env`, log in once with `docker login ghcr.io` (a token with `read:packages`), then `docker compose pull && docker compose --profile tls up -d`.

Rollback: set `LHC_IMAGE` back to the previous tag (or `git checkout` the previous commit) and start again. If the update changed data you need to undo, restore the backup taken before it (section 5).

## 5. Backups and restore

```bash
deploy/backup.sh                    # -> backups/<UTC timestamp>/ (database, uploads, settings, SHA256SUMS)
deploy/restore.sh backups/<timestamp> --yes
```

Nightly, keeping 14 days (`LHC_BACKUP_KEEP_DAYS`):

```cron
30 2 * * * cd /opt/livehelperchat && deploy/backup.sh >> backups/backup.log 2>&1
```

Copy `backups/` off the server (object storage, another host). `.env` is not in the backup on purpose; keep it in your secret store.

Disaster recovery on a new server: install Docker, clone the repository, put the saved `.env` in place, copy the backup directory over, run `deploy/restore.sh <dir> --yes`, then `docker compose --profile tls up -d`. CI performs exactly this after destroying every volume.

## 6. Monitoring

- Liveness for load balancers and uptime checks: `GET /index.php/system/health` returns 200 or 503. With `?token=<LHC_HEALTH_TOKEN>` it adds per-check details.
- Prometheus: `GET /index.php/system/metrics` with `Authorization: Bearer <LHC_METRICS_TOKEN>`.

```yaml
scrape_configs:
  - job_name: livehelperchat
    scheme: https
    metrics_path: /index.php/system/metrics
    authorization: { credentials: <LHC_METRICS_TOKEN> }
    static_configs: [{ targets: ['chat.example.com'] }]
```

Suggested alerts: `lhc_up == 0` or scrape failing for 2 minutes; `lhc_pending_chat_oldest_seconds > 120`; `lhc_operators_online == 0` during business hours; `lhc_login_locks_active > 20` (brute-force attempt); backup cron log not updated for 26 hours.

Logs: `docker compose logs -f web` (Apache and PHP), `scheduler` (one line per background job), `caddy` (TLS and access).

## 7. Known limits

- This fork is based on Live Helper Chat from February 2023. Upstream has released security fixes since; review and merge them before or soon after going live.
- The scheduler runs jobs every minute in one container; do not scale it beyond one replica. The web service can be scaled horizontally only with shared storage for the `lhc-uploads` and `lhc-data` volumes.
- Real-time push (the optional NodeJS helper) is not part of this stack; the widget and back office use polling, which is the upstream default.
