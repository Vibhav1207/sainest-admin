# Hotel Sai Nest — Hotel Management System

A complete, self-contained hotel booking & front-desk management system built
for **Hotel Sai Nest, Shirdi** — dashboard, check-in, check-out, housekeeping,
guest/document management, billing & invoicing, and automatic guest-data
cleanup. Built in plain PHP + MySQL so it can be dropped into any shared
hosting account (cPanel, Hostinger, GoDaddy, etc.) and merged with your
existing `sainest.org` account under a subdomain — with **zero code
changes required**.

---

## 1. What's included

| Feature | Where |
|---|---|
| Staff login (role based: Admin / Manager / Front Desk / Housekeeping) | `login.php` |
| Dashboard with KPIs, today's check-ins/check-outs, revenue & commission | `dashboard.php` |
| Check-In — room selection, multi-guest support, ID proof upload, internal commission field | `checkin.php` |
| Check-Out — auto bill calculation, extra charges, tax, discount, payment collection | `checkout.php` |
| Printable / PDF-able Guest Invoice (commission is never shown here) | `invoice_print.php` |
| Room management (rooms, room types, live status) | `rooms.php` |
| Housekeeping task board (cleaning/maintenance/inspection) | `housekeeping.php` |
| Guest directory + automatic 1-year data retention policy | `guests.php`, `cron/data_retention_cleanup.php` |
| All bookings — search & filter | `bookings.php` |
| Booking detail — guests, ID documents, payments, commission (staff only) | `booking_view.php` |
| Reports — revenue, commission by source, revenue by room | `reports.php` |
| Staff user management | `users.php` |
| Hotel settings (name, address, logo, tax, retention period) | `settings.php` |

Branding (logo, colours, fonts, address, phone, email) has been matched to
your existing `sainest.org` website automatically.

---

## 2. Requirements

- PHP 8.0 or newer with the `pdo_mysql`, `fileinfo`, and `mbstring` extensions
  (all standard on shared hosting)
- MySQL or MariaDB 5.7+
- Apache with `mod_rewrite` / `.htaccess` support (used for security only —
  no rewrite rules are required)

---

## 3. First-time setup (5 minutes)

1. **Create a database** in cPanel → MySQL Databases (e.g. `sainest_hms`)
   and a database user with full privileges on it.
2. **Import the schema.** In phpMyAdmin, open the new database and import
   `database.sql`. This creates all tables and a default admin login.
3. **Upload the files.** Upload the *entire contents* of this folder to
   your hosting account (see Section 4 for exactly where).
4. **Edit `config/config.php`** and set your real database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'sainest_hms');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_db_password');
   ```
   Nothing else in this file needs to change — the site URL is detected
   automatically.
5. **Set folder permissions** (if your host requires it):
   `uploads/documents/` and `uploads/logo/` should be writable (755 or 775).
6. **Log in** at `https://yourdomain/login.php` with:
   - Username: `admin`
   - Password: `Admin@123`

   **⚠️ Change this password immediately** from the user menu (top-right) →
   *My Profile* → Change Password, or from *Staff Users* if you're an admin
   resetting another account.

---

## 4. Merging with your subdomain later

This project is fully self-contained and portable — it doesn't hardcode any
domain name. To merge it into your `sainest.org` hosting account under a
subdomain such as `manage.sainest.org` or `book.sainest.org`:

1. In cPanel → **Subdomains**, create the subdomain (e.g. `manage`) and
   point its document root to a new folder, e.g. `public_html/manage`.
2. Upload the contents of this project (everything inside the zip) into
   that folder.
3. Create the database and import `database.sql` as described above.
4. Update `config/config.php` with the DB credentials.
5. Visit `https://manage.sainest.org/login.php` — done. No path or URL
   constants need to be edited anywhere else in the code.

You can also place it in a sub-folder of your main domain (e.g.
`sainest.org/manage/`) the same way — the app detects its own base URL
automatically regardless of where it lives.

---

## 5. Automatic guest data retention (privacy + storage cleanup)

