#!/usr/bin/env python3
"""
Offline Badminton Tournament Entry Software
District Badminton Association — Hoshiarpur
"""

from __future__ import annotations

import io
import os
import re
import shutil
import sqlite3
import zipfile
from datetime import datetime
from functools import wraps
from pathlib import Path

from flask import (
    Flask,
    abort,
    flash,
    g,
    redirect,
    render_template,
    request,
    send_file,
    session,
    url_for,
)
from werkzeug.security import check_password_hash, generate_password_hash
from werkzeug.utils import secure_filename

BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = BASE_DIR / "data"
UPLOAD_DIR = DATA_DIR / "uploads"
BACKUP_DIR = BASE_DIR / "backups"
DB_PATH = DATA_DIR / "badminton.db"

ALLOWED_EXTENSIONS = {"pdf", "png", "jpg", "jpeg", "webp"}

app = Flask(__name__)
app.config["SECRET_KEY"] = os.environ.get("BADMINTON_SECRET", "badminton-offline-hoshiarpur-change-me")
app.config["MAX_CONTENT_LENGTH"] = 12 * 1024 * 1024  # 12 MB


# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------

def get_db() -> sqlite3.Connection:
    if "db" not in g:
        g.db = sqlite3.connect(DB_PATH)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA foreign_keys = ON")
    return g.db


@app.teardown_appcontext
def close_db(_exc=None):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def init_db():
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)

    db = sqlite3.connect(DB_PATH)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA foreign_keys = ON")
    db.executescript(
        """
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS age_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            play_type TEXT NOT NULL CHECK (play_type IN ('single','double')),
            partner_name TEXT,
            age_category_id INTEGER NOT NULL,
            bai_id TEXT,
            pbi_id TEXT,
            aadhaar_no TEXT,
            aadhaar_file TEXT,
            dob TEXT,
            dob_certificate_file TEXT,
            mobile TEXT,
            remarks TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS tournaments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            held_at TEXT NOT NULL,
            date_from TEXT NOT NULL,
            date_to TEXT NOT NULL,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS tournament_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            age_category_id INTEGER NOT NULL,
            selected INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(tournament_id, player_id),
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT
        );
        """
    )

    row = db.execute("SELECT id FROM users WHERE username = ?", ("admin",)).fetchone()
    if not row:
        db.execute(
            "INSERT INTO users (username, password_hash) VALUES (?, ?)",
            ("admin", generate_password_hash("admin123")),
        )
    db.commit()
    db.close()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def login_required(view):
    @wraps(view)
    def wrapped(*args, **kwargs):
        if not session.get("user_id"):
            return redirect(url_for("login", next=request.path))
        return view(*args, **kwargs)

    return wrapped


def fmt_date(value: str | None) -> str:
    if not value:
        return "__________"
    try:
        return datetime.strptime(value, "%Y-%m-%d").strftime("%d-%m-%Y")
    except ValueError:
        return value


def date_range_text(date_from: str, date_to: str) -> str:
    a, b = fmt_date(date_from), fmt_date(date_to)
    if a == b:
        return a
    return f"{a} to {b}"


def allowed_file(filename: str) -> bool:
    return "." in filename and filename.rsplit(".", 1)[-1].lower() in ALLOWED_EXTENSIONS


def save_upload(file_storage, prefix: str) -> str | None:
    if not file_storage or not file_storage.filename:
        return None
    if not allowed_file(file_storage.filename):
        raise ValueError("Only PDF, PNG, JPG, JPEG, WEBP files are allowed.")
    safe = secure_filename(file_storage.filename)
    stamp = datetime.now().strftime("%Y%m%d%H%M%S%f")
    name = f"{prefix}_{stamp}_{safe}"
    path = UPLOAD_DIR / name
    file_storage.save(path)
    return name


def delete_upload(filename: str | None):
    if not filename:
        return
    path = UPLOAD_DIR / filename
    if path.is_file():
        path.unlink()


