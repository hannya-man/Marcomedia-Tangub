# Marcomedia Tangub POS — Codebase Overview + Appointment Feature Removal Map

## Summary
A Laravel 10 (PHP 8.x) point-of-sale and management system for a Marcomedia printshop / photo studio in Tangub. It handles billing (POS), inventory with variant-level stock tracking (t-shirt sizes, trophy sizes), raw materials, sales/orders, a staff chat widget, user management, and — the feature targeted for removal — **appointments** (client booking for photography services: prenup, graduation, passport, events, studio portraits).

Built on XAMPP + MySQL with no background workers (no queues, no WebSockets — staff chat uses HTTP polling). The client wants the appointment system **removed** for safety.

## Architecture
- **Pattern**: Traditional server-rendered Laravel MVC with Blade templates + Alpine.js (CDN) for interactivity, Tailwind CSS via Vite, Chart.js for dashboard charts, and lucide icons.
- **Runtime**: PHP 8.x on XAMPP (Apache + MySQL). No Redis, no Horizon, no queue workers.
- **Key stack**: Laravel 10, Blade, Alpine.js 3, Tailwind (compiled via Vite), Chart.js 4, MySQL.
- **Entry points**: `public/index.php` → `routes/web.php`. Auth is Laravel Breeze (session-based).
- **Roles**: `admin` and `cashier` only. A `photographer` role existed but was **removed** in migration `2024_01_01_000018_remove_photographer_role.php` — however, orphaned photographer views/layout remain as dead code (see below).
- **Middleware**: `role:` alias → `EnsureRole` (active), `no-cache` → `PreventBackHistory`. Note: `EnsureUserHasRole` exists but is **not registered** in `app/Http/Kernel.php`; it is dead code.
- **Execution flow**: Web request → `web` middleware group → route → controller → Blade view. No SPA; full page navigations with a small "loading bar" JS enhancement.

## Directory Structure (appointment-relevant portions)

```
Marcomedia Tangub/
├── app/
│   ├── Http/Controllers/
│   │   ├── AppointmentController.php     ← REMOVE (entire file)
│   │   └── DashboardController.php       ← EDIT (remove appointment queries)
│   ├── Models/
│   │   └── Appointment.php               ← REMOVE (entire file)
│   └── Http/Middleware/
│       └── EnsureUserHasRole.php         ← dead code, references photographer route
├── routes/
│   └── web.php                           ← EDIT (remove appointment routes)
├── resources/views/
│   ├── pages/
│   │   ├── appointments.blade.php        ← REMOVE (main page)
│   │   ├── appointments-archive.blade.php← REMOVE (archive page)
│   │   └── appointment-form.blade.php    ← REMOVE (legacy multi-step form, unreachable)
│   ├── layouts/
│   │   ├── app.blade.php                 ← EDIT (remove sidebar "Appointments" link)
│   │   └── photographer.blade.php        ← dead orphan (photographer role removed)
│   ├── photographer/
│   │   ├── appointments.blade.php        ← dead orphan (hardcoded data)
│   │   └── booking-detail.blade.php      ← dead orphan (hardcoded data)
│   └── dashboard/
│       └── index.blade.php               ← EDIT (remove Appointments card + Upcoming panel)
└── database/
    ├── migrations/
    │   ├── 2024_01_01_000009_create_appointments_table.php              ← migration to drop
    │   ├── 2024_01_01_000011_add_archive_fields_to_appointments_table.php ← migration to drop
    │   ├── 2024_01_01_000011_add_archiving_to_appointments_table.php    ← migration to drop (DUPLICATE #, guarded)
    │   ├── 2024_01_01_000014_add_seen_at_to_appointments_table.php      ← migration to drop
    │   └── 2024_01_01_000018_remove_photographer_role.php               ← EDIT (drop-columns block for appointments)
    ├── schema.sql                        ← EDIT (add appointments DROP, fix role enum — see notes)
```

## Key Abstractions

