# FinPulse — Render Deployment Guide

Deploy FinPulse on Render's free tier with Docker and a free external MySQL database.

> [!NOTE]
> **Cost**: Completely free. Render free tier + TiDB Cloud Serverless free tier.
> The trade-off: the app spins down after 15 min of inactivity (cold starts take ~30s).

---

## Architecture

```
┌────────────────┐           ┌─────────────────────────┐
│   Render       │   MySQL   │   TiDB Cloud Serverless │
│   (Docker)     ├──────────►│   (free, 5 GB)          │
│   FinPulse     │           │   MySQL-compatible      │
│   free tier    │           └─────────────────────────┘
└────────────────┘
       ▲
    Internet
  (HTTPS included)
```

---

## Step 1 — Set Up a Free MySQL Database

Render doesn't offer MySQL, so we use **TiDB Cloud Serverless** (MySQL-compatible, 5 GB free, no expiry).

1. Go to [tidbcloud.com](https://tidbcloud.com/) and sign up (GitHub login works).
2. Click **Create Cluster** → choose **Serverless** (free).
3. Pick a region close to your Render service (e.g. US East).
4. Once created, click **Connect** → choose **General** connection method.
5. Note down these values:
   - **Host** (e.g. `gateway01.us-east-1.prod.aws.tidbcloud.com`)
   - **Port** (usually `4000`)
   - **Username** (e.g. `randomstring.root`)
   - **Password** (the one you set or was generated)
6. Create a database called `finpulse`:
   - Click **SQL Editor** in TiDB Cloud
   - Run: `CREATE DATABASE finpulse;`

> [!TIP]
> TiDB requires SSL. Add `?sslmode=required` if you hit connection issues,
> but the default PHP MySQL driver should handle it automatically.

---

## Step 2 — Deploy to Render

### Option A: One-click Blueprint (recommended)

1. Push your latest code to GitHub (make sure `render.yaml` and the updated `docker-entrypoint.sh` are committed).
2. Go to [dashboard.render.com](https://dashboard.render.com/).
3. Click **New** → **Blueprint**.
4. Connect your GitHub repo (`SamisDone/FinPulse`).
5. Render will detect `render.yaml` and create the service.
6. Before deploying, add your environment variables (see Step 3).

### Option B: Manual setup

1. Go to [dashboard.render.com](https://dashboard.render.com/).
2. Click **New** → **Web Service**.
3. Connect your GitHub repo (`SamisDone/FinPulse`).
4. Configure:
   | Setting | Value |
   |---|---|
   | **Name** | `finpulse` |
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
| `APP_URL` | `https://finpulse-XXXX.onrender.com` (Render gives you this URL after creating the service) |
| `APP_TIMEZONE` | `UTC` |
| `DB_TYPE` | `mysql` |
| `DB_HOST` | Your TiDB host (e.g. `gateway01.us-east-1.prod.aws.tidbcloud.com`) |
| `DB_PORT` | `4000` |
| `DB_NAME` | `finpulse` |
| `DB_USER` | Your TiDB username |
| `DB_PASS` | Your TiDB password |
| `MAIL_DRIVER` | `log` |

> [!IMPORTANT]
> Update `APP_URL` with the actual `.onrender.com` URL after your first deploy.

---

## Step 4 — Verify

Once the deploy finishes (2–3 minutes):

1. Visit your Render URL: `https://finpulse-XXXX.onrender.com`
2. You should see the FinPulse landing page.
3. Create an account and verify everything works.

---

## Auto-Deploy

Render automatically redeploys whenever you push to `main`. No GitHub Actions needed — it's built in.

---

## Custom Domain (Optional)

1. In Render dashboard → your service → **Settings** → **Custom Domains**.
2. Add your domain (e.g. `finpulse.yourdomain.com`).
3. Add the CNAME record Render gives you to your DNS provider.
4. Update `APP_URL` in environment variables to your custom domain.
5. Render handles HTTPS automatically.

---

## Limitations of the Free Tier

| Limitation | Impact | Workaround |
|---|---|---|
| **Spins down after 15 min idle** | First visit after idle takes ~30s | Use [UptimeRobot](https://uptimerobot.com/) (free) to ping it every 14 min |
| **No persistent disk** | Can't use SQLite (data would be lost) | External MySQL (TiDB) solves this |
| **750 free hours/month** | Enough for one service running 24/7 | Only run one free service |
| **Limited CPU/RAM** | Fine for a personal finance app | Upgrade to Starter ($7/mo) if needed |

> [!TIP]
> **Keep it awake for free**: Sign up at [uptimerobot.com](https://uptimerobot.com/),
> add an HTTP monitor for your Render URL with a 14-minute interval.
> This prevents the cold-start spin-down.

---

## Useful Links

- [Render Dashboard](https://dashboard.render.com/)
- [TiDB Cloud Console](https://tidbcloud.com/)
- [Render Docker Docs](https://render.com/docs/docker)
- [UptimeRobot](https://uptimerobot.com/) (free ping service)