def clean_aadhaar(value: str) -> str:
    digits = re.sub(r"\D", "", value or "")
    return digits


# ---------------------------------------------------------------------------
# Auth
# ---------------------------------------------------------------------------

@app.route("/login", methods=["GET", "POST"])
def login():
    if session.get("user_id"):
        return redirect(url_for("dashboard"))

    error = None
    if request.method == "POST":
        username = (request.form.get("username") or "").strip()
        password = request.form.get("password") or ""
        db = get_db()
        user = db.execute("SELECT * FROM users WHERE username = ?", (username,)).fetchone()
        if user and check_password_hash(user["password_hash"], password):
            session.clear()
            session["user_id"] = user["id"]
            session["username"] = user["username"]
            nxt = request.args.get("next") or url_for("dashboard")
            return redirect(nxt)
        error = "Invalid username or password."

    return render_template("login.html", error=error)


@app.route("/logout")
def logout():
    session.clear()
    return redirect(url_for("login"))


@app.route("/change-password", methods=["GET", "POST"])
@login_required
def change_password():
    if request.method == "POST":
        current = request.form.get("current_password") or ""
        new = request.form.get("new_password") or ""
        confirm = request.form.get("confirm_password") or ""
        db = get_db()
        user = db.execute("SELECT * FROM users WHERE id = ?", (session["user_id"],)).fetchone()
        if not user or not check_password_hash(user["password_hash"], current):
            flash("Current password is incorrect.", "error")
        elif len(new) < 6:
            flash("New password must be at least 6 characters.", "error")
        elif new != confirm:
            flash("New password and confirm password do not match.", "error")
        else:
            db.execute(
                "UPDATE users SET password_hash = ? WHERE id = ?",
                (generate_password_hash(new), session["user_id"]),
            )
            db.commit()
            flash("Password changed successfully.", "success")
            return redirect(url_for("dashboard"))
    return render_template("change_password.html")


# ---------------------------------------------------------------------------
# Dashboard
# ---------------------------------------------------------------------------

@app.route("/")
@login_required
def dashboard():
    db = get_db()
    stats = {
        "categories": db.execute("SELECT COUNT(*) AS c FROM age_categories").fetchone()["c"],
        "players": db.execute("SELECT COUNT(*) AS c FROM players").fetchone()["c"],
        "tournaments": db.execute("SELECT COUNT(*) AS c FROM tournaments").fetchone()["c"],
    }
    recent = db.execute(
        """
        SELECT t.*,
               (SELECT COUNT(*) FROM tournament_entries e WHERE e.tournament_id = t.id AND e.selected = 1) AS entry_count
        FROM tournaments t
        ORDER BY t.date_from DESC, t.id DESC
        LIMIT 5
        """
    ).fetchall()
    return render_template("dashboard.html", stats=stats, recent=recent)


# ---------------------------------------------------------------------------
# Age categories
# ---------------------------------------------------------------------------

@app.route("/categories")
@login_required
def categories():
    db = get_db()
    rows = db.execute(
        """
        SELECT c.*,
               (SELECT COUNT(*) FROM players p WHERE p.age_category_id = c.id) AS player_count
        FROM age_categories c
        ORDER BY c.sort_order ASC, c.name ASC
        """
    ).fetchall()
    return render_template("categories.html", rows=rows)


@app.route("/categories/new", methods=["GET", "POST"])
@login_required
def category_new():
    if request.method == "POST":
        name = (request.form.get("name") or "").strip()
        notes = (request.form.get("notes") or "").strip()
        sort_order = int(request.form.get("sort_order") or 0)
        if not name:
            flash("Category name is required.", "error")
        else:
            try:
                db = get_db()
                db.execute(
                    "INSERT INTO age_categories (name, sort_order, notes) VALUES (?, ?, ?)",
                    (name, sort_order, notes or None),
                )
                db.commit()
                flash("Age category created.", "success")
                return redirect(url_for("categories"))
            except sqlite3.IntegrityError:
                flash("A category with this name already exists.", "error")
    return render_template("category_form.html", row=None, title="Create Age Category")