### AppointmentController
- **File**: `app/Http/Controllers/AppointmentController.php`
- **Responsibility**: Full CRUD + archive/restore for appointments. "Delete" is soft — records are marked `status='archived'` with `archived_reason` (`manual` or `expired`).
- **Key methods**:
  - `index()` — lists non-archived appointments, calls `autoArchiveExpired()`, renders calendar month
  - `store()` — validates + creates with `status='pending'`; server-side enforces `after_or_equal:today`
  - `update()` — edits any appointment incl. status change
  - `destroy()` — archives (soft delete)
  - `restore()` — un-archives back to `pending`
  - `archive()` — shows archived list
  - `autoArchiveExpired()` — **private, runs on every `index()`/`archive()` load**: past-date appointments that aren't done/cancelled/archived get swept to `status='archived'` with reason `expired`
- **Used by**: `routes/web.php` (7 routes); reaches into the `appointments` table.

### Appointment (model)
- **File**: `app/Models/Appointment.php`
- **Responsibility**: Eloquent model for the `appointments` table. `$fillable` covers client info, service, schedule, status, archive fields. `appointment_date` cast to `date`.
- **Used by**: `AppointmentController`, `DashboardController`.

### Appointment DB schema (current state after all migrations)
Table `appointments`:
- `id` (PK)
- `client_name` varchar(150)
- `contact_number` varchar(30)
- `email` varchar(150) nullable
- `service` varchar(150)
- `appointment_date` date
- `appointment_time` time
- `location` varchar(150) nullable
- `notes` text nullable
- `status` enum(`pending`,`confirmed`,`done`,`cancelled`,`archived`) default `pending`
- `archived_at` timestamp nullable
- `archived_reason` enum(`manual`,`expired`) nullable
- `is_archived` boolean default false (_unused in logic; added by the duplicate guarded migration_)
- timestamps

> **Migration warning**: Two migrations share the number `2024_01_01_000011`. The second (`add_archiving_to_appointments_table`) uses `hasColumn()` guards so it runs safely after the first. Both must be handled when removing.

### DashboardController
- **File**: `app/Http/Controllers/DashboardController.php`
- **Responsibility**: Aggregates dashboard metrics. Contains **two appointment queries** that must be removed:
  - `$todayAppointmentsCount = Appointment::where('status','!=','archived')->whereDate('appointment_date', now()->toDateString())->count();`
  - `$upcomingAppointments = Appointment::where('status','!=','archived')->where('appointment_date','>=',now()->toDateString())->orderBy(...)->limit(5)->get();`
- Both feed `dashboard/index.blade.php`.

### EnsureRole / EnsureUserHasRole (middleware)
- `EnsureRole` — registered as `role:` alias in `app/Http/Kernel.php`. This is the **active** role guard. Routes use `role:admin` / `role:admin,cashier`.
- `EnsureUserHasRole` — **dead code**. Not registered in Kernel. Still contains a `'photographer' => redirect()->route('photographer.appointments.index')` branch — harmless but stale; `photographer.appointments.index` route does not exist.

## Data Flow — The Appointment Feature (before removal)

1. Admin clicks "Appointments" in sidebar → `GET /appointments` → `AppointmentController@index` (middleware `role:admin`)
2. `index()` calls `autoArchiveExpired()` — any past-date non-terminal appointments are silently archived
3. View `pages/appointments.blade.php` renders calendar + "Today's Schedule" + "Upcoming Schedule" from the collection, plus a JSON blob (`#appointments-data`) for Alpine modal CRUD
4. "New Appointment" opens a modal (Alpine) → `POST /appointments` → `store()` (validates `after_or_equal:today`)
5. Edit/Delete modals → `PATCH/DELETE /appointments/{id}` → `update()` / `destroy()` (soft archive)
6. Archive page → `GET /appointments/archive` → restore via `POST /appointments/{id}/restore`
7. `DashboardController@index` separately queries appointments for the dashboard card ("Appointments — Today") and "Upcoming Appointments" panel
8. The old full-page multi-step form (`pages/appointment-form.blade.php`) is **unreachable** — `create()` redirects to `appointments.index`; no route serves it.

## Non-Obvious Behaviors & Design Decisions

