# Badminton Tournament Entry (Offline)

Standalone offline software for **District Badminton Association, Hoshiarpur**.

This project is **separate from School ERP**. It does not use School ERP files or database.

## What it does

1. **Admin login** (username + password)
2. **Create age categories** (U-13, U-15, Senior, etc.)
3. **Add / edit / remove players**
   - Name
   - Single or Double (+ partner name for Double)
   - BAI ID
   - PBI ID
   - Aadhaar number + Aadhaar card file
   - Date of birth + DOB certificate file
4. **Create tournament**
   - Tournament name
   - Held at ______
   - On ______ to ______
5. **Open a category → tick players who will participate → Save**
6. **Open other categories** the same way
7. **Prepare letter** in Punjab Badminton Association format and Print / Save as PDF
8. **Backup** database + source code ZIP to your laptop

## Default login

| Field | Value |
|-------|-------|
| Username | `admin` |
| Password | `admin123` |

Change the password after first login (menu → **Password**).

## Install and run (Windows laptop)

1. Install [Python 3.10+](https://www.python.org/downloads/) — tick **Add Python to PATH**
2. Copy this `Badminton` folder to your laptop (e.g. `D:\Badminton`)
3. Double-click **`install.bat`** once
4. Double-click **`run.bat`**
5. Browser opens at `http://127.0.0.1:5055`
6. Login with `admin` / `admin123`

Keep the black `run.bat` window open while using the software.

## Install and run (Linux / Mac)

```bash
cd Badminton
chmod +x install.sh run.sh
./install.sh
./run.sh
```

Open `http://127.0.0.1:5055` and login.

## How to use (daily)

1. **Age Categories** → create categories
2. **Players** → add player details and documents
3. **Tournaments** → create tournament (name, held at, from–to dates)
4. Open the tournament → **Open & tick** each category → Save
5. Click **Prepare letter** → Print / Save as PDF
6. **Backup** → create ZIP and download to laptop / USB

## Backup

In the app: **Backup** → *Create full backup (database + source code ZIP)* → Download.

The ZIP includes:

- `data/badminton.db` (all saved data)
- uploaded Aadhaar / DOB certificate files
- full source code

You can also download **database only** (`.db` file).

## Put source code on GitHub (name: Badminton)

This agent cannot create a new GitHub repository (School ERP token is separate/read-only for new repos). On your laptop:

1. Create a **new empty repository** on GitHub named **`Badminton`** (under your account)
2. Then run:

```bash
cd Badminton
git init
git add .
git commit -m "Initial offline Badminton tournament entry software"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/Badminton.git
git push -u origin main
```

Replace `YOUR_USERNAME` with your GitHub username.

## Tech

- Python + Flask
- SQLite database (offline, stored in `data/badminton.db`)
- Runs only on your computer (`127.0.0.1`) — no internet required after install

## Letter format used

> To  
> The Secretary  
> Punjab Badminton Association  
>  
> Subject: Details of Players Participating in the Tournament Held at __________ on __________  
>  
> …category-wise player list…  
>  
> Thanking You  
> Member  
> District Badminton Association  
> Hoshiarpur