@app.route("/categories/<int:cat_id>/edit", methods=["GET", "POST"])
@login_required
def category_edit(cat_id: int):
    db = get_db()
    row = db.execute("SELECT * FROM age_categories WHERE id = ?", (cat_id,)).fetchone()
    if not row:
        abort(404)
    if request.method == "POST":
        name = (request.form.get("name") or "").strip()
        notes = (request.form.get("notes") or "").strip()
        sort_order = int(request.form.get("sort_order") or 0)
        if not name:
            flash("Category name is required.", "error")
        else:
            try:
                db.execute(
                    "UPDATE age_categories SET name = ?, sort_order = ?, notes = ? WHERE id = ?",
                    (name, sort_order, notes or None, cat_id),
                )
                db.commit()
                flash("Age category updated.", "success")
                return redirect(url_for("categories"))
            except sqlite3.IntegrityError:
                flash("A category with this name already exists.", "error")
    return render_template("category_form.html", row=row, title="Edit Age Category")


@app.route("/categories/<int:cat_id>/delete", methods=["POST"])
@login_required
def category_delete(cat_id: int):
    db = get_db()
    used = db.execute("SELECT COUNT(*) AS c FROM players WHERE age_category_id = ?", (cat_id,)).fetchone()["c"]
    if used:
        flash("Cannot remove category while players are linked to it.", "error")
    else:
        db.execute("DELETE FROM age_categories WHERE id = ?", (cat_id,))
        db.commit()
        flash("Age category removed.", "success")
    return redirect(url_for("categories"))


# ---------------------------------------------------------------------------
# Players
# ---------------------------------------------------------------------------

@app.route("/players")
@login_required
def players():
    db = get_db()
    cat_filter = request.args.get("category", type=int)
    q = (request.args.get("q") or "").strip()
    sql = """
        SELECT p.*, c.name AS category_name
        FROM players p
        JOIN age_categories c ON c.id = p.age_category_id
        WHERE 1=1
    """
    params: list = []
    if cat_filter:
        sql += " AND p.age_category_id = ?"
        params.append(cat_filter)
    if q:
        sql += " AND (p.full_name LIKE ? OR p.bai_id LIKE ? OR p.pbi_id LIKE ? OR IFNULL(p.partner_name,'') LIKE ?)"
        like = f"%{q}%"
        params.extend([like, like, like, like])
    sql += " ORDER BY c.sort_order, c.name, p.full_name"
    rows = db.execute(sql, params).fetchall()
    categories = db.execute(
        "SELECT * FROM age_categories ORDER BY sort_order, name"
    ).fetchall()
    return render_template(
        "players.html",
        rows=rows,
        categories=categories,
        cat_filter=cat_filter,
        q=q,
    )