1. **Soft-delete-by-status**: "Delete" never hard-deletes. `status='archived'` + `archived_reason`. The `autoArchiveExpired()` private method silently archives past appointments on every page load. Removing the feature means deciding what to do with existing rows — hard-drop the table (safest per client request) or keep for records.
2. **Duplicate migration number `000011`**: Two migration files share the number. The second is guarded (`hasColumn`) so it's idempotent-ish. When removing, **do not** simply `php artisan migrate:rollback` in an attempt to undo appointment migrations — it will try both 000011 files and could misbehave. The clean approach is a new migration (e.g. `000022_drop_appointments_table.php`) or manual `DROP TABLE` — full removal of old migration files is risky if they've already run and live in the `migrations` table.
3. **Orphaned photographer code**: The `photographer` role and its routes/controllers were already removed (migration 000018), but three view files + one layout remain: `photographer/` dir, `layouts/photographer.blade.php`. They reference **nonexistent routes** (`photographer.appointments.*`) and would 500 if ever routed. They were never wired into `routes/web.php`. These are safe to delete as part of cleanup, but they are **not** the same feature as the admin appointments — they're stale remnants.
4. **`database/schema.sql` is stale**: It still declares `users.role ENUM('admin','cashier','photographer')` and contains **no appointments table at all** — meaning schema.sql predates/diverges from the migrations. Do not rely on it as source of truth for the appointments table; the migrations are authoritative. (If the client uses schema.sql to rebuild DBs, the role enum should be updated to `('admin','cashier')`.)
5. **`EnsureUserHasRole` is dead**: exists but unregistered. The live role middleware is `EnsureRole`.
6. **No appointment notification / relation coupling**: `Appointment` has no relationships (no `belongsTo` User, no `hasMany` Sale). The only coupling is the two queries in `DashboardController` and the sidebar link. Removal is low-risk in terms of foreign keys — **but** migration 000018 currently drops `assigned_to`/`seen_at` from appointments; if the appointments table is dropped first, that migration must be adjusted or it will fail on fresh installs.

## Appointment Removal Checklist (for Act Mode)

### 1. Remove backend code
- [ ] Delete `app/Http/Controllers/AppointmentController.php`
- [ ] Delete `app/Models/Appointment.php`

### 2. Remove routes (`routes/web.php`)
- [ ] Delete `use App\Http\Controllers\AppointmentController;` import
- [ ] Delete the entire `// Appointments — admin only` route group (7 routes: index, create, store, update, destroy, archive, restore)

### 3. Remove views
- [ ] Delete `resources/views/pages/appointments.blade.php`
- [ ] Delete `resources/views/pages/appointments-archive.blade.php`
- [ ] Delete `resources/views/pages/appointment-form.blade.php`
- [ ] Edit `resources/views/layouts/app.blade.php` — remove the "Services" sidebar block containing the `route('appointments.index')` link

### 4. Clean Dashboard
- [ ] Edit `app/Http/Controllers/DashboardController.php` — remove `$todayAppointmentsCount` and `$upcomingAppointments` queries (and their `compact(...)` entries)
- [ ] Edit `resources/views/dashboard/index.blade.php` — remove the "Appointments" stat card (the `<a href="{{ route('appointments.index') }}">` block) and the "Upcoming Appointments" panel (the `@forelse($upcomingAppointments ...)` block)

### 5. Handle the database (SAFEST approach)
- [ ] Add a **new migration** `database/migrations/2024_01_01_000022_drop_appointments_table.php` with `Schema::dropIfExists('appointments')` — do NOT delete the old appointment migrations, since they've likely already been recorded in the `migrations` table. Removing the old files without migrating would break `migrate:status`/fresh installs.
- [ ] Run `php artisan migrate` to apply the drop.
- (Optional, if client wants zero traces) Manually `DELETE FROM migrations WHERE migration LIKE '%appointment%'` and then delete the old migration files + drop the table — this is the "total removal" path but should only be done knowingly, as it diverges from normal Laravel migration history.
- [ ] Edit `database/migrations/2024_01_01_000018_remove_photographer_role.php` — the `up()` currently runs `Schema::table('appointments', ...)` to drop `assigned_to`/`seen_at`. If the appointments table no longer exists, wrap in `if (Schema::hasTable('appointments'))` to keep fresh installs working, or leave as-is if you go the "delete old migrations and drop table manually" path.
- [ ] Edit `database/schema.sql` — add `DROP TABLE IF EXISTS appointments;` (or omit) and update `users.role` enum to remove `photographer`.

