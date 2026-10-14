@echo off
rem Runs Marcomedia POS on this PC at http://localhost:8000, with Laravel Herd's PHP 8.4.
rem (Laravel 13 needs PHP 8.3 or newer; XAMPP's PHP 8.0 is too old, so XAMPP's Apache can't run it.)
rem XAMPP's MySQL must be running: it still holds the database. Close this window to stop the app.
rem --no-reload hands the server .env's settings: without it, APP_ENV=local makes Laravel read the
rem Vercel CLI's .env.local (only a Vercel token) instead of .env, and every page fails (no app key).
cd /d "%~dp0"
start "" http://localhost:8000
"%USERPROFILE%\.config\herd-lite\bin\php.exe" artisan serve --port=8000 --no-reload