@app.route("/players/new", methods=["GET", "POST"])
@login_required
def player_new():
    db = get_db()
    categories = db.execute(
        "SELECT * FROM age_categories ORDER BY sort_order, name"
    ).fetchall()
    if request.method == "POST":
        try:
            data = _player_form_data()
            aadhaar_file = save_upload(request.files.get("aadhaar_file"), "aadhaar")
            dob_file = save_upload(request.files.get("dob_certificate_file"), "dobcert")
            db.execute(
                """
                INSERT INTO players (
                    full_name, play_type, partner_name, age_category_id,
                    bai_id, pbi_id, aadhaar_no, aadhaar_file, dob, dob_certificate_file,
                    mobile, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    data["full_name"],
                    data["play_type"],
                    data["partner_name"],
                    data["age_category_id"],
                    data["bai_id"],
                    data["pbi_id"],
                    data["aadhaar_no"],
                    aadhaar_file,
                    data["dob"],
                    dob_file,
                    data["mobile"],
                    data["remarks"],
                ),
            )
            db.commit()
            flash("Player saved.", "success")
            return redirect(url_for("players"))
        except ValueError as exc:
            flash(str(exc), "error")
        except Exception as exc:  # noqa: BLE001
            flash(f"Could not save player: {exc}", "error")
    return render_template("player_form.html", row=None, categories=categories, title="Add Player")


@app.route("/players/<int:player_id>/edit", methods=["GET", "POST"])
@login_required
def player_edit(player_id: int):
    db = get_db()
    row = db.execute("SELECT * FROM players WHERE id = ?", (player_id,)).fetchone()
    if not row:
        abort(404)
    categories = db.execute(
        "SELECT * FROM age_categories ORDER BY sort_order, name"
    ).fetchall()
    if request.method == "POST":
        try:
            data = _player_form_data()
            aadhaar_file = row["aadhaar_file"]
            dob_file = row["dob_certificate_file"]
            new_aadhaar = save_upload(request.files.get("aadhaar_file"), "aadhaar")
            new_dob = save_upload(request.files.get("dob_certificate_file"), "dobcert")
            if new_aadhaar:
                delete_upload(aadhaar_file)
                aadhaar_file = new_aadhaar
            if new_dob:
                delete_upload(dob_file)
                dob_file = new_dob
            if request.form.get("remove_aadhaar_file") == "1" and not new_aadhaar:
                delete_upload(aadhaar_file)
                aadhaar_file = None
            if request.form.get("remove_dob_certificate_file") == "1" and not new_dob:
                delete_upload(dob_file)
                dob_file = None
            db.execute(
                """
                UPDATE players SET
                    full_name = ?, play_type = ?, partner_name = ?, age_category_id = ?,
                    bai_id = ?, pbi_id = ?, aadhaar_no = ?, aadhaar_file = ?, dob = ?,
                    dob_certificate_file = ?, mobile = ?, remarks = ?,
                    updated_at = datetime('now','localtime')
                WHERE id = ?
                """,
                (
                    data["full_name"],
                    data["play_type"],
                    data["partner_name"],
                    data["age_category_id"],
                    data["bai_id"],
                    data["pbi_id"],
                    data["aadhaar_no"],
                    aadhaar_file,
                    data["dob"],
                    dob_file,
                    data["mobile"],
                    data["remarks"],
                    player_id,
                ),
            )
            db.commit()
            flash("Player details updated.", "success")
            return redirect(url_for("players"))
        except ValueError as exc:
            flash(str(exc), "error")
        except Exception as exc:  # noqa: BLE001
            flash(f"Could not update player: {exc}", "error")
        row = db.execute("SELECT * FROM players WHERE id = ?", (player_id,)).fetchone()
    return render_template("player_form.html", row=row, categories=categories, title="Edit Player")


@app.route("/players/<int:player_id>/delete", methods=["POST"])
@login_required
def player_delete(player_id: int):
    db = get_db()
    row = db.execute("SELECT * FROM players WHERE id = ?", (player_id,)).fetchone()
    if row:
        delete_upload(row["aadhaar_file"])
        delete_upload(row["dob_certificate_file"])
        db.execute("DELETE FROM players WHERE id = ?", (player_id,))
        db.commit()
        flash("Player removed.", "success")
    return redirect(url_for("players"))


def _player_form_data() -> dict:
    full_name = (request.form.get("full_name") or "").strip()
    play_type = (request.form.get("play_type") or "").strip().lower()
    partner_name = (request.form.get("partner_name") or "").strip()
    age_category_id = request.form.get("age_category_id", type=int)
    bai_id = (request.form.get("bai_id") or "").strip()
    pbi_id = (request.form.get("pbi_id") or "").strip()
    aadhaar_no = clean_aadhaar(request.form.get("aadhaar_no") or "")
    dob = (request.form.get("dob") or "").strip() or None
    mobile = (request.form.get("mobile") or "").strip()
    remarks = (request.form.get("remarks") or "").strip()

    if not full_name:
        raise ValueError("Player name is required.")
    if play_type not in ("single", "double"):
        raise ValueError("Select Single or Double.")
    if play_type == "double" and not partner_name:
        raise ValueError("Partner name is required for Double.")
    if play_type == "single":
        partner_name = None
    if not age_category_id:
        raise ValueError("Age category is required. Create a category first if needed.")
    if aadhaar_no and len(aadhaar_no) != 12:
        raise ValueError("Aadhaar number must be 12 digits.")

    return {
        "full_name": full_name,
        "play_type": play_type,
        "partner_name": partner_name,
        "age_category_id": age_category_id,
        "bai_id": bai_id or None,
        "pbi_id": pbi_id or None,
        "aadhaar_no": aadhaar_no or None,
        "dob": dob,
        "mobile": mobile or None,
        "remarks": remarks or None,
    }


@app.route("/uploads/<path:filename>")
@login_required
def uploaded_file(filename: str):
    path = UPLOAD_DIR / filename
    if not path.is_file():
        abort(404)
    return send_file(path)


# ---------------------------------------------------------------------------
# Tournaments
# ---------------------------------------------------------------------------

@app.route("/tournaments")
@login_required
def tournaments():
    db = get_db()
    rows = db.execute(
        """
        SELECT t.*,
               (SELECT COUNT(*) FROM tournament_entries e WHERE e.tournament_id = t.id AND e.selected = 1) AS entry_count
        FROM tournaments t
        ORDER BY t.date_from DESC, t.id DESC
        """
    ).fetchall()
    return render_template("tournaments.html", rows=rows)


@app.route("/tournaments/new", methods=["GET", "POST"])
@login_required
def tournament_new():
    if request.method == "POST":
        try:
            data = _tournament_form_data()
            db = get_db()
            cur = db.execute(
                """
                INSERT INTO tournaments (name, held_at, date_from, date_to, notes)
                VALUES (?, ?, ?, ?, ?)
                """,
                (data["name"], data["held_at"], data["date_from"], data["date_to"], data["notes"]),
            )
            db.commit()
            flash("Tournament created. Open a category and tick participating players.", "success")
            return redirect(url_for("tournament_detail", tournament_id=cur.lastrowid))
        except ValueError as exc:
            flash(str(exc), "error")
    return render_template("tournament_form.html", row=None, title="Create Tournament")


@app.route("/tournaments/<int:tournament_id>/edit", methods=["GET", "POST"])
@login_required
def tournament_edit(tournament_id: int):
    db = get_db()
    row = db.execute("SELECT * FROM tournaments WHERE id = ?", (tournament_id,)).fetchone()
    if not row:
        abort(404)
    if request.method == "POST":
        try:
            data = _tournament_form_data()
            db.execute(
                """
                UPDATE tournaments SET name = ?, held_at = ?, date_from = ?, date_to = ?, notes = ?,
                    updated_at = datetime('now','localtime')
                WHERE id = ?
                """,
                (
                    data["name"],
                    data["held_at"],
                    data["date_from"],
                    data["date_to"],
                    data["notes"],
                    tournament_id,
                ),
            )
            db.commit()
            flash("Tournament updated.", "success")
            return redirect(url_for("tournament_detail", tournament_id=tournament_id))
        except ValueError as exc:
            flash(str(exc), "error")
    return render_template("tournament_form.html", row=row, title="Edit Tournament")


@app.route("/tournaments/<int:tournament_id>/delete", methods=["POST"])
@login_required
def tournament_delete(tournament_id: int):
    db = get_db()
    db.execute("DELETE FROM tournaments WHERE id = ?", (tournament_id,))
    db.commit()
    flash("Tournament removed.", "success")
    return redirect(url_for("tournaments"))


def _tournament_form_data() -> dict:
    name = (request.form.get("name") or "").strip()
    held_at = (request.form.get("held_at") or "").strip()
    date_from = (request.form.get("date_from") or "").strip()
    date_to = (request.form.get("date_to") or "").strip()
    notes = (request.form.get("notes") or "").strip()
    if not name:
        raise ValueError("Tournament name is required.")
    if not held_at:
        raise ValueError("Held at (venue / city) is required.")
    if not date_from or not date_to:
        raise ValueError("From and To dates are required.")
    if date_to < date_from:
        raise ValueError("To date cannot be before From date.")
    return {
        "name": name,
        "held_at": held_at,
        "date_from": date_from,
        "date_to": date_to,
        "notes": notes or None,
    }


@app.route("/tournaments/<int:tournament_id>")
@login_required
def tournament_detail(tournament_id: int):
    db = get_db()
    tournament = db.execute("SELECT * FROM tournaments WHERE id = ?", (tournament_id,)).fetchone()
    if not tournament:
        abort(404)
    categories = db.execute(
        """
        SELECT c.*,
               (SELECT COUNT(*) FROM players p WHERE p.age_category_id = c.id) AS player_count,
               (SELECT COUNT(*) FROM tournament_entries e
                 WHERE e.tournament_id = ? AND e.age_category_id = c.id AND e.selected = 1) AS selected_count
        FROM age_categories c
        ORDER BY c.sort_order, c.name
        """,
        (tournament_id,),
    ).fetchall()
    return render_template(
        "tournament_detail.html",
        tournament=tournament,
        categories=categories,
        date_text=date_range_text(tournament["date_from"], tournament["date_to"]),
    )


@app.route("/tournaments/<int:tournament_id>/categories/<int:cat_id>", methods=["GET", "POST"])
@login_required
def tournament_category(tournament_id: int, cat_id: int):
    db = get_db()
    tournament = db.execute("SELECT * FROM tournaments WHERE id = ?", (tournament_id,)).fetchone()
    category = db.execute("SELECT * FROM age_categories WHERE id = ?", (cat_id,)).fetchone()
    if not tournament or not category:
        abort(404)

    if request.method == "POST":
        selected_ids = {int(x) for x in request.form.getlist("player_ids") if str(x).isdigit()}
        players = db.execute(
            "SELECT id FROM players WHERE age_category_id = ?", (cat_id,)
        ).fetchall()
        for p in players:
            pid = p["id"]
            selected = 1 if pid in selected_ids else 0
            existing = db.execute(
                "SELECT id FROM tournament_entries WHERE tournament_id = ? AND player_id = ?",
                (tournament_id, pid),
            ).fetchone()
            if selected:
                if existing:
                    db.execute(
                        "UPDATE tournament_entries SET selected = 1, age_category_id = ? WHERE id = ?",
                        (cat_id, existing["id"]),
                    )
                else:
                    db.execute(
                        """
                        INSERT INTO tournament_entries (tournament_id, player_id, age_category_id, selected)
                        VALUES (?, ?, ?, 1)
                        """,
                        (tournament_id, pid, cat_id),
                    )
            elif existing:
                db.execute("DELETE FROM tournament_entries WHERE id = ?", (existing["id"],))
        db.commit()
        flash(f"Saved participants for {category['name']}.", "success")
        return redirect(url_for("tournament_detail", tournament_id=tournament_id))

    players = db.execute(
        """
        SELECT p.*,
               CASE WHEN e.selected = 1 THEN 1 ELSE 0 END AS is_selected
        FROM players p
        LEFT JOIN tournament_entries e
          ON e.player_id = p.id AND e.tournament_id = ?
        WHERE p.age_category_id = ?
        ORDER BY p.full_name
        """,
        (tournament_id, cat_id),
    ).fetchall()
    return render_template(
        "tournament_category.html",
        tournament=tournament,
        category=category,
        players=players,
        date_text=date_range_text(tournament["date_from"], tournament["date_to"]),
    )


# ---------------------------------------------------------------------------
# Letter / list export
# ---------------------------------------------------------------------------

@app.route("/tournaments/<int:tournament_id>/letter")
@login_required
def tournament_letter(tournament_id: int):
    db = get_db()
    tournament = db.execute("SELECT * FROM tournaments WHERE id = ?", (tournament_id,)).fetchone()
    if not tournament:
        abort(404)

    categories = db.execute(
        """
        SELECT DISTINCT c.*
        FROM age_categories c
        JOIN tournament_entries e ON e.age_category_id = c.id
        WHERE e.tournament_id = ? AND e.selected = 1
        ORDER BY c.sort_order, c.name
        """,
        (tournament_id,),
    ).fetchall()

    grouped = []
    for cat in categories:
        players = db.execute(
            """
            SELECT p.*
            FROM players p
            JOIN tournament_entries e ON e.player_id = p.id
            WHERE e.tournament_id = ? AND e.selected = 1 AND e.age_category_id = ?
            ORDER BY p.full_name
            """,
            (tournament_id, cat["id"]),
        ).fetchall()
        if players:
            grouped.append({"category": cat, "players": players})

    return render_template(
        "letter.html",
        tournament=tournament,
        grouped=grouped,
        held_at=tournament["held_at"],
        date_text=date_range_text(tournament["date_from"], tournament["date_to"]),
        today=datetime.now().strftime("%d-%m-%Y"),
    )


# ---------------------------------------------------------------------------
# Backup
# ---------------------------------------------------------------------------

@app.route("/backup")
@login_required
def backup_page():
    backups = sorted(BACKUP_DIR.glob("*.zip"), key=lambda p: p.stat().st_mtime, reverse=True)
    items = [
        {
            "name": p.name,
            "size_kb": round(p.stat().st_size / 1024, 1),
            "mtime": datetime.fromtimestamp(p.stat().st_mtime).strftime("%d-%m-%Y %H:%M"),
        }
        for p in backups[:20]
    ]
    return render_template("backup.html", backups=items)


@app.route("/backup/create", methods=["POST"])
@login_required
def backup_create():
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    zip_name = f"Badminton_backup_{stamp}.zip"
    zip_path = BACKUP_DIR / zip_name

    skip_dirs = {".git", "__pycache__", ".venv", "venv", "backups", "node_modules"}
    skip_names = {".DS_Store", "Thumbs.db"}

    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        # Always include live database + uploads
        if DB_PATH.is_file():
            zf.write(DB_PATH, arcname="data/badminton.db")
        if UPLOAD_DIR.is_dir():
            for f in UPLOAD_DIR.rglob("*"):
                if f.is_file():
                    zf.write(f, arcname=str(f.relative_to(BASE_DIR)))

        # Source code (excluding venv / backups / cache)
        for path in BASE_DIR.rglob("*"):
            if not path.is_file():
                continue
            rel = path.relative_to(BASE_DIR)
            parts = rel.parts
            if parts and parts[0] in skip_dirs:
                continue
            if any(p in skip_dirs for p in parts):
                continue
            if path.name in skip_names:
                continue
            if parts and parts[0] == "data":
                continue  # already added carefully above
            if parts and parts[0] == "backups":
                continue
            zf.write(path, arcname=str(rel))

    flash(f"Backup created: {zip_name}. Save this file on your laptop.", "success")
    return redirect(url_for("backup_page"))


@app.route("/backup/download/<path:filename>")
@login_required
def backup_download(filename: str):
    safe = Path(filename).name
    path = BACKUP_DIR / safe
    if not path.is_file() or not safe.endswith(".zip"):
        abort(404)
    return send_file(path, as_attachment=True, download_name=safe)


@app.route("/backup/database")
@login_required
def backup_database_only():
    if not DB_PATH.is_file():
        abort(404)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    return send_file(
        DB_PATH,
        as_attachment=True,
        download_name=f"badminton_db_{stamp}.db",
    )


# ---------------------------------------------------------------------------
# Bootstrap
# ---------------------------------------------------------------------------

init_db()


if __name__ == "__main__":
    print("=" * 60)
    print("  Badminton Tournament Entry — Offline")
    print("  Open in browser:  http://127.0.0.1:5055")
    print("  Username: admin")
    print("  Password: admin123")
    print("=" * 60)
    app.run(host="127.0.0.1", port=5055, debug=False)
