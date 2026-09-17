# Sixpence — Render Deployment Guide

Deploy Sixpence on Render's free tier with Docker and a managed PostgreSQL database.

> [!NOTE]
> **Cost**: Free, with two trade-offs. The app spins down after 15 min of inactivity
> (cold starts take ~30s), and **Render's free PostgreSQL expires 30 days after
> creation**, with a 14-day grace period before the data is deleted. For anything
> you intend to keep, either upgrade the database to a paid plan or use a provider
> whose free tier does not expire — see Step 1.

---

## Architecture

```
┌────────────────┐            ┌─────────────────────────┐
│   Render       │ PostgreSQL │   Render PostgreSQL     │
│   (Docker)     ├───────────►│   (free: 1 GB, expires  │
│   Sixpence     │            │    after 30 days)       │
│   free tier    │            └─────────────────────────┘
└────────────────┘
       ▲
    Internet
  (HTTPS included)
```

---

## Step 1 — Choose a PostgreSQL Database

**Option A — Render PostgreSQL (simplest).** `render.yaml` already declares it, so
the Blueprint in Step 2 creates it for you and injects `DATABASE_URL` automatically.
Nothing to do here. Note the 30-day expiry above before you rely on it.

**Option B — a free tier that does not expire.** Recommended if this holds data you
care about. Both of these give you a `postgres://` connection string to paste into
`DATABASE_URL` as a normal environment variable:

- [Neon](https://neon.tech) — free Postgres, no expiry
- [Supabase](https://supabase.com) — free Postgres, no expiry

If you pick Option B, delete the `databases:` block from `render.yaml` and set
`DATABASE_URL` in the Render dashboard instead.

> [!TIP]
> The scheme in `DATABASE_URL` selects the driver, so `postgres://...` is all the
> app needs — `DB_TYPE` can be left unset. Any host other than `localhost` defaults
> to `sslmode=require`, which is what managed providers expect.

---

## Step 2 — Deploy to Render

### Option A: One-click Blueprint (recommended)

1. Push your latest code to GitHub (make sure `render.yaml` and the updated `docker-entrypoint.sh` are committed).
2. Go to [dashboard.render.com](https://dashboard.render.com/).
3. Click **New** → **Blueprint**.
4. Connect your GitHub repo (`SamisDone/Sixpence`).
5. Render will detect `render.yaml` and create both the service and the database.
6. Before deploying, add your environment variables (see Step 3).

### Option B: Manual setup

1. Go to [dashboard.render.com](https://dashboard.render.com/).
2. Click **New** → **Web Service**.
3. Connect your GitHub repo (`SamisDone/Sixpence`).
4. Configure:
   | Setting | Value |
   |---|---|
   | **Name** | `sixpence` |
   | **Runtime** | Docker |
   | **Plan** | Free |
   | **Branch** | `main` |
5. Add environment variables (see Step 3).
6. Click **Deploy**.

---

## Step 3 — Set Environment Variables

In Render dashboard → your service → **Environment** tab, add:

| Key | Value |
|---|---|
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://sixpence-XXXX.onrender.com` (Render gives you this URL after creating the service) |
| `APP_TIMEZONE` | `UTC` |
| `DATABASE_URL` | Set automatically by the Blueprint (Option A). For Option B, paste the `postgres://...` string from Neon or Supabase |
| `MAIL_DRIVER` | `log` |

Individual `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` variables still
work if you prefer them, with `DB_TYPE` set to `pgsql` — but `DATABASE_URL` overrides
them all when present.

> [!IMPORTANT]
> Update `APP_URL` with the actual `.onrender.com` URL after your first deploy.

---

## Step 4 — Verify

Once the deploy finishes (2–3 minutes):

1. Visit your Render URL: `https://sixpence-XXXX.onrender.com`
2. You should see the Sixpence landing page.
3. Create an account and verify everything works.

---

## Auto-Deploy

Render automatically redeploys whenever you push to `main`. No GitHub Actions needed — it's built in.

---

## Custom Domain (Optional)

1. In Render dashboard → your service → **Settings** → **Custom Domains**.
2. Add your domain (e.g. `sixpence.yourdomain.com`).
3. Add the CNAME record Render gives you to your DNS provider.
4. Update `APP_URL` in environment variables to your custom domain.
5. Render handles HTTPS automatically.

---

## Limitations of the Free Tier

| Limitation | Impact | Workaround |
|---|---|---|
| **Spins down after 15 min idle** | First visit after idle takes ~30s | Use [UptimeRobot](https://uptimerobot.com/) (free) to ping it every 14 min |
| **No persistent disk** | Uploads and logs vanish on redeploy | All durable state lives in PostgreSQL |
| **750 free hours/month** | Enough for one service running 24/7 | Only run one free service |
| **Limited CPU/RAM** | Fine for a personal finance app | Upgrade to Starter ($7/mo) if needed |

> [!TIP]
> **Keep it awake for free**: Sign up at [uptimerobot.com](https://uptimerobot.com/),
> add an HTTP monitor for your Render URL with a 14-minute interval.
> This prevents the cold-start spin-down.

---

## Useful Links

- [Render Dashboard](https://dashboard.render.com/)
- [Render PostgreSQL docs](https://render.com/docs/postgresql)
- [Render Docker Docs](https://render.com/docs/docker)
- [UptimeRobot](https://uptimerobot.com/) (free ping service)