Per your requirement, guest identity data (name, phone, email, address, ID
proof number and ID proof photos) is **automatically and permanently
removed** for any guest who has not stayed at the hotel in the last 12
months (configurable in *Settings*). This keeps your database lean and
protects guest privacy. Booking and invoice records are kept (with the
personal fields blanked out) so your revenue history and accounting stay
accurate.

To activate it, add this to your hosting's cron job manager
(cPanel → **Cron Jobs**), running once a day:

```
0 3 * * *  /usr/bin/php /home/yourcpaneluser/public_html/manage/cron/data_retention_cleanup.php >> /home/yourcpaneluser/public_html/manage/cron/cleanup.log 2>&1
```

Adjust the path to match where you uploaded the project. You can also run
it manually any time from the command line or via cPanel's "Run Now" option
to test it.

---

## 6. How the commission feature works

At Check-In, staff can record:
- **Booking Source** (Walk-in,Clear Trip Phone, Online, Travel Agent, or a specific OTA)
- **Agent / OTA name**
- **Commission %** and **Commission Amount** (auto-calculated from the room
  rate, editable)
- **Commission Status** (Not Applicable / Pending / Paid)

**Billing behavior:** at Check-Out, the Commission Amount is silently folded
into the guest's **Room Charges**. For example, a ₹1200 room with a ₹50
commission is billed to the guest as a single combined **Room Charges of
₹1250** — the guest never sees a separate commission line or any breakdown,
and the printed invoice's per-night rate is recalculated so it always
multiplies out cleanly to that combined figure.

Internally, the true split is preserved so accounting stays accurate:
- The `invoices` table stores `room_charges` (the ₹1250 the guest was
  billed) **and** `commission_amount` (the ₹50) separately.
- **Actual Room Revenue** (₹1200 = `room_charges - commission_amount`) is
  shown on the Dashboard, Booking detail page and Reports.

This entire section is clearly marked **"Internal Only"** in the interface.
It is:
- ✅ Visible on the Dashboard, Bookings list, Booking detail page and
  Reports — **only to Admin and Manager roles**
- ✅ Included in revenue/commission reports for accounting, alongside the
  separate "Actual Room Revenue" figure
- ❌ **Never itemised or printed on the guest-facing invoice** (`invoice_print.php`)
  — the guest only ever sees the one combined Room Charges amount
- ❌ Not visible to the Front Desk or Housekeeping roles

> **Upgrading an existing install?** If your database was created before
> this behavior was added, run `invoice_commission_fix.sql` once (see the
> instructions inside that file) to add the missing `commission_amount`
> column to the `invoices` table.

---

## 7. Roles & permissions

| Role | Dashboard | Check-In/Out | Rooms | Housekeeping | Guests/Bookings | Commission | Reports | Staff Users | Settings |
|---|---|---|---|---|---|---|---|---|---|
| Admin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Manager | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Front Desk | ✅ | ✅ | View | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Housekeeping | ✅ | ❌ | View | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

Create additional staff accounts from *Staff Users* (Admin only).

---

## 8. Multiple guests per room

At Check-In, the primary guest's full details and a photo ID are mandatory.
Click **"Add Another Guest"** to add any number of additional guests staying
in the same room — each can optionally have their own ID proof recorded.
All guests are linked to the same booking and shown together on the
Booking detail page.

---

## 9. Security notes

- Passwords are hashed with PHP's `password_hash()` (bcrypt).
- All forms are protected against CSRF with per-session tokens.
- ID proof documents are stored outside of direct public access
  (`uploads/documents/.htaccess` denies all direct requests) and can only
  be viewed by a logged-in staff member through `doc_view.php`.
- Set `APP_DEBUG` to `false` in `config/config.php` once you've confirmed
  everything works, to hide detailed error messages from visitors.

---

## 10. Support

For any customisation (adding more room types, changing tax rules, adding
more roles, connecting to an SMS/WhatsApp gateway for booking confirmations,
etc.), this is a plain PHP + MySQL codebase with no framework dependency,
so any PHP developer can extend it easily. Key folders:

- `includes/` — shared logic, layout, database connection
- `assets/css/style.css` — all styling (uses CSS variables matched to the
  Sai Nest brand palette)
- `cron/` — scheduled maintenance scripts
- `database.sql` — full schema with comments
