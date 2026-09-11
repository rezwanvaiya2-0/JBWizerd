# JBWizerd — Docker Installation Guide

Run the entire JBWizerd panel (PHP + Apache + MySQL + phpMyAdmin) in Docker containers.
Everything is pre-configured — no manual PHP/MySQL setup needed.

## 1. Prerequisites

You need a server (VPS / dedicated) with Docker installed:

| OS | Install command |
|---|---|
| **Ubuntu / Debian** | `apt install docker.io docker-compose-v2 -y` |
| **CentOS / AlmaLinux** | `dnf install docker docker-compose-plugin -y` |
| **Windows / macOS** | [Install Docker Desktop](https://www.docker.com/products/docker-desktop/) |

Verify it works:

```bash
docker --version
docker compose version
```

## 2. Get the files

```bash
git clone https://github.com/rezwanvaiya2-0/JBWizerd.git
cd JBWizerd
```

## 3. Configure

Open `docker-compose.yml` and set your **registration key** (this is the key your
JetBackup servers use to register with the panel):

```yaml
environment:
  JBWIZERD_REGISTRATION_KEY: YOUR-REGISTRATION-KEY-HERE   # <- change this
```

Example: `JBWIZERD_REGISTRATION_KEY: A1B2-C3D4-E5F6-G7H8`

You can also change (optional):

| Variable | Default | Purpose |
|---|---|---|
| `JBWIZERD_PANEL_URL` | `http://localhost:8080` | Public URL of the panel |
| `JBWIZERD_TIMEZONE` | `Asia/Dhaka` | Display timezone |
| `JBWIZERD_DB_PASS` | `jbpass` | MySQL password |
| `MYSQL_ROOT_PASSWORD` | `rootpass` | MySQL root password |

> For production, change the MySQL passwords too.

## 4. Start everything

```bash
docker compose up -d --build
```

This starts 3 containers:

| Container | Purpose | URL |
|---|---|---|
| `jbwizerd_web` | The panel (Apache + PHP) | http://localhost:8080 |
| `jbwizerd_phpmyadmin` | Database manager | http://localhost:8081 |
| `jbwizerd_db` | MySQL database | internal (not exposed) |

Wait a moment for the database to become healthy, then check:

```bash
docker compose ps
```

All three should show `Up` / `healthy`.

## 5. First-time setup (the panel)

1. Open **http://localhost:8080/setup.php**
2. The installer shows pre-filled database details (already correct for Docker):
   - Database Host: `db`
   - Database Name: `jbwizerd`
   - Database User: `jbuser`
   - Database Password: `jbpass`
3. Set the panel URL (default `http://localhost:8080`)
4. Enter your registration key (same as step 3)
5. Create the admin user + password
6. Done — you're logged in

> `setup.php` disables itself automatically after the first run.

## 6. Manage the database with phpMyAdmin

Open **http://localhost:8081**

| Field | Value |
|---|---|
| Server | `db` (already filled) |
| Username | `root` |
| Password | `rootpass` |

You can also log in as `jbuser` / `jbpass`.

Here you can:
- Browse the `jbwizerd` database tables (`backups`, `servers`, `webhooks`, ...)
- Run SQL queries (e.g. cleanup, debugging)
- Export / import backups of the panel data

## 7. Connect your JetBackup servers

On each JetBackup server, run the install command with your panel URL + registration key:

```bash
bash <(curl -sL http://YOUR_SERVER_IP:8080/hook/install.sh) \
  --panel-url http://YOUR_SERVER_IP:8080 \
  --register-key A1B2-C3D4-E5F6-G7H8
```

Then add the Pre/Post hooks in **JetBackup 5 → API / Hooks**:

```
/usr/bin/python3 /JBWizerd/jb_hook.py    (Pre backup hook)
/usr/bin/python3 /JBWizerd/jb_hook.py    (Post backup hook)
```

> Replace `YOUR_SERVER_IP` with your server's public IP, and expose port `8080`
> in your firewall if the JetBackup servers connect over the internet.

## 8. Common commands

```bash
# See container status
docker compose ps

# See the panel logs
docker compose logs -f web

# See the MySQL logs
docker compose logs -f db

# Restart the panel
docker compose restart web

# Stop everything
docker compose down

# Rebuild after pulling new code
git pull
docker compose up -d --build

# Wipe the database for real (see "Volumes & data" below)
rm -rf ./mysql-data
```

## 9. Troubleshooting

| Problem | Fix |
|---|---|
| Panel shows "config.php is missing" | The container writes it from env vars on first boot. Check `docker compose logs web`. |
| Can't connect to database | Make sure `db` is healthy: `docker compose ps`. The web container waits for it. |
| Port 8080 already in use | Change `"8080:80"` to e.g. `"9090:80"` in `docker-compose.yml`, then `docker compose up -d`. |
| Port 8081 already in use | Change `"8081:80"` (phpMyAdmin) the same way. |
| Can't reach phpMyAdmin | It binds to `localhost:8081` — use `http://localhost:8081` from the same machine, or the server IP if remote. |
| Changed DB password | Update both the `db` and `web` environment variables, then `docker compose up -d`. |
| Backups aren't reporting | Check `docker compose logs web` and the server's `/JBWizerd/hook-errors.log`. |
| MySQL fails to start on CentOS/AlmaLinux with SELinux | Relabel the data folder: `chcon -Rt container_file_t ./mysql-data`, or add `:Z` to the mount: `- ./mysql-data:/var/lib/mysql:Z`. |

## 10. Volumes & data

Your database lives **on the host** in the `mysql-data/` folder inside the project directory
(`./mysql-data` is mounted to the MySQL container's `/var/lib/mysql`).

- **Persistent storage** — data survives `docker compose down`, container rebuilds, `git pull` +
  rebuild, and even `docker compose down -v` (bind mounts are not deleted by `-v`).
- **Safe to reinstall** — you can delete and re-create the containers any time without losing data.
- **The only way to wipe the database** is to delete the folder yourself: `rm -rf ./mysql-data`
  (stop the stack first: `docker compose down`).

> `mysql-data/` is excluded from git (`.gitignore`) and from the Docker build context
> (`.dockerignore`), so it never gets committed or baked into the image.

The schema is pre-seeded from `install.sql` automatically **only on the very first boot** —
when `mysql-data/` is empty. After that, MySQL reuses the existing data directory.

### Migrating from an existing named volume (only if you already ran the old setup)

If you previously ran this stack when it used the Docker volume `db_data`, your data is in the
named volume, not in `./mysql-data`. Move it once before starting the new stack:

```bash
# 1. Stop the stack (keeps the old volume intact)
docker compose down

# 2. Copy the data from the old volume into the new host folder
mkdir -p mysql-data
docker run --rm \
  -v jbwizerd_db_data:/from \
  -v "$(pwd)/mysql-data":/to \
  alpine cp -a /from/. /to/

# 3. Start the stack — MySQL now uses ./mysql-data
docker compose up -d --build
```

Verify the panel still shows your data, then remove the old volume when you're sure:

```bash
docker volume rm jbwizerd_db_data
```

### Backups

To back up the database:

```bash
docker compose exec db mysqldump -uroot -prootpass jbwizerd > jbwizerd-backup.sql
```

To restore:

```bash
docker compose exec -T db mysql -uroot -prootpass jbwizerd < jbwizerd-backup.sql
```
