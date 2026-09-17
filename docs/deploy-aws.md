# FinPulse — AWS EC2 Deployment Guide

Deploy FinPulse on an AWS EC2 free-tier instance with Docker, MySQL and automatic HTTPS.

> [!NOTE]
> **Cost**: The EC2 `t2.micro` is free for 12 months under the AWS Free Tier.
> After that it's roughly **$8–10/month**. You can stop the instance when not needed.

---

## Architecture

```
┌─────────────┐      ┌──────────────┐      ┌──────────────┐
│   Caddy      │ 443  │   FinPulse   │  3306 │    MySQL     │
│  (HTTPS)     ├─────►│  PHP+Apache  ├──────►│    8.0       │
│  :80 / :443  │      │   container  │       │  container   │
└─────────────┘      └──────────────┘      └──────────────┘
       ▲                                         │
       │                                    Docker volume
    Internet                              (persistent data)
```

---

## Step 1 — Launch an EC2 Instance

1. Go to the [EC2 Console](https://console.aws.amazon.com/ec2/) and click **Launch Instance**.
2. Configure:
   | Setting | Value |
   |---|---|
   | **Name** | `finpulse` |
   | **AMI** | Ubuntu Server 24.04 LTS (free tier eligible) |
   | **Instance type** | `t2.micro` (free tier) |
   | **Key pair** | Create a new key pair → download the `.pem` file |
   | **Security group** | Allow **SSH (22)**, **HTTP (80)**, **HTTPS (443)** from anywhere |
   | **Storage** | 20 GB gp3 (free tier allows up to 30 GB) |

3. Click **Launch Instance** and wait for it to start.
4. Note the **Public IPv4 address** from the instance details.

---

## Step 2 — Connect and Install Docker

SSH into your instance:

```bash
ssh -i your-key.pem ubuntu@YOUR_EC2_PUBLIC_IP
```

Then install Docker:

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com | sudo sh

# Let your user run Docker without sudo
sudo usermod -aG docker $USER

# Install Docker Compose plugin
sudo apt install -y docker-compose-plugin

# Log out and back in for the group change to take effect
exit
```

SSH back in:

```bash
ssh -i your-key.pem ubuntu@YOUR_EC2_PUBLIC_IP
```

Verify Docker works:

```bash
docker --version
docker compose version
```

---

## Step 3 — Clone and Configure FinPulse

```bash
# Clone your repo
git clone https://github.com/SamisDone/FinPulse.git ~/finpulse
cd ~/finpulse

# Create the production .env from the template
cp .env.production .env
```

Edit `.env` with your settings:

```bash
nano .env
```

**Required changes:**
- `DB_PASS` → set a strong password (e.g. `openssl rand -base64 24`)
- `DB_ROOT_PASSWORD` → set a different strong password
- `APP_URL` → your domain (e.g. `https://finpulse.yourdomain.com`) or `http://YOUR_EC2_PUBLIC_IP`
- `DOMAIN` → your domain for HTTPS, or `localhost` for HTTP only

Save with `Ctrl+O`, exit with `Ctrl+X`.

---

## Step 4 — Deploy

```bash
cd ~/finpulse
docker compose -f docker-compose.prod.yml up -d --build
```

Wait a minute for MySQL to initialize, then check:

```bash
# Verify all 3 containers are running
docker compose -f docker-compose.prod.yml ps

# Check logs if something looks wrong
docker compose -f docker-compose.prod.yml logs app
```

Visit `http://YOUR_EC2_PUBLIC_IP` — you should see FinPulse! 🎉

---

## Step 5 — Set Up a Domain + HTTPS (Optional)

If you have a domain:

1. In your DNS provider, add an **A record** pointing to your EC2 public IP:
   ```
   finpulse.yourdomain.com  →  YOUR_EC2_PUBLIC_IP
   ```

2. Update `.env`:
   ```
   APP_URL=https://finpulse.yourdomain.com
   DOMAIN=finpulse.yourdomain.com
   ```

3. Redeploy:
   ```bash
   docker compose -f docker-compose.prod.yml up -d
   ```

Caddy will automatically get a Let's Encrypt certificate — HTTPS just works.

> [!TIP]
> **No domain?** You can get a free subdomain from [DuckDNS](https://www.duckdns.org/)
> or [FreeDNS](https://freedns.afraid.org/).

---

## Step 6 — Auto-Deploy from GitHub (Optional)

A GitHub Actions workflow is already set up at `.github/workflows/deploy.yml`.
It auto-deploys to your EC2 instance whenever you push to `main`.

### Add these secrets to your GitHub repo:

Go to **GitHub → Your repo → Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
|---|---|
| `EC2_HOST` | Your EC2 public IP address |
| `EC2_USER` | `ubuntu` |
| `EC2_SSH_KEY` | Contents of your `.pem` private key file |

Now every `git push` to `main` automatically deploys to your server.

---

## Useful Commands

```bash
# View running containers
docker compose -f docker-compose.prod.yml ps

# View logs (live)
docker compose -f docker-compose.prod.yml logs -f app

# Restart everything
docker compose -f docker-compose.prod.yml restart

# Pull latest code and redeploy
cd ~/finpulse && git pull && docker compose -f docker-compose.prod.yml up -d --build

# Back up the MySQL database
docker compose -f docker-compose.prod.yml exec db \
  mysqldump -u finpulse -p finpulse > backup_$(date +%F).sql

# Run the cron job manually
docker compose -f docker-compose.prod.yml exec app php scripts/cron.php

# Stop everything
docker compose -f docker-compose.prod.yml down

# Stop and remove all data (⚠️ deletes database!)
docker compose -f docker-compose.prod.yml down -v
```

---

## Keeping It Running

The `restart: unless-stopped` policy in docker-compose.prod.yml ensures containers
auto-restart after a crash or EC2 reboot. Docker itself starts on boot by default on Ubuntu.

To **prevent your free-tier instance from being stopped** due to billing:
- Set up a [billing alarm](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/monitor_estimated_charges_with_cloudwatch.html) at $0 so you know if you go over the free tier.
