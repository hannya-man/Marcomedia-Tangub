{{--
    The brand screen between the login page and the app, both ways. The page being left fades it in
    (window.authSplash.show) before its form is sent; the page arrived at starts with it fully shown
    ($arriving) and fades it away, by CSS alone. Both pages meet on the same screen, so the switch
    itself is never seen. Styles: .auth-splash in resources/css/app.css.
--}}
<div id="auth-splash" class="auth-splash{{ ($arriving ?? false) ? ' is-arriving' : '' }}" aria-hidden="true">
    <img src="{{ asset('images/marcomedia-logo-transparent.png') }}" alt="" class="auth-splash-logo">
    <div class="auth-splash-status" role="status">
        <span class="auth-splash-spinner"></span>
        <span class="auth-splash-text"></span>
    </div>
</div>
<script>
window.authSplash = {
    show(text) {
        const splash = document.getElementById('auth-splash');
        splash.querySelector('.auth-splash-text').textContent = text;
        splash.classList.remove('is-arriving');
        splash.classList.add('is-on');
    },
};
// Come back to this page with Back (from the browser's page cache): no splash left over it.
window.addEventListener('pageshow', (e) => {
    if (e.persisted) document.getElementById('auth-splash')?.classList.remove('is-on');
});
</script>
