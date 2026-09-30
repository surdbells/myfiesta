# Running it on a Contabo VPS with aaPanel

One Ubuntu server at Contabo, with aaPanel on it, and perhaps the old myFiesta
app on it too. This is the whole arrangement, in the order it is done, with a
check after each step that says whether it worked. Everything in it can be
done again on a box where it has already been done.

[DEPLOYMENT.md](DEPLOYMENT.md) says what any arrangement has to provide and
why; [OPERATIONS.md](OPERATIONS.md) is what keeps it running;
[CUTOVER.md](CUTOVER.md) is the move from the old app. This file does not
repeat them. It says how each is done on this one kind of box.

## What runs where

```
internet ── 80/443 ──► aaPanel's nginx: TLS, Let's Encrypt, one site per name
                         myfiesta.ca, www ──► 127.0.0.1:4000  site     (Node, server-rendered)
                         console.…        ──► 127.0.0.1:4310  console  (static files)
                         api.… (+ /admin) ──► 127.0.0.1:8000  api-web ──► api (php-fpm)
                                                                           │
                         Docker network myfiesta_default, 172.30.57.0/24:  ├─ postgres 17  (no port)
                           worker, scheduler (no ports)                    └─ redis 7      (no port)

the old app, if it is here: an aaPanel PHP site and MySQL on 3306, never open to the internet
  ◄── read only, by the `legacy` container, only while an import runs
```

Two compose files: `ops/docker/compose.prod.yml`, which is every arrangement,
and `ops/docker/compose.contabo.yml` on top of it, which adds Postgres and
Redis beside the application, pins the network's numbers and adds the
import's container. `ops/deploy/` has the three scripts this file runs:
`deploy.sh`, `rollback.sh` and `legacy-sync.sh`.

aaPanel keeps what it is good at: nginx on 80 and 443, certificates and their
renewal, the old PHP site and its MySQL. Docker runs the platform. Neither
touches the other's parts, and the rest of this file is mostly about keeping
it that way.

**Conventions.** `#` in front of a command means as root (or with `sudo`), `$`
as the `deploy` user in `/opt/myfiesta`. `<angle brackets>` are yours to fill
in. The host names are the ones in `.env.production.example`; use yours.

## 0. Before you start

- A Contabo VPS on Ubuntu 22.04 or 24.04, sized as below, with aaPanel 7 and
  its **Nginx** (not Apache or OpenLiteSpeed) installed from its App Store.
