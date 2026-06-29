# das — Dashboard Tools

A Persian (RTL) dashboard for accessing a collection of helper tools, with admin panel,
authentication (registration + email verification + password reset), session management,
and notification system. Built with plain PHP (no framework) on a lightweight MVC architecture.

## Requirements

- **PHP 8.0+** (uses `match` and union types; PHP 7.4 will not work)
- **MySQL 8** (or compatible MariaDB)
- Web server with `.htaccess` support (Apache/LiteSpeed) for clean URLs

## Project Structure

```
das/                         webroot (on host: public_html)

    Entry points (directly served PHP files)
    index.php                Main dashboard
    login.php                Login / Register / Forgot password
    profile.php              User account settings
    notifications.php        Notification history & search
    admin.php                Admin panel (?page= views, ?api= JSON)
    api.php                  Public API (?action=...)

    Shared includes (not directly served, denied via .htaccess)
    bootstrap.php            Shared setup: autoload + config + DB + session
    version.php              Single source of version + asset versioning

    Backend MVC (app/ fully denied, loaded via include only)
    app/
        Core/                DB, Router, PublicRouter, Request, Response,
                             UserSession, DbSessionHandler, Mailer, Validator, ...
        Models/              Database layer (User, Tool, Notification, Session, Settings, ...)
        Controllers/         Admin (Tool/User/Access/Session/...) + Public (App/Auth/Feed)
        Views/               Admin panel templates (dashboard, users, settings, notifications)

    Static assets
    assets/
        css/                 Public page styles (style, profile, notifications, datepicker)
        js/                  Public page scripts (script, theme, login, tooltip, field, ...)
        admin/               Admin panel CSS/JS (admin.*, notifications-admin.*)

    data/                    JSON storage for icons/decorators (filesystem only)
    fonts/                   Vazir / IRANSans fonts
```

## Setup

1. **Configuration:** Copy `config.example.php` to `config.php` **one level above the webroot**
   and fill in the database credentials. On the host, `config.php` must be at
   `/home/username/config.php` (bootstrap.php loads it via `dirname(__DIR__)`).

2. **Database:** Create a MySQL database and import the schema
   (`users`, `tools`, `sessions`, `notifications`, `app_settings`, etc.).

3. **Production:** Place this folder as `public_html`; `.htaccess` handles
   clean URLs, security denies, and caching.

   **Local development:**
   ```
   php -S 127.0.0.1:8080 dev-router.php
   ```

## Security Notes

- `config.php` (DB credentials), `*.sql` dumps, and archives are in `.gitignore`
  and must never be committed.
- Sensitive files must be **outside the webroot**: `config.php` one level above,
  database dumps outside the published directory entirely.
- `.htaccess` serves as a second layer, denying direct access to config/app files.
