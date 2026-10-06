# Badminton Tournament Entry (Website)

**Separate from School ERP.**  
This is its own website + its own GitHub repository + its own Hostinger website.

Do **not** put this code inside `school-fee-erp`.

---

## What this website does

1. Admin login  
2. Create age categories  
3. Add players (name, gender, docs) — no category/event on create  
4. Assign age category + **one or more events** (Single / Double Men / Double Girls / Mix Double)  
   - Same player can play in multiple events in a tournament  
5. Create tournament (name, held at, from–to dates)  
6. Form teams per category + event (1 or 2 players by event rules)  
7. Export participating letter / CSV  
8. Backup database + source code  

---

## Login

| Field | Value |
|-------|-------|
| Username | `admin` |
| Password | `admin123` |

Change password after first login (menu → **Password**).

---

## Step 1 — Create GitHub repo named `Badminton` (you do this once)

1. Open: https://github.com/new  
2. Repository name: **`Badminton`**  
3. Private or Public — your choice  
4. **Do not** add README / .gitignore / license (empty repo)  
5. Click **Create repository**  
6. Reply with the URL, example: `https://github.com/Amitahps/Badminton`

This must be a **new** repo. Not School ERP.

---

## Step 2 — Push this website to that GitHub repo

After the empty `Badminton` repo exists:

```bash
cd Badminton
git remote add origin https://github.com/YOUR_USERNAME/Badminton.git
git branch -M main
git push -u origin main
```

(If the agent has access to your new repo, the agent can push for you.)

---

## Step 3 — Connect GitHub → Hostinger (online website)

In Hostinger, create a **separate website / domain or subdomain** for Badminton  
(example: `badminton.yourdomain.com` or a new domain).  
**Do not deploy into the School ERP website folder.**

Then:

1. Hostinger hPanel → your Badminton website → **Git** (or Deployments)  
2. Connect the GitHub repo **`Badminton`** (not `school-fee-erp`)  
3. Branch: `main`  
4. Deploy path: usually `public_html` for that website  
5. Deploy  

Requirements on Hostinger:

- PHP 8.0+ (8.1/8.2 recommended)  
- PDO SQLite enabled (usually on by default)  
- Folders `data/` and `data/uploads/` and `backups/` must be writable (permission 755 or 775)

After deploy, open:

`https://YOUR-BADMINTON-DOMAIN/login.php`

---

## Local test (optional)

If PHP is installed on your laptop:

```bash
cd Badminton
php -S 127.0.0.1:8080
```

Open http://127.0.0.1:8080/login.php

---

## Backup

In the website: **Backup** → Create full backup ZIP → Download to laptop.

---

## Important separation

| Project | GitHub | Hostinger |
|---------|--------|-----------|
| School ERP | `school-fee-erp` | School ERP website |
| Badminton | `Badminton` | Separate Badminton website |

Never mix the two.