- DNS records you can change for `myfiesta.ca`, `www`, `api` and `console`.
  `api` and `console` point at this server from the start (Let's Encrypt
  checks them over http). `myfiesta.ca` and `www` move at the cutover if the
  old app still answers on them ([12.5](#125-switching-myfiestaca)).
- The values only the operator can supply: every gateway key, the mail
  sender, the backup bucket, the contact details. [LAUNCH.md](LAUNCH.md) lists
  them and says where each one comes from.
- An SSH key on your own computer. Nothing below uses a password to log in.

## 1. The server

### 1.1 Size

| | Enough | Comfortable |
| --- | --- | --- |
| vCPU | 4 | 6 or more |
| Memory | 8 GB, with 4 GB of swap | 12–16 GB |
| Disk | 75 GB NVMe | 150 GB or more |

Where it goes. The running platform is about 2.5–3 GB: Postgres with its
512 MB of shared buffers, Redis, php-fpm's handful of processes, the worker,
the scheduler, the site's Node and two small nginx. aaPanel with the old
app's PHP and MySQL is another 1–1.5 GB. The peak is the build: the images
are built on this box ([4.1](#41-the-checkout)), and each Angular build wants
2–3 GB on its own, which is why `deploy.sh` builds one image at a time and
why the swap is there. Disk: each release's four images come to 2–3 GB and
three releases are kept, Docker's build cache takes 10–15 GB, then the
database, the posters (about 300 MB from the old app) and the logs.

PgBouncer is not needed at this size. php-fpm, the worker and the scheduler
hold a few dozen connections at most against Postgres' 100, and the nightly
backup has to connect directly anyway (DEPLOYMENT.md). It earns its place
with several application servers, not one.

Swap, so the build is slowed down rather than killed:

```sh
# fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
# echo '/swapfile none swap sw 0 0' >> /etc/fstab
# printf 'vm.swappiness=10\n' > /etc/sysctl.d/99-myfiesta-swap.conf && sysctl --system
```

**Check.** `swapon --show` lists `/swapfile` at 4G; `free -h` shows it;
`nproc` and `df -h /` are what you paid for.

### 1.2 Hostname, UTC and the clock

The platform stores every time in UTC and schedules its nightly work in UTC
(the backup at 06:30, the clean-ups at 05:00). The host in UTC too means cron,
aaPanel's logs, Docker's logs and the application all name the same moment
the same way. And the clock has to be right: Stripe and Paystack refuse a
webhook signature more than a few minutes off, and every sign-in code and
signed link has an expiry.

```sh
# hostnamectl set-hostname myfiesta-1
# sed -i 's/^127\.0\.1\.1.*/127.0.1.1 myfiesta-1/' /etc/hosts; grep -q myfiesta-1 /etc/hosts || echo '127.0.1.1 myfiesta-1' >> /etc/hosts
# timedatectl set-timezone Etc/UTC
# apt-get update && apt-get install -y chrony
# systemctl enable --now chrony
```

chrony replaces systemd-timesyncd, and keeps better time on a virtual machine
whose clock drifts when the host is busy.

**Check.** `hostnamectl` shows `myfiesta-1`. `timedatectl` shows `Time zone:
Etc/UTC` and `System clock synchronized: yes`. `chronyc tracking` shows
`Leap status: Normal` and a system time offset in milliseconds.

### 1.3 Security updates

Ubuntu's security fixes, installed every day without anybody remembering to.
Only Ubuntu's security pocket: Docker's own packages are not in it, so a
Docker upgrade — which restarts the daemon — happens when you choose (with
`live-restore`, [3](#3-docker), the containers keep running through it).

```sh
# apt-get install -y unattended-upgrades
# dpkg-reconfigure -f noninteractive unattended-upgrades
```

It does not reboot by itself, on purpose: a reboot at 4 a.m. UTC is 11 p.m. or
midnight in Toronto, when a door may be scanning tickets. When
`/var/run/reboot-required` exists, reboot at a quiet hour you choose; every
container comes back by itself (`restart: unless-stopped`).

**Check.** `systemctl is-enabled unattended-upgrades` says `enabled`;
`unattended-upgrade --dry-run 2>&1 | tail -3` runs without an error;
`/var/log/unattended-upgrades/` fills in over the next day.

### 1.4 Who logs in

Two accounts besides root, which stops logging in at [1.5](#15-ssh):

- **you** (one account per person who administers the box), with `sudo`;
- **`deploy`**, which owns the checkout and runs Docker, without `sudo`.

```sh
# adduser --gecos '' <you> && usermod -aG sudo <you>
# install -d -m 700 -o <you> -g <you> /home/<you>/.ssh
# nano /home/<you>/.ssh/authorized_keys        # your public key, one line
# chown <you>:<you> /home/<you>/.ssh/authorized_keys && chmod 600 /home/<you>/.ssh/authorized_keys

# adduser --disabled-password --gecos '' deploy
# install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
# cp /home/<you>/.ssh/authorized_keys /home/deploy/.ssh/ && chown deploy:deploy /home/deploy/.ssh/authorized_keys
```

`deploy` joins the `docker` group once Docker is installed
([3](#3-docker)). Membership of that group is as good as root on this box —
anything that can start a container can mount the disk — so its key is
guarded like root's.

**Check.** From your computer, in a new terminal, `ssh <you>@<server>` and
`ssh deploy@<server>` both log in without a password, and `sudo -v` works for
you. Keep the root session open until they do.

### 1.5 SSH

Keys only, no root, and only the two accounts above:

```sh
# cat > /etc/ssh/sshd_config.d/10-myfiesta.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
X11Forwarding no
AllowUsers <you> deploy
EOF
# sshd -t && systemctl reload ssh
```

Contabo sends a root password when the server is made. After this it opens
nothing. If you ever lock yourself out, Contabo's customer panel has a VNC
console and a rescue system.

**Check.** Before closing the root session, from your computer:
`ssh root@<server>` is refused, `ssh -o PubkeyAuthentication=no <you>@<server>`
is refused with `Permission denied (publickey)`, and `ssh <you>@<server>` still
works.

## 2. aaPanel's own security

aaPanel is a web page that can do anything root can. It is the easiest way
into this box for anybody else, and the first thing to close.

### 2.1 The panel

`bt 14` (as root) prints the panel's address, port and user. Then, in the panel:

1. **Settings → Panel port**: something other than what was printed, if it is
   a well-known one (8888, 7800). Note it for the firewall below.
2. **Settings → Security entrance**: a long random path. Without it the login
   page is at the port's root for every scanner to find.
3. **Settings → Panel SSL**: on, so the panel's password never crosses the
   internet in the clear.
4. **Settings → Google authenticator** (two-step verification): on, with the
   code in your authenticator app. If the phone is lost, `bt` as root has an
   option that switches it off.
5. **Settings → Password**: a long one from the password manager, and a panel
   user name that is not `admin`.
6. Optionally **Settings → Authorized IP**: your own addresses only.

**Check.** `https://<server>:<panel port>/` without the entrance path shows
aaPanel's refusal, not a login form; with it, the login asks for the second
factor; `bt 14` shows the new port and entrance.

### 2.2 The firewall

Only four ports open to the world: 22 for SSH, 80 and 443 for the sites, and
the panel's port. Unless you have set one up in Contabo's panel, nothing
filters traffic before it reaches the VPS, so this is the only firewall there
is.

**Never open to the world:** 3306 (the old app's MySQL), 5432 and 6379 (the
platform's Postgres and Redis — they publish no port at all, see below), 8000,
4000 and 4310 (the platform's own http, which aaPanel's nginx reaches on
127.0.0.1), 888 (aaPanel's phpMyAdmin), and 20, 21 and 39000–40000 (FTP)
unless the old site really is still updated over FTP.

On Ubuntu, aaPanel's **Security → Firewall** page manages `ufw`'s rules. Use
either, and trust `ufw status` over the page if the two ever disagree. By hand:

```sh
# ufw default deny incoming && ufw default allow outgoing
# ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw allow <panel port>/tcp
# ufw status                   # what else is open: aaPanel opens 20, 21, 888, 39000:40000 and its first panel port
# ufw delete allow 21/tcp      # each of those, one at a time, written as `ufw status` shows it
# ufw enable
```

Delete by the rule, never by its number from `ufw status numbered`: ufw
numbers the rules again after every delete, so the second number of a list
taken once is already another rule's — and one of them is 22, which ends SSH
for everybody once this session closes. A rule `ufw status` shows as `888`
rather than `888/tcp` is deleted as `ufw delete allow 888`; `Could not delete
non-existent rule` means it was written another way, and nothing changed.

**Docker goes around ufw.** A port Docker publishes is let in by Docker's own
iptables rules before ufw's are read, so `ufw deny 8000` does nothing for a
container published on `0.0.0.0:8000`. That is why the compose file publishes
the site, the console and the API on `127.0.0.1` only (`SITE_BIND`,
`CONSOLE_BIND`, `API_BIND`), and why Postgres and Redis publish nothing. Leave
all three at `127.0.0.1`.

**Check.** `ufw status verbose` lists only the four ports (and, later, the one
rule of [12.1](#121-the-old-database-read-only)). From your own computer,
once the platform is running:

```sh
for p in 3306 5432 6379 8000 4000 4310 888; do nc -vz -w 3 <server> $p; done
```

Every one times out or is refused. On the server, `ss -ltnp | grep -E
':(8000|4000|4310) '` shows each on `127.0.0.1` only, and nothing listens on
5432 or 6379.

## 3. Docker

Docker Engine and the compose plugin **from Docker's own apt repository**, not
from aaPanel's Docker manager. It is the same engine, but Docker's repository
has the current engine, the compose plugin (the platform needs Compose 2.17
or later: every command reads two `--env-file`s) and buildx, and updates with
`apt` when you choose. aaPanel's module installs on its own schedule, has
shipped the old `docker-compose` v1, and offers buttons that change containers
behind compose's back, which compose then undoes on the next deploy.

If Docker is already installed (`docker version`), keep it when
`docker compose version` says 2.17 or later, and go on to `daemon.json` —
merged into the one it has, not written over it.

```sh
# apt-get install -y ca-certificates curl
# install -m 0755 -d /etc/apt/keyrings
# curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc && chmod a+r /etc/apt/keyrings/docker.asc
# echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}") stable" > /etc/apt/sources.list.d/docker.list
# apt-get update && apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Container logs rotated, so a chatty container cannot fill the disk; and
`live-restore`, so the containers keep running while the Docker daemon itself
is upgraded or restarted:

```sh
# docker info --format '{{.DockerRootDir}}'      # note it, to compare after the restart
# apt-get install -y jq
# f=/etc/docker/daemon.json; [ -s "$f" ] || echo '{}' > "$f"
# jq '. + {"log-driver": "json-file", "log-opts": {"max-size": "20m", "max-file": "5"}, "live-restore": true}' "$f" > "$f.new" && mv "$f.new" "$f"
# systemctl restart docker
# usermod -aG docker deploy
```

Merged, because a Docker that was already here may have settings of its own
in that file — `data-root` above all, where its containers and images are.
Written over, the restart would start a Docker looking in
`/var/lib/docker`, and every container and image the host had would be
gone from `docker ps` and `docker image ls`. `jq` stops, and nothing is
replaced, if the file is not valid JSON.

The log settings apply to containers created after them, which on a new box is
all of them.

**Check.** `docker compose version` is 2.17 or later. `docker info --format
'{{.LoggingDriver}} {{.LiveRestoreEnabled}} {{.DockerRootDir}}'` prints
`json-file true` and the same directory as before the restart. As
`deploy` (log in again for the group to apply), `docker run --rm hello-world`
prints its greeting. `docker network ls` and `ip -4 route` show nothing in
172.30.57.0/24; if something does, pick another range now
([13](#13-when-something-is-wrong), "Pool overlaps").

## 4. The code and the settings

### 4.1 The checkout

The images are built on this server, from a checkout of the repository, and
tagged with the commit they were built from. CI builds every image on every
change to prove it still builds, and pushes none; a registry would only move
the build somewhere else, so there is none by default. The checkout is
`/opt/myfiesta`, owned by `deploy`, reading the repository with a deploy key
that cannot write to it.

```sh
# install -d -m 755 -o deploy -g deploy /opt/myfiesta
```

As `deploy`:

```sh
$ ssh-keygen -t ed25519 -N '' -C 'myfiesta-1 deploy key' -f ~/.ssh/github_myfiesta
$ cat ~/.ssh/github_myfiesta.pub
```

Add that line on GitHub under the repository's **Settings → Deploy keys**,
without write access. Then:

```sh
$ printf 'Host github.com\n  IdentityFile ~/.ssh/github_myfiesta\n  IdentitiesOnly yes\n' >> ~/.ssh/config && chmod 600 ~/.ssh/config
$ git clone git@github.com:surdbells/myfiesta.git /opt/myfiesta
```

Nothing is ever edited in this checkout. `deploy.sh` refuses to run on one
with changes of its own: a change made by hand here is one no image can be
traced back to, or one the next deploy throws away.

**Check.** `git -C /opt/myfiesta log -1 --oneline` shows the newest commit;
`git -C /opt/myfiesta status --short` prints nothing.

### 4.2 `.env.production`

Every setting and every credential the platform has, in one file beside the
checkout's root, readable by `deploy` alone.

```sh
$ cd /opt/myfiesta
$ cp .env.production.example .env.production && chmod 600 .env.production
$ nano .env.production
```

The file explains each line. On this box, set at least these; the ones marked
*generated* are made here, the ones marked *operator* come from
[LAUNCH.md](LAUNCH.md):

| Setting | Value on this box |
| --- | --- |
| `APP_KEY` | *generated*: `echo "base64:$(openssl rand -base64 32)"` — the same thing `key:generate --show` prints, without needing an image first |
| `APP_URL`, `PUBLIC_URL`, `CONSOLE_URL`, `API_BASE_URL`, `SITE_HOSTS`, `CORS_ALLOWED_ORIGINS` | your host names; the example's are the production ones |
| `TRUSTED_PROXIES` | `172.30.57.1`, the compose network's gateway: aaPanel's nginx reaches the containers through Docker's proxy and arrives from it ([DEPLOYMENT.md](DEPLOYMENT.md#behind-the-load-balancer)) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE` | `postgres`, `5432`, `myfiesta` |
| `DB_USERNAME`, `DB_PASSWORD` | `myfiesta`, and *generated* `openssl rand -hex 32`. Postgres is created with these the first time it starts; changing them in this file afterwards does not change the database's ([13](#13-when-something-is-wrong)) |
| `REDIS_HOST`, `REDIS_PASSWORD` | `redis`, and *generated* `openssl rand -hex 32` |
| `BACKUP_ENCRYPTION_KEY` | *generated*: `openssl rand -base64 32` — 32 random bytes, which is what `backup:key` makes. Into the password manager as well, now |
| `BACKUP_TARGET` and `BACKUP_S3_*` | *operator*: `s3` and a bucket at another provider ([10](#10-backups)) |
| `MAIL_*`, `MAIL_SUPPORT_ADDRESS`, `CONTACT_*` | *operator*. An SMTP provider on port 587; no mail server on this box |
| `STRIPE_*`, `PAYSTACK_*` | *operator*, live keys and the webhook secret |
| `SMS_*` | *operator*, or `SMS_COUNTRIES=` empty until there is a provider |
| `MEDIA_DISK` | `local` works; `s3` is better here ([10](#10-backups)) |
| `SENTRY_*` | *operator*, or empty to report nothing |
| `API_BIND`, `SITE_BIND`, `CONSOLE_BIND` | leave `127.0.0.1` ([2.2](#22-the-firewall)) |
| `LEGACY_*` | only for the move from the old app ([12.2](#122-the-imports-settings)) |

Generated secrets are hex or base64 on purpose: compose reads this file and
treats a `$` in a value as the start of a variable.

`.env.release`, beside it, is one line, `TAG=<the release that is running>`.
`deploy.sh` writes it; never by hand except as OPERATIONS.md says.

**Check.** `stat -c '%a %U' .env.production` prints `600 deploy`.
`grep -cE '^(APP_KEY|DB_PASSWORD|REDIS_PASSWORD|BACKUP_ENCRYPTION_KEY|TRUSTED_PROXIES)=.+' .env.production`
prints 5. The full check, `app:preflight`, runs in the first deploy and names
anything else missing.

### 4.3 The `fiesta` command

OPERATIONS.md writes every command as `fiesta …`: docker compose with both
env files. On this box it also reads the override, from the checkout wherever
you run it. As `deploy`:

```sh
$ cat >> ~/.bashrc <<'EOF'

# myFiesta on this box: docker compose as docs/OPERATIONS.md's `fiesta`, with
# the one-box override, run from the checkout wherever you are.
export COMPOSE_FILES="ops/docker/compose.prod.yml ops/docker/compose.contabo.yml"
fiesta() {
    (cd /opt/myfiesta && docker compose --env-file .env.production --env-file .env.release \
        -f ops/docker/compose.prod.yml -f ops/docker/compose.contabo.yml "$@")
}
EOF
$ . ~/.bashrc
```

`COMPOSE_FILES` is what `ops/backup/restore-drill.sh` reads to find the same
two files; `ops/deploy/*.sh` use both by default.

**Check.** After the first deploy, `fiesta ps` lists the containers.

## 5. The first deploy

```sh
$ bash ops/deploy/deploy.sh
```

With no argument it deploys the newest commit on `origin/main`; give it a
branch, a tag or a commit to deploy that instead. In order, it:

1. takes the checkout's lock, so no other deploy, rollback or import runs
   meanwhile, and logs everything to `.deploy/logs/deploy-<time>.log`;
2. refuses a checkout with changes of its own, fetches, and checks out the
   commit (detached);
3. builds the four images one at a time, tagged with the commit's first 12
   characters, pulling the base images' latest patches — unless that release
   is already built;
4. runs `app:preflight` in the new image against `.env.production`, and stops
   there, replacing nothing, if anything is missing;
5. starts Postgres and Redis if they are not running;
6. lists the migrations the new release would run and, when something has
   been live here before, takes a backup first (`backup:run`);
7. migrates once (`api-migrate`), and stops there, replacing nothing, if that
   fails — putting the checkout back at the running release;
8. writes `.env.release`, keeping the release it replaces in `.deploy/previous`;
9. replaces the containers, waits for every one to be healthy, then for the
   readiness check, the time-zone check and `APP_URL/up` through aaPanel;
10. removes the images of all but the last three releases.

The first build takes a while (15–30 minutes on 4 vCPUs); later ones reuse
what did not change. On the first deploy the readiness check fails for a
minute or two until the worker and the scheduler have each run once: the
script waits for it.

Run it again whenever it stops, for whatever reason. It finishes what was left
and does nothing twice.

**Check.** It ends with `<tag> is live` and exit status 0. Then:

```sh
$ fiesta ps                                   # every service healthy, postgres and redis included
$ fiesta exec api php artisan app:health      # every check OK
$ edition=$(grep -v '^#' ops/docker/tzdata-edition | tr -d '[:space:]')
$ fiesta exec api php artisan app:time-zones --expect="$edition"
$ fiesta exec site node tz-version.mjs "$edition"
$ fiesta exec scheduler php artisan app:health --only=scheduler
$ fiesta exec worker php artisan app:health --only=queue
```

The last two are the heartbeats: the scheduler has run in the last three
minutes, and a worker has taken the heartbeat job in the last ten.
`$APP_URL/up` through aaPanel only answers once [6](#6-aapanel-sites) is done;
the script says so and carries on.

## 6. aaPanel sites

One aaPanel site per host name, each a reverse proxy to one container on
127.0.0.1, each with its own Let's Encrypt certificate. aaPanel keeps the
certificate and its renewal; the rest of each site's nginx configuration is
replaced by the one below.

### 6.1 Every site the same way

For `api.myfiesta.ca`, then `console.myfiesta.ca`, then (see
[6.4](#64-myfiestaca-and-www)) `myfiesta.ca` with `www.myfiesta.ca`:

1. **Website → Add site.** Domain: the host name (both names on one site for
   `myfiesta.ca` and `www`). Database: none. FTP: none. PHP version:
   **Static** — none of these sites runs aaPanel's PHP; every request goes to
   a container. The directory aaPanel makes (`/www/wwwroot/<host>`) holds
   nothing but certificate tokens.
2. **The site → SSL → Let's Encrypt**: tick the names, file verification,
   apply. Then switch on **Force HTTPS**. Leave aaPanel's **HSTS** switch
   **off**: the containers send `Strict-Transport-Security` themselves when
   told the request was https, and a second copy from aaPanel is two headers
   where a browser expects one.
3. **The site → Config.** Keep everything aaPanel wrote down to the
   `#SSL-END` line — the `listen` lines, `server_name`, the `CERT-APPLY-CHECK`
   include, the certificate paths and the http-to-https rule — and replace
   everything below it with the block for that site below. Save.
4. **Check it loaded**, as root: `/www/server/nginx/sbin/nginx -t`. A
   configuration nginx cannot read is one the next restart of nginx fails on,
   taking every site on this box with it, the old app's included.

If `nginx -t` says `duplicate location "/.well-known/acme-challenge/"`,
aaPanel's own `CERT-APPLY-CHECK` include already serves the certificate tokens
on your version: delete that one block from what you pasted and save again.

The file is `/www/server/panel/vhost/nginx/<host>.conf`, and can be edited
there instead; then `/www/server/nginx/sbin/nginx -t && /www/server/nginx/sbin/nginx -s reload`.

Why each part of the block is there:

- `location ^~ /` and not `location /`: aaPanel's configuration elsewhere has
  regular-expression locations — its PHP, its image and script expiry rules,
  `\.well-known` — and nginx lets a regular expression beat a plain prefix.
  `^~` means no regular expression gets a say about any path. Without it a
  request for `/main.js` or the app-link files would be looked for in the
  empty directory and answered 404.
- The one path not proxied is `/.well-known/acme-challenge/`, served from the
  site's directory, where aaPanel's certificate client writes its tokens: a
  longer prefix than `/`, so it wins. Renewals work without switching the
  proxy off.
- `Host`, `X-Forwarded-For` and `X-Forwarded-Proto`: the site answers only for
  the host names in `SITE_HOSTS`, and the API believes who the visitor is and
  that it was https only from `TRUSTED_PROXIES`, which is the network's
  gateway where aaPanel's connections arrive from. `X-Forwarded-Host` is sent
  for completeness and never believed (DEPLOYMENT.md).
- `proxy_cache off`: aaPanel's `proxy.conf` switches a proxy cache on for
  every site, keyed on the address alone and not on who is asking. The
  containers already tell browsers what to cache.
- `proxy_next_upstream off`: there is one container behind each site;
  aaPanel's default of trying "the next one" on an error has nothing to try,
  and a request is never to be sent twice.
- No buffer sizes. The same `proxy.conf` sets them for every site (32k, four
  of 64k, and 128k of those busy), which is plenty: the containers' own nginx
  and Node answer with far less. And nginx refuses a location that sets
  smaller buffers of its own while it inherits that 128k, which must be less
  than all its buffers but one — `nginx -t` fails, and aaPanel will not save
  the site.
- gzip here, because nothing behind it compresses: the API's nginx, the site's
  Node and the console's nginx all answer plainly for this to compress once.
  Brotli only if `/www/server/nginx/sbin/nginx -V 2>&1 | grep -o brotli`
  prints it; gzip is enough.

### 6.2 `api.myfiesta.ca`

The API and the admin (`/admin`), to `api-web` on 8000. It is the one site
that takes uploads — identity documents and posters, up to the 16 MB the API's
nginx takes — and the one with slow answers, an export or a gateway call that
PHP is given 120 seconds for.

```nginx
    #SSL-END
    # ---- from here down replaces what aaPanel wrote ---------------------------

    #ERROR-PAGE-START  Error page configuration, allowed to be commented, deleted or modified
    #ERROR-PAGE-END

    #PHP-INFO-START  PHP reference configuration, allowed to be commented, deleted or modified
    include enable-php-00.conf;
    #PHP-INFO-END

    location ^~ /.well-known/acme-challenge/ {
        root /www/wwwroot/api.myfiesta.ca;
        default_type text/plain;
        try_files $uri =404;
    }

    location ^~ / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;

        client_max_body_size 16m;
        proxy_connect_timeout 5s;
        proxy_send_timeout 130s;
        proxy_read_timeout 130s;

        proxy_cache off;
        proxy_next_upstream off;
    }

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml application/javascript text/javascript application/json application/xml application/manifest+json image/svg+xml;

    access_log  /www/wwwlogs/api.myfiesta.ca.log;
    error_log  /www/wwwlogs/api.myfiesta.ca.error.log;
}
```

### 6.3 `console.myfiesta.ca`

The console, to its nginx on 4310. Static files: nothing slow, no uploads of
its own (the console sends those to the API).

```nginx
    #SSL-END
    # ---- from here down replaces what aaPanel wrote ---------------------------

    #ERROR-PAGE-START  Error page configuration, allowed to be commented, deleted or modified
    #ERROR-PAGE-END

    #PHP-INFO-START  PHP reference configuration, allowed to be commented, deleted or modified
    include enable-php-00.conf;
    #PHP-INFO-END

    location ^~ /.well-known/acme-challenge/ {
        root /www/wwwroot/console.myfiesta.ca;
        default_type text/plain;
        try_files $uri =404;
    }

    location ^~ / {
        proxy_pass http://127.0.0.1:4310;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;

        proxy_connect_timeout 5s;
        proxy_read_timeout 60s;

        proxy_cache off;
        proxy_next_upstream off;
    }

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml application/javascript text/javascript application/json application/xml application/manifest+json image/svg+xml;

    access_log  /www/wwwlogs/console.myfiesta.ca.log;
    error_log  /www/wwwlogs/console.myfiesta.ca.error.log;
}
```

### 6.4 `myfiesta.ca` and `www`

The public site, to its Node on 4000. Both names on one site and both served
as they are, with no redirect from one to the other: Apple and Google fetch
`/.well-known/apple-app-site-association` and `/.well-known/assetlinks.json`
from the exact host the app names and refuse a redirect (DEPLOYMENT.md, "The
phone app's links"). Both go to the container, not the directory — only
`acme-challenge` is served from disk.

**If the old app is myfiesta.ca on this server**, do not make a new site for
it: aaPanel will not put one name on two sites. Write this block now into the
copy [12.5](#125-switching-myfiestaca) prepares, and it replaces the old app's
configuration at the cutover. Until then the site can be looked at under a
name of its own ([12.3](#123-the-order-of-it), "A look at the site").

```nginx
    #SSL-END
    # ---- from here down replaces what aaPanel wrote ---------------------------

    #ERROR-PAGE-START  Error page configuration, allowed to be commented, deleted or modified
    #ERROR-PAGE-END

    #PHP-INFO-START  PHP reference configuration, allowed to be commented, deleted or modified
    include enable-php-00.conf;
    #PHP-INFO-END

    location ^~ /.well-known/acme-challenge/ {
        root /www/wwwroot/myfiesta.ca;
        default_type text/plain;
        try_files $uri =404;
    }

    location ^~ / {
        proxy_pass http://127.0.0.1:4000;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;

        proxy_connect_timeout 5s;
        proxy_read_timeout 60s;

        proxy_cache off;
        proxy_next_upstream off;
    }

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml application/javascript text/javascript application/json application/xml application/manifest+json image/svg+xml;

    access_log  /www/wwwlogs/myfiesta.ca.log;
    error_log  /www/wwwlogs/myfiesta.ca.error.log;
}
```

The `root` in the certificate block is the site's own directory as aaPanel
shows it (**Website → the site → Site directory**). For the old app's site
that is where its PHP lives; the tokens are written there either way.

### 6.5 What not to switch on

For any of these sites:

- **PHP.** Static, always. A PHP version would put aaPanel's php-fpm in front
  of a path ending `.php` — the platform's own PHP is inside a container.
- **aaPanel's reverse-proxy tab, its caching, and its "Node project" site
  type.** The configuration above is the proxy. aaPanel's own proxy entries
  would add a second `location /` and switch caching back on; the Node project
  type answers every `/.well-known/` path from a directory, the app-link files
  included.
- **HSTS in aaPanel.** The containers send it (6.1).
- **Security headers, hotlink protection, access restriction (a password),
  traffic limits, URL rewrites, redirects** other than Force HTTPS. The
  containers send their own security headers, and a second
  `X-Frame-Options` from aaPanel would stop the embedded checkout working on
  organizers' sites. Hotlink protection breaks posters in emails and embeds.
  A password or a limit on the API stops Stripe's and Paystack's webhooks, and
  an unanswered webhook is a paid order nobody is told about. The one
  exception is the staff-only rule of the move from the old app
  ([12.3](#123-the-order-of-it), step 0), which lets the webhooks through
  and is gone at the switch.
- **aaPanel's WAF**, if installed, in front of the API, without first sending
  a test-mode payment through it: a webhook it blocks looks like an attack
  and is a paid order that never becomes paid.

### 6.6 Checks

From your computer, for each host:

```sh
curl -sI https://api.myfiesta.ca/up                    # 200
curl -s  https://api.myfiesta.ca/api/health/ready      # {"status":"ok",…}
curl -sI https://console.myfiesta.ca/                  # 200, one strict-transport-security line
curl -sI http://console.myfiesta.ca/                   # 301 to https
curl -sI -H 'Accept-Encoding: gzip' https://console.myfiesta.ca/ | grep -i content-encoding   # gzip
curl -sI https://api.myfiesta.ca/up | grep -ci strict-transport-security                     # 1, not 2
```

For the public site once it answers on its names: `curl -sI
https://myfiesta.ca/robots.txt` is 200, and `curl -sI
https://myfiesta.ca/.well-known/assetlinks.json` is 200 with no `location:`.

That the API sees visitors as themselves and not as aaPanel: open
`https://api.myfiesta.ca/up` in a browser, then on the server
`fiesta logs --tail=5 api-web` shows your own address, not `172.30.57.1`. If
it shows the gateway, `TRUSTED_PROXIES` is wrong
([13](#13-when-something-is-wrong)).

That certificates will renew:

```sh
# d=/www/wwwroot/api.myfiesta.ca/.well-known/acme-challenge; mkdir -p $d && echo probe > $d/probe
# curl -sL http://api.myfiesta.ca/.well-known/acme-challenge/probe; rm $d/probe     # prints probe
```

and **Cron** in aaPanel lists its certificate renewal task.

## 7. The first administrator and a test email

`staff:grant` gives a role to an account that exists and has proved its
address, so the account comes first. Register at
`https://console.myfiesta.ca/register`. The confirmation email is sent by the
API straight away, not queued, so receiving it proves the API reaches the mail
provider; its link opens on `api.myfiesta.ca`. Then:

```sh
$ fiesta exec api php artisan staff:grant <you@yourdomain> admin
```

Staff sign in at `https://api.myfiesta.ca/admin` with that password and a code
sent by email.

**Use an address the old app does not have.** The import makes an account for
every one of the old app's accounts, and one whose address is already taken
here does not come across — with every event it ran waiting behind it. A
staff-only address (`ops@…`) avoids that; or grant the role after the import,
to the imported account.

The queued path, through Redis and the worker — the one tickets and reminders
take:

```sh
$ fiesta exec api php artisan tinker --execute='Illuminate\Support\Facades\Mail::to("<you@yourdomain>")->queue((new Illuminate\Mail\Mailable)->subject("myFiesta queue test")->html("<p>Queued mail from the server works.</p>"));'
```

**Check.** Both emails arrive, not in spam (SPF and DKIM for the sending
domain, DEPLOYMENT.md). `fiesta exec api php artisan tinker
--execute='echo DB::table("failed_jobs")->count();'` prints 0.

## 8. Routine deploys

```sh
$ bash ops/deploy/deploy.sh                # origin/main
$ bash ops/deploy/deploy.sh <ref>          # a branch, a tag or a commit
```

The same script as [5](#5-the-first-deploy), run the same way every time.
Before it, OPERATIONS.md's checklist still applies — CI green for the commit,
nothing on sale that cannot wait five minutes — and the script does the rest
of it: preflight, the backup before migrations, the migrations before
anything is replaced, `.env.release` before the containers, the checks after.

It is safe to run again, with the same ref or another: a release already built
is not rebuilt (`REBUILD=1` to insist), migrations already run are not run
again, and containers already running the release are left alone. If it stops
before the containers are replaced, nothing changed and the checkout is put
back at the running release. If it stops after, the new release is live; run
it again, or roll back.

| Setting | Does |
| --- | --- |
| `REBUILD=1` | builds even when that release's images exist |
| `SKIP_BACKUP=1` | migrates without the backup first; only when the backup itself is what is broken and a backup less than a day old exists |
| `KEEP_RELEASES=<n>` | how many releases' images to keep (3) |
| `COMPOSE_FILES` | the compose files; on a host whose database is elsewhere, `ops/docker/compose.prod.yml` alone |

Exit status 0 means live and every check passed; 1 means it stopped (it says
where, and whether anything was replaced); 75 means another deploy, rollback
or import holds the checkout and nothing was done.

**Check.** The last line names the new release and, from the second deploy
on, the one `rollback.sh` would go back to. `cat .env.release` and
`tail -3 .deploy/history` agree. Sentry, if set up, shows the new release.

## 9. Rolling back

```sh
$ bash ops/deploy/rollback.sh              # to the release deploy.sh last replaced
$ bash ops/deploy/rollback.sh <tag>        # to any release whose images are still here
```

The code, not the schema: migrations only ever add, so the older release runs
against today's database (OPERATIONS.md, "Rolling back"). Nothing is built;
the older release's images were kept. It writes `.env.release`, moves the
checkout back to that commit, replaces the containers and runs the same
checks as a deploy.

Running it again finds that release already running and only makes sure it
is up: it does not flip forward again. Forward again is a deploy
(`deploy.sh <ref>`), which finds the newer images still here and does not
rebuild them.

`docker image ls myfiesta/api` lists the releases on the host. One older than
the last three is rebuilt with `deploy.sh <its commit>`.

**Check.** It ends `<tag> is live`; `cat .env.release` names it;
`fiesta ps` shows every container healthy.

## 10. Backups

OPERATIONS.md, "Backups", is the whole of it. On this box:

- **The database** is backed up every night at 06:30 UTC by `backup:run` in
  the scheduler, encrypted with `BACKUP_ENCRYPTION_KEY`. Set
  `BACKUP_TARGET=s3` and a bucket **at another provider** — Backblaze B2,
  Cloudflare R2, AWS — not Contabo's object storage in the same data centre:
  the backup is for the day this server, or this account, is gone. The
  database is a container here, so there is no provider point-in-time recovery
  behind it; the nightly backup is the only one, and a day's sales is the most
  it can lose.
- **Pictures** with `MEDIA_DISK=local` are on the `api-media` volume and in no
  backup. Set `MEDIA_DISK=s3` with a bucket that has versioning, or accept
  that a lost server loses every poster.
- **A Contabo snapshot** (customer panel) is a quick way back for the whole
  machine before something risky — the cutover, a distribution upgrade — and
  not a backup: it lives with the server.

**Check.** The first night after the first deploy, or straight away:

```sh
$ fiesta exec scheduler php artisan backup:run
$ fiesta exec scheduler php artisan backup:list
$ fiesta exec scheduler php artisan app:health --only=backup
$ bash ops/backup/restore-drill.sh          # reads COMPOSE_FILES from ~/.bashrc (4.3)
```

The drill restores the newest backup into a new database beside the live one,
checks every table against the backup's manifest, prints how long it took and
drops it. Write the time into OPERATIONS.md, "RPO and RTO". Monthly after that.

## 11. Watching it

**Uptime.** The monitors in OPERATIONS.md, "What to watch", on these host
names: `/api/health/ready` every minute (it pages), `robots.txt` on the site,
the console, a rendered page, and certificate expiry on all of them. They
watch from outside, which is the only place that sees what a visitor sees.

**Logs.**

| What | Where |
| --- | --- |
| each container | `fiesta logs --tail=200 <service>` (rotated: 5 × 20 MB each) |
| aaPanel's nginx, per site | `/www/wwwlogs/<host>.log` and `<host>.error.log` (aaPanel's log-cutting task rotates them) |
| deploys, rollbacks, imports | `/opt/myfiesta/.deploy/logs/`, one file per run; `.deploy/history`, one line per release |
| legacy:reconcile's reports | `storage/app/reconciliation/` on the `api-storage` volume ([12.4](#124-reading-what-a-run-said)) |
| Docker, security updates | `journalctl -u docker`, `/var/log/unattended-upgrades/` |
| the old app's MySQL | `/www/server/data/*.err` |

**Disk.** `deploy.sh` keeps the last three releases' images and removes the
rest. The build cache and old logs are cleared weekly from `deploy`'s crontab
(`crontab -e`):

```cron
# Sundays: Docker's build cache and dangling images older than a week, and
# deploy and import logs older than 90 days.
30 4 * * 0  docker builder prune -af --filter until=168h >/dev/null 2>&1; docker image prune -f >/dev/null 2>&1; find /opt/myfiesta/.deploy/logs -name '*.log' -mtime +90 -delete
```

And the journal capped, as root: `SystemMaxUse=500M` in
`/etc/systemd/journald.conf`, then `systemctl restart systemd-journald`.
`app:health`'s `storage` check fails when a disk is full; before that, look at
`df -h /` weekly or use aaPanel's disk alert if yours offers one.

**Certificates.** aaPanel renews Let's Encrypt certificates from its own cron
task about a month before they expire. The uptime monitor's expiry alert (under
14 days) is the check that it did. By hand:

```sh
for h in myfiesta.ca www.myfiesta.ca api.myfiesta.ca console.myfiesta.ca; do
  printf '%s ' $h; echo | openssl s_client -connect $h:443 -servername $h 2>/dev/null | openssl x509 -noout -enddate
done
```

## 12. Moving off the old app on this server

[CUTOVER.md](CUTOVER.md) is the move: what the two commands do, the order, and
what to do with every line they report. This part is how it is done on this
box, with the old app's MySQL read live rather than from a dump, again and
again during a parallel run, then once more after the freeze.

Every run is safe to repeat. A run brings only the rows the old database has
that this one does not, tries again the ones that failed, and never changes a
row it already brought; the old app's later changes to those are listed for a
person instead (CUTOVER.md, "Rows that changed after they came across"). Two
runs never overlap: `legacy-sync.sh` holds the checkout's lock and
`legacy:import` holds one in the database.

### 12.1 The old database, read-only

The import reads the old app's MySQL through an account that can do nothing
but read that one database, from the `legacy` container only, and only while
it runs. MySQL's port stays shut to the internet.

**Where MySQL listens.** As root:

```sh
# ss -ltnp 'sport = :3306'
```

`0.0.0.0:3306`, `*:3306` or `[::]:3306` is every address, which aaPanel's
MySQL does unless told otherwise, with the firewall keeping it shut
([2.2](#22-the-firewall)). Nothing to change. `127.0.0.1:3306` means the
containers cannot reach it: in **App Store → MySQL → Setting → Configuration**
set `bind-address = 0.0.0.0` (and remove `skip-networking` if it is there),
save, restart MySQL at a quiet hour — the old app is down for those seconds —
and check `ss` again. The firewall is what keeps it private, so check that as
well: from your computer, `nc -vz -w 3 <server> 3306` must time out or be
refused.

**The account.** aaPanel's **Databases** page makes a user for each database
with every privilege on it, which is not this. So by hand, with MySQL's root
password (**Databases → Root password**):

```sh
# mysql -uroot -p
```

```sql
CREATE USER 'myfiesta_ro'@'172.30.57.%' IDENTIFIED BY '<openssl rand -hex 24>';
GRANT SELECT ON `sql_myfiesta_ca`.* TO 'myfiesta_ro'@'172.30.57.%';
SHOW GRANTS FOR 'myfiesta_ro'@'172.30.57.%';
```

`172.30.57.%` is the compose network (`compose.contabo.yml` pins it): the only
addresses the account can come from. `sql_myfiesta_ca` is the old database's
name as aaPanel's Databases page shows it. Nothing but `SELECT`: no
`LOCK TABLES`, no `PROCESS`, nothing that writes. The import never needs more.

**Through the firewall, from the containers only.** The container reaches the
host as `host.docker.internal` (`extra_hosts` in `compose.contabo.yml`) and
arrives from 172.30.57.x, which ufw drops like anything else unless told:

```sh
# ufw allow from 172.30.57.0/24 to any port 3306 proto tcp comment 'myfiesta legacy import'
```

(Or in aaPanel, **Security → Firewall**, port 3306, source 172.30.57.0/24.)
That range is private; nothing from the internet arrives from it. Never set a
database's permission to "Everyone" in aaPanel's Databases page: that opens
3306 to the world.

**Check.** The grants show one line with `USAGE` and one with `SELECT ON
sql_myfiesta_ca.*`, nothing else. `ufw status` shows the one 3306 rule, from
172.30.57.0/24. From your computer 3306 is still closed. That the container
can read it is the dry run in [12.3](#123-the-order-of-it).

**The old app on another server.** Then nothing about its MySQL changes: the
import reaches it through an SSH tunnel this server opens, with a key of its
own. `deploy` has no key but the GitHub one ([4.1](#41-the-checkout)), which
is offered to GitHub alone. As root:

```sh
# sudo -u deploy ssh-keygen -t ed25519 -N '' -C 'myfiesta-1 legacy tunnel' -f /home/deploy/.ssh/legacy_tunnel
# cat /home/deploy/.ssh/legacy_tunnel.pub
```

On the old server, the same account for `'myfiesta_ro'@'127.0.0.1'`, and that
public key in `~/.ssh/authorized_keys` of an account there, restricted to that
one forwarding:

```
restrict,port-forwarding,permitopen="127.0.0.1:3306" ssh-ed25519 AAAA… myfiesta-1 legacy tunnel
```

Here, a tunnel that listens on Docker's bridge address (`ip -4 -brief addr show
docker0`, usually 172.17.0.1) so only containers can use it:

```sh
# cat > /etc/systemd/system/myfiesta-legacy-tunnel.service <<'EOF'
[Unit]
Description=myFiesta: the old app's MySQL, through SSH, for the import
After=network-online.target docker.service
Wants=network-online.target

[Service]
User=deploy
ExecStart=/usr/bin/ssh -N -i /home/deploy/.ssh/legacy_tunnel -o IdentitiesOnly=yes -o BatchMode=yes -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -L 172.17.0.1:3307:127.0.0.1:3306 <account>@<old server>
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
# systemctl daemon-reload && systemctl enable --now myfiesta-legacy-tunnel
# ufw allow from 172.30.57.0/24 to any port 3307 proto tcp comment 'myfiesta legacy import'
```

with `LEGACY_DB_PORT=3307`. Accept the old server's host key once first
(`sudo -u deploy ssh -i /home/deploy/.ssh/legacy_tunnel -o IdentitiesOnly=yes
<account>@<old server> true`): `BatchMode` in the service answers no to
every question, that one included, rather than wait for an answer nobody is
there to give. Check: `systemctl status myfiesta-legacy-tunnel` is active and
`ss -ltn | grep 3307` shows `172.17.0.1:3307`; `journalctl -u
myfiesta-legacy-tunnel` has no `Permission denied (publickey)`.

**Or no connection at all.** For a rehearsal, or where the live MySQL's
configuration is not to be touched: a dump loaded into a MySQL container on
this server's compose network, and `LEGACY_DB_HOST` pointed at it. The dump
never leaves this machine. It is a copy as of the moment it was taken, so
every later run needs a fresh one; that suits a rehearsal, not a parallel run.

### 12.2 The import's settings

In `.env.production`:

```
LEGACY_DB_HOST=host.docker.internal
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=sql_myfiesta_ca
LEGACY_DB_USERNAME=myfiesta_ro
LEGACY_DB_PASSWORD=<the password above>
LEGACY_STRIPE_KEY=<a restricted, read-only key on the old app's Stripe account — CUTOVER.md, "Before the day">
```

The import runs in the `legacy` service: the running release's own image with
a MySQL driver added (`api.Dockerfile`, the `legacy` stage), which the image
that serves the internet does not carry. While `LEGACY_DB_HOST` is set,
`deploy.sh` builds it with every release; the first `legacy-sync.sh` builds
it if it is missing.

**Check.** The dry run below connects and reads every table.

### 12.3 The order of it

Each step below is a command and the check that it worked.

**0. Staff only, until the switch.** From the first import on, this platform
holds the old app's events, approved and on sale, and its organizers'
accounts, which sign in with their old passwords; and anybody can sign up.
Open to the internet during the parallel run, it would take a card from
anybody with the phone app, or anything else that calls the API, for seats
the old app is still selling — the import never counts one against the
other — and an organizer who signed up here with the address of one of the
old app's accounts would stop that account, and every event it runs, from
ever coming across. So until the switch the API and the console answer staff
alone, by address, in aaPanel's nginx. As root, at the top of
`location ^~ / {` in `api.myfiesta.ca`'s configuration ([6.2](#62-apimyfiestaca)):

```nginx
        # Staff only until the switch (the runbook, 12.3 step 0); out at 12.5.
        allow <each staff address>;
        allow <this server's addresses>;
        allow 172.30.57.0/24;
        deny all;

        location ^~ /webhooks/ {
            allow all;
            proxy_pass http://127.0.0.1:8000;
        }
```

and the same `allow` and `deny` lines, without the webhooks, in
`console.myfiesta.ca`'s ([6.3](#63-consolemyfiestaca)). Then
`/www/server/nginx/sbin/nginx -t && /www/server/nginx/sbin/nginx -s reload`.

- One `allow` line per address or range. A staff address: yours is the
  first word of `echo $SSH_CLIENT` in your SSH session, and an IPv6 one as
  well if you have one. A home connection's address changes now and then,
  and is refused until the new one is added.
- This server's own addresses (`hostname -I`): `deploy.sh`'s last check asks
  for `APP_URL/up` through aaPanel, from here.
- 172.30.57.0/24, the containers: the site's server rendering, under
  `next.myfiesta.ca`, asks the API by its public name.
- `/webhooks/` for everybody. Stripe and Paystack are not staff, a gateway
  whose webhooks are refused retries them and then switches the endpoint
  off, and a webhook proves itself with its signature, not its address. Its
  `location` takes every proxy setting from the one around it except
  `proxy_pass`, which nginx wants said again.
- The certificate block is outside `location ^~ /`, so renewals carry on.

The outside monitors of [11](#11-watching-it) are refused too until the
switch: add their addresses, or set them up at the switch. So is the phone
app, which is the point: a version of it in the stores before the switch
would sell the same seats.

Check: from your computer, `https://console.myfiesta.ca/` opens. From a phone
off Wi-Fi, it and `https://api.myfiesta.ca/up` are 403, and
`https://api.myfiesta.ca/webhooks/payments/stripe` is 405 — the API's own
answer to a webhook asked for by a browser — not 403. On the server, `curl
-sI https://api.myfiesta.ca/up` is 200.

**1. A dry run.**

```sh
$ bash ops/deploy/legacy-sync.sh --dry-run --posters
```

Reads everything, writes nothing, here or there. Check: exit 0, and a table
with one line per old table: `read`, and `would come`. The `read` column
matches the old database's own counts:

```sh
# mysql -uroot -p sql_myfiesta_ca -e "SELECT 'user_accounts', COUNT(*) FROM user_accounts UNION ALL SELECT 'events', COUNT(*) FROM events UNION ALL SELECT 'event_tickets', COUNT(*) FROM event_tickets UNION ALL SELECT 'tickets_sales', COUNT(*) FROM tickets_sales UNION ALL SELECT 'ticket_issued', COUNT(*) FROM ticket_issued UNION ALL SELECT 'settlements', COUNT(*) FROM settlements"
```

"No connection to the legacy database" with the reason under it: see
[13](#13-when-something-is-wrong).

**2. The first import.**

```sh
$ bash ops/deploy/legacy-sync.sh --no-reconcile
$ bash ops/deploy/legacy-sync.sh --posters --no-reconcile     # about 300 MB; stop and start it as you like
```

Check: both exit 0, or the first ends with the rows that did not come across
and why. Fix the cause and run it again. A row that should not exist — a
duplicate account, a test sale — is left behind with a reason, as CUTOVER.md
says:

```sh
$ fiesta --profile legacy run --rm legacy php artisan legacy:import --leave-behind=user_accounts:32 --because="duplicate of account 26"
```

Checkouts from the last 48 hours are left for a later run, as `left for later`:
they may still be paid in the old app.

**3. A first look at the money.**

```sh
$ fiesta --profile legacy run --rm legacy php artisan legacy:reconcile --limit=50
```

If most of these are `amount_mismatch`, stop: CUTOVER.md, "Amount or
currency differs". Otherwise go on.

**4. The parallel run.** For as many days as it takes to be sure, the old app
keeps selling and this server keeps up with it. From `deploy`'s crontab:

```cron
# The move from the old app: the new rows every hour, and once a day the
# posters and the reconcile report too. A run that finds another running
# does nothing (exit 75); every run's log is in /opt/myfiesta/.deploy/logs.
15 * * * *  bash /opt/myfiesta/ops/deploy/legacy-sync.sh --no-reconcile >/dev/null 2>&1
45 7 * * *  bash /opt/myfiesta/ops/deploy/legacy-sync.sh --posters >/dev/null 2>&1
```

Every morning, read the newest log (`ls -t .deploy/logs/legacy-sync-* | head
-1`): nothing failed, and each row the old app changed since it came across is
settled as CUTOVER.md, "Rows that changed after they came across", says. Keep
your own list of the ones settled: they are listed on every run.

Until the switch, nobody but staff can use this platform (step 0). Every
organizer and buyer is still on the old app, and whatever is done here — an
event created, a ticket bought — is not in the old app and never will be. The platform
sends nothing about imported orders on its own: reminders are ones organizers
set up here, and the import brings none.

*A look at the site.* To see the public site before the switch, give it a name
of its own — `next.myfiesta.ca`: a DNS record, an aaPanel site as in
[6.4](#64-myfiestaca-and-www) with that name, the name added to `SITE_HOSTS`
and `CORS_ALLOWED_ORIGINS`, and `bash ops/deploy/deploy.sh` to recreate the
containers with them. Put it behind aaPanel's **Access restriction**
(a password) — it is a shop selling the old app's events with live keys — and
take it away after the switch.

**5. Freeze the old app.** At the hour announced. Three things, so nothing
writes to its database again:

- *The site*: its configuration in aaPanel replaced by a maintenance page that
  answers 503, which tells people and Stripe's webhook retries to come back:

  ```sh
  # install -d /www/wwwroot/myfiesta-maintenance
  # echo '<!doctype html><title>myFiesta</title><p>myFiesta is moving to its new home. Back within the hour.</p>' > /www/wwwroot/myfiesta-maintenance/maintenance.html
  ```

  and in **Website → myfiesta.ca → Config**, below `#SSL-END` (after
  [12.5](#125-switching-myfiestaca) has saved the old configuration):

  ```nginx
      location ^~ /.well-known/acme-challenge/ { root /www/wwwroot/myfiesta.ca; try_files $uri =404; }
      location = /maintenance.html { root /www/wwwroot/myfiesta-maintenance; internal; }
      location ^~ / { return 503; }
      error_page 503 /maintenance.html;
      add_header Retry-After 3600 always;
  }
  ```

  (**Website → Stop** does the same with aaPanel's own page, less politely.)
- *Its scheduled jobs*: **Cron**, every task that runs the old app's PHP,
  paused.
- *Its PHP*: **App Store → PHP x.y → Stop**, if no other site uses that version.

Check: `curl -sI https://myfiesta.ca/` is 503. Then that nothing writes:

```sh
# mysql -uroot -p sql_myfiesta_ca -e "CHECKSUM TABLE user_accounts, events, event_tickets, tickets_sales, ticket_issued, settlements"
```

Ten minutes later, the same six numbers. A number that moved is something
still writing: find it before going on.

**6. The last run.**

```sh
$ crontab -e                      # the two legacy-sync lines out
$ bash ops/deploy/legacy-sync.sh --frozen --posters
```

`--frozen` brings the checkouts of the last 48 hours as they stand: a frozen
app will never learn whether they were paid, and `legacy:reconcile` is what
finds a payment that landed after all. Check: exit 0; `left for later` is 0;
no row is listed as failed; every changed row is settled.

**7. The money.** Read the report the run wrote (12.4) and deal with every
line that is not `matched`, as CUTOVER.md says. Then record what Stripe
already refunded:

```sh
$ fiesta --profile legacy run --rm legacy php artisan legacy:reconcile --apply
```

Quiet by design: organizers are not emailed about these refunds and their
integrations are not told; each is audited as `refund.made_elsewhere` and
`refund.reconciled` (CUTOVER.md, "Refunded in Stripe, not here"). Do not add
Laravel's `--quiet` to it: that only hides the summary from you. Check: a new
`…-apply.csv`, and its `refunded_in_stripe_only` lines say in `action` what
was recorded.

**8. Switch myfiesta.ca** ([12.5](#125-switching-myfiestaca)).

**9. Afterwards** ([12.7](#127-afterwards)).

### 12.4 Reading what a run said

`legacy:import` ends with a table, one line per old table:

| Column | |
| --- | --- |
| `read` | rows in the old table |
| `already here` | came across on an earlier run; passed over, never changed |
| `changed since` | of those, the ones the old app has changed since |
| `new` (`would come` in a dry run) | brought on this run, first time tried |
| `tried again` (`would retry`) | failed on an earlier run, brought on this one |
| `failed` | tried on this run, new or again, and did not come across; listed at the end with the reason |
| `waiting` | passed over for want of a row they belong to, which comes first |
| `left for later` | checkouts that may still be paid in the old app |
| `gone` | came across before and are no longer in the old database; kept here |

Each row read is in exactly one of `already here`, `new`, `tried again`,
`failed`, `waiting` and `left for later`, so those add up to `read`. A dry run
has no `failed`: only writing a row tells whether it fails.

Then, when there are any, the ids of the rows changed or deleted in the old app,
and a table of the orders among them: this database's status, the old app's
now, and what to do. Ids, references and statuses only; never a name, an
address or a ticket code, because this output ends up in a log.
`legacy-sync.sh` ends with a line per step and its exit status.

The reconcile report is on the `api-storage` volume. To read it, copy it out:

```sh
$ fiesta exec api ls -t storage/app/reconciliation | head -3
$ fiesta cp api:/var/www/api/storage/app/reconciliation/<file> ~/
```

It holds no names, addresses or codes, but it is a list of orders and amounts:
keep it with the cutover record, not in a chat.

### 12.5 Switching myfiesta.ca

**When the old app is myfiesta.ca on this server**, the switch is aaPanel's
site for it changing from the old PHP to the proxy — the same site, the same
certificate, no DNS to wait for — and going back is putting its old
configuration back. Both are prepared before the day, as root:

```sh
# install -d -m 700 /root/cutover
# cp -a /www/server/panel/vhost/nginx/myfiesta.ca.conf /root/cutover/myfiesta.ca.conf.old   # the way back
# cp -a /www/server/panel/vhost/nginx/myfiesta.ca.conf /root/cutover/myfiesta.ca.conf.new   # then edit this copy:
# nano /root/cutover/myfiesta.ca.conf.new       # everything below #SSL-END → the block in 6.4
```

Taken before the freeze changes the site's configuration (12.3, step 5), so
`.old` is the old app as it serves today.

The old configuration's certificate must already cover both `myfiesta.ca` and
`www.myfiesta.ca` (`openssl x509 -noout -ext subjectAltName -in
/www/server/panel/vhost/cert/myfiesta.ca/fullchain.pem`); if not, add `www`
to the site and renew it before the day.

The switch:

```sh
# cp /root/cutover/myfiesta.ca.conf.new /www/server/panel/vhost/nginx/myfiesta.ca.conf \
    && /www/server/nginx/sbin/nginx -t && /www/server/nginx/sbin/nginx -s reload
```

If `nginx -t` fails, nothing was reloaded: put the old one back
(`cp /root/cutover/myfiesta.ca.conf.old /www/server/panel/vhost/nginx/myfiesta.ca.conf`)
and read what it said.

**When the old app is on another server**, it is DNS: lower the TTL of
`myfiesta.ca` and `www` to 300 a day before; on the day, point them here.
Their certificate here needs them to point here first for file verification,
or aaPanel's DNS verification beforehand; with neither, the site answers with
a certificate error until it is issued.

**Either way**, the API and the console open to everybody at the same moment:
the staff-only lines of [12.3](#123-the-order-of-it), step 0, out of
`api.myfiesta.ca`'s and `console.myfiesta.ca`'s configuration, then
`/www/server/nginx/sbin/nginx -t && /www/server/nginx/sbin/nginx -s reload`.

**Check.** From a phone off Wi-Fi, `https://console.myfiesta.ca/` opens.
`curl -sI https://myfiesta.ca/ | grep -i content-security-policy` shows the
new site's policy, with a `nonce-`. `curl -s
https://myfiesta.ca/.well-known/assetlinks.json` is the new site's JSON, and
`curl -sI https://myfiesta.ca/.well-known/apple-app-site-association` is 200
with no `location:`. Buy a ticket to a real night with a real card and refund
it. Take `next.myfiesta.ca` out of `SITE_HOSTS` if it was used.

Leave the old app's webhook endpoint in Stripe switched on, failing as it is,
until [12.7](#127-afterwards). Stripe goes on retrying to it, for up to three
days, whatever it could not deliver since the freeze — a payment on a checkout
the old app opened before it — and that is what going back (12.6) relies on.
An endpoint switched off stops those retries for good.

### 12.6 Going back to the old app

If the switch has to be undone:

```sh
# cp /root/cutover/myfiesta.ca.conf.old /www/server/panel/vhost/nginx/myfiesta.ca.conf \
    && /www/server/nginx/sbin/nginx -t && /www/server/nginx/sbin/nginx -s reload
```

then the old app's PHP started and its cron tasks resumed (or the DNS pointed
back). The old database was never written to by any of this, so the old app
is exactly as it was at the freeze.

Then, in the same minutes:

- The staff-only lines back in `api.myfiesta.ca`'s and
  `console.myfiesta.ca`'s configuration ([12.3](#123-the-order-of-it),
  step 0), `nginx -t`, reload. The imported events are still on sale here,
  and the old app is selling them again.
- In Stripe, the old app's webhook endpoint, still on (12.5), gets Stripe's
  retries of what it missed since the freeze, and the old app learns of the
  payments made meanwhile on checkouts it had opened. Check it in Stripe
  (**Developers → Webhooks →** the endpoint): deliveries succeeding again. If
  it was switched off, or the freeze was more than three days ago, the
  retries have stopped: switch it on, and resend to it from **Developers →
  Events** every event since the freeze of the kinds the endpoint listens
  for (its own page lists them). Otherwise a payment made meanwhile is one
  the old app never hears of: a buyer charged, and no tickets.

What this platform sold in between is not in the old app, and nothing carries
it back. Before telling anybody it is over, list those orders in the admin
(Orders, placed since the switch) and settle each with its organizer. The next
attempt starts again at step 4 of 12.3; the rows already imported stay, and
the runs pick up from there.

### 12.7 Afterwards

Once CUTOVER.md's sign-off is done:

- `LEGACY_*` out of `.env.production`, then `bash ops/deploy/deploy.sh` so no
  container keeps them, and no more import images are built.
- The MySQL account and its way in gone: `DROP USER
  'myfiesta_ro'@'172.30.57.%';`, `ufw delete allow from 172.30.57.0/24 to any
  port 3306 proto tcp`, and the tunnel service disabled if there was one.
- The restricted Stripe key deleted in Stripe (CUTOVER.md, step 9).
- The old app's webhook endpoint switched off in Stripe, once going back
  (12.6) is no longer on the table: it points at pages that are not there
  any more, and Stripe warns about it by email until it is off.
- `docker image rm $(docker image ls -q myfiesta/api-legacy)`.
- The reconcile reports and the last import's log copied into the cutover
  record.
- The old app's files and database kept, stopped, for as long as agreed. If
  its database is ever dumped, the dump stays on this server.

## 13. When something is wrong

- **aaPanel answers 502 Bad Gateway.** The container behind that site is not
  running, or the port in its configuration is not the one it publishes.
  `fiesta ps`, then `ss -ltn | grep -E ':(8000|4000|4310) '`.
- **Everybody is rate limited together**, or sign-ups are refused for people
  who have not signed up: `TRUSTED_PROXIES` is not the address aaPanel's
  connections arrive from. `docker network inspect myfiesta_default -f
  '{{(index .IPAM.Config 0).Gateway}}'` is the one to name; then `bash
  ops/deploy/deploy.sh` to recreate the containers with it.
- **A certificate did not renew.** `curl` the probe of [6.6](#66-checks); if it
  is not served, the certificate block is missing from the site's
  configuration or its `root` is not the site's directory. aaPanel's Cron page
  has the renewal task's log.
- **The import says "No connection to the legacy database"**, and under it:
  - *Access denied for user 'myfiesta_ro'@'172.30.57.x'*: the password, or the
    account's host is not the compose network (a changed `NETWORK_SUBNET`).
  - *Connection timed out*: the ufw rule for 3306 from 172.30.57.0/24 is
    missing.
  - *Connection refused*: MySQL listens on 127.0.0.1 only ([12.1](#121-the-old-database-read-only)).
  - *authentication method unknown* or *requires secure connection*: MySQL 8's
    default password plugin over a plain connection. On MySQL 5.7 or 8.0,
    `ALTER USER 'myfiesta_ro'@'172.30.57.%' IDENTIFIED WITH mysql_native_password BY '<password>';`.
- **The first `up` says "Pool overlaps with other one on this address
  space".** Something on this host already uses 172.30.57.0/24. Choose a free
  /24, set `NETWORK_SUBNET` and `NETWORK_GATEWAY` in `.env.production`, and
  change the three things written against it: `TRUSTED_PROXIES`, the MySQL
  account's host and the firewall rule — and, until the switch, the
  staff-only lines ([12.3](#123-the-order-of-it), step 0).
- **A build is killed** (exit 137, or "signal: killed"): out of memory. Check
  the swap is on ([1.1](#11-size)); nothing else should be building at the
  same time.
- **The API cannot log in to Postgres after `DB_PASSWORD` was changed.**
  Postgres keeps the password it was created with. Put the old one back, or
  change it in the database too: `fiesta exec postgres psql -U <DB_USERNAME>
  -d <DB_DATABASE> -c "\password <DB_USERNAME>"`, then `bash
  ops/deploy/deploy.sh`.
- **"a deploy, rollback or legacy sync is already running on this
  checkout".** One is. `ps aux | grep ops/deploy` says which; its log is the
  newest in `.deploy/logs`. The lock goes with the process, so there is never a
  stale one to delete.
- **Everything else**: DEPLOYMENT.md, "When something is wrong", and
  OPERATIONS.md.
