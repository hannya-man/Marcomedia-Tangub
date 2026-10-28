@echo off
rem Runs Marcomedia POS on this PC at http://localhost:8000, with the official PHP 8.4 in C:\php84.
rem (The packages need PHP 8.4.1 or newer: XAMPP's PHP 8.0 is too old, and Herd Lite's download is
rem stuck at 8.4.0. To update PHP, unzip the newest 8.4 from windows.php.net over C:\php84.)
rem XAMPP's MySQL must be running: it still holds the database. Close this window to stop the app.
rem --no-reload hands the server .env's settings: without it, APP_ENV=local makes Laravel read the
rem Vercel CLI's .env.local (only a Vercel token) instead of .env, and every page fails (no app key).
cd /d "%~dp0"
start "" http://localhost:8000
"C:\php84\php.exe" artisan serve --port=8000 --no-reload