### 6. Optional dead-code cleanup (not required for the removal, but recommended)
- [ ] Delete `resources/views/photographer/` (2 files) and `resources/views/layouts/photographer.blade.php` — orphaned remnants of the removed photographer role; they reference routes that don't exist
- [ ] Delete `app/Http/Middleware/EnsureUserHasRole.php` — dead code, unregistered; or edit it to remove the `photographer` branch
- [ ] Remove the `appointments_archive`/`is_archived` references in any remaining files (search for `appointment` / `Appointment` across `app/`, `resources/`, `routes/`, `database/`)

### 7. Verify
- [ ] `php artisan route:list` — no appointment routes remain
- [ ] `php artisan migrate:status` — drop migration applied
- [ ] Login as admin → sidebar shows no Appointments; dashboard renders without errors
- [ ] `php artisan view:clear` + hard refresh (or `npm run build` if Tailwind classes changed)

## Module Reference

| File | Purpose |
|------|---------|
| `routes/web.php` | All routes incl. the 7 appointment routes to remove |
| `app/Http/Controllers/AppointmentController.php` | Appointment CRUD + auto-archive logic |
| `app/Http/Controllers/DashboardController.php` | Dashboard metrics incl. 2 appointment queries |
| `app/Models/Appointment.php` | Appointment Eloquent model |
| `resources/views/pages/appointments.blade.php` | Main appointment page: calendar, list, create/edit/delete modals |
| `resources/views/pages/appointments-archive.blade.php` | Archive page: restore, view details |
| `resources/views/pages/appointment-form.blade.php` | Legacy 3-step form (unreachable) |
| `resources/views/layouts/app.blade.php` | Main layout; sidebar holds the "Appointments" nav link |
| `resources/views/dashboard/index.blade.php` | Dashboard; Appointments card + Upcoming panel |
| `database/migrations/2024_01_01_000009_create_appointments_table.php` | Creates appointments table |
| `database/migrations/2024_01_01_000011_add_archive_fields_to_appointments_table.php` | Adds archived_at/archived_reason |
| `database/migrations/2024_01_01_000011_add_archiving_to_appointments_table.php` | Duplicate-# guarded migration (is_archived) |
| `database/migrations/2024_01_01_000014_add_seen_at_to_appointments_table.php` | Adds seen_at (photographer bell) |
| `database/migrations/2024_01_01_000018_remove_photographer_role.php` | Drops assigned_to/seen_at — needs `hasTable` guard if appointments dropped |
| `database/schema.sql` | Stale manual schema (no appointments table; old role enum) |
| `app/Http/Middleware/EnsureRole.php` | Active role middleware (`role:` alias) |
| `app/Http/Middleware/EnsureUserHasRole.php` | Dead middleware (unregistered; photographer branch) |
| `resources/views/photographer/*` | Orphaned photographer role views (dead) |
| `resources/views/layouts/photographer.blade.php` | Orphaned photographer layout (dead) |

## Suggested Reading Order
1. `routes/web.php` — see the appointment route group and role guards
2. `app/Http/Controllers/AppointmentController.php` — the whole feature's brain (soft-delete + auto-archive)
3. `app/Http/Controllers/DashboardController.php` — the second live consumer of appointments
4. `resources/views/layouts/app.blade.php` — where the nav entry lives
5. `resources/views/dashboard/index.blade.php` — the dashboard widgets to remove
6. `database/migrations/2024_01_01_000018_remove_photographer_role.php` — the migration that must be guarded before dropping the appointments table

---

**Note:** I'm in **Explore Mode** — I can investigate and document, but I can't make the edits myself. The full report has been saved to `project_info__1.md` in the project root. To implement this removal, switch to **Act Mode** using the mode selector at the bottom of the chat. The checklist above is ready to execute, and all findings will carry over as context.