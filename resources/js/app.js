import './bootstrap';

// NOTE: Alpine.js, Chart.js, and Lucide icons are intentionally loaded via
// CDN <script> tags directly in layouts/app.blade.php and auth/login.blade.php,
// not bundled here. Importing/starting Alpine a second time in this file
// would double-initialize it — that exact bug broke click handlers on the
// POS page earlier in this project, so don't add `import Alpine` here
// unless you also remove the CDN <script> tag for it in the layouts.
