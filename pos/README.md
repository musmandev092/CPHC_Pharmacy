**CPHC Pharmacy POS — Plain-PHP**  
Single-machine pharmacy point-of-sale. Plain PHP 8.4 + Postgres 17, served  
   
 by Caddy inside one Docker container. No Laravel, no Livewire, no Tailwind,  
   
 no Vite, no npm. Zero composer dependencies.  
**Stack**  
- **App container**: Alpine + PHP-FPM 8.4 + Caddy + cups-client. ~80 MB.  
- **DB container**: postgres:17-alpine. Internal network only.  
- **Schema + triggers**: shared with the existing Laravel project at  
 ../db/schema/ and ../db/triggers/ — loaded automatically on first start.  
- **Printer**: CUPS on the host, with the zj-58 driver compiled and  
   
 installed by you (see ../printer/scripts/). The container talks to it  
   
 through the bind-mounted /var/run/cups/cups.sock.  
**Install on a fresh Debian + GNOME machine**  
We ship **source code, not pre-built images.** Two reasons: updates are a  
   
 git pull away (no 170 MB transfer per change), and the first-time build  
   
 takes ~2 minutes once. The whole install is:  
# 1. One-time apt install (the only host packages this app needs):  
 sudo apt update  
 sudo apt install -y docker.io docker-compose-plugin git libnss3-tools  
 sudo usermod -aG docker $USER && newgrp docker  
   
 # 2. Get the code:  
 git clone <repo-url> ~/CPHC_Pharmacy  
 cd ~/CPHC_Pharmacy/pos  
   
 # 3. Run the installer:  
 sudo bash install.sh  
   
install.sh is idempotent and does everything:  
1. Generates per-installation secrets (db_password, audit_key, session_secret).  
2. Adds 127.0.0.1 pharmacy.local to /etc/hosts.  
3. Builds the Docker image and brings the stack up.  
4. Trusts Caddy's auto-generated root CA system-wide **and** in Firefox /  
   
 Brave / Chromium NSS DBs so the browser shows a green padlock — no  
   
 "Not Secure" warning.  
5. Smoke-tests https://pharmacy.local/healthz.  
When it finishes, open [**https://pharmacy.local** in Brave or Firefox and  
   
 log in (credentials below).](https://pharmacy.local "https://pharmacy.local")  
**Optional — receipt printer**  
If the machine has the ZJ-80 thermal printer attached:  
sudo apt install -y cups cups-client  
 sudo usermod -aG lp $USER  
 cd ~/CPHC_Pharmacy/pos/printer/scripts  
 sudo bash install-driver.sh        # compile + register rastertozj filter  
 sudo bash install-queue.sh         # creates CPHC_Receipt_80mm queue  
   
The container talks to host CUPS via the bind-mounted /var/run/cups/cups.sock  
   
 — no printer driver lives inside the container.  
**Updating after code changes**  
cd ~/CPHC_Pharmacy  
 git pull  
 cd pos  
 sudo bash update.sh  
   
update.sh rebuilds **only** the app image (--no-cache) and recreates  
   
 **only** the app container (--no-deps). Postgres keeps running on the  
   
 named volume cphc-pos_pg-data, so every sale, audit-log row, GRN, return,  
   
 session — all persist across redeploys. The whole roll is ~90 seconds.  
To truly wipe the database (irreversible):  
docker compose down --volumes  
   
**Ports**  
- 80 → HTTP, redirects to HTTPS.  
- 443 → HTTPS, signed by Caddy's internal CA (trusted by install.sh).  
**Layout**  
pos/  
 ├── Dockerfile             single-stage Alpine  
 ├── Caddyfile              HTTPS + FastCGI to PHP-FPM  
 ├── docker-compose.yml     2 services  
 ├── install.sh             first-time install (sudo)  
 ├── update.sh              redeploy app, keep DB (sudo)  
 ├── public/  
 │   ├── index.php          front controller  
 │   └── assets/  
 │       ├── app.css        hand-written CSS  
 │       └── app.js         vanilla JS  
 ├── src/  
 │   ├── Db.php             PDO factory  
 │   ├── Router.php         array-based dispatch  
 │   ├── Session.php        secure-cookie sessions + fingerprint  
 │   ├── Csrf.php           hand-rolled CSRF  
 │   ├── Csp.php            per-request nonce + security headers  
 │   ├── Auth.php           PIN check, role gate, progressive-delay limiter  
 │   ├── Money.php          bcmath helpers  
 │   ├── Audit.php          audit_log INSERT (DB trigger does HMAC)  
 │   ├── pages/             one PHP file per route  
 │   ├── services/          1:1 ports of existing app/Services/  
 │   └── templates/         layout/nav/flash partials  
 ├── secrets/               db_password, audit_key, session_secret (gitignored)  
 └── tools/                 audit-verify.php and other admin scripts  
   
**Status**  
- Step 1 — Container bootstrap (Docker + Caddy + PHP-FPM serving /healthz).  
- Step 2 — Plumbing (Router, Auth, CSRF, CSP, Session, Money, Audit).  
- Step 3 — POS terminal (search, FEFO, atomic commit, ESC/POS print).  
- Step 4 — Cashier session + signed Z-report + tamper-evident archive.  
- Step 5 — Inventory + GRN with cost-blending + adjustments.  
- Step 6 — Returns state machine + auto sale-status recompute.  
- Step 7 — Reports + RFC-4180 CSV exports (no league/csv).  
- Step 8 — Admin (users, settings, printer, system).  
- Step 9 — Polish (nav, audit-verify CLI, .dockerignore).  
- Step 10 — Tests + cutover.  
**Seeded credentials (change at first login)**  
| | | |  
|-|-|-|  
| **Username** | **PIN** | **Role** |   
| admin | 472913 | ADMIN |   
   
The admin row has must_rotate_pin = TRUE, so first login forces you to  
   
 /account/change-pin.  
**Audit-chain integrity check**  
docker compose exec app php /var/www/tools/audit-verify.php  
 # → OK: N rows, every signature matches.  
   
Exits non-zero if anything is tampered.  
