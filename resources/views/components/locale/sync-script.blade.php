@php($enabled = \App\Support\Locales::enabled())
@if (count($enabled) > 1)
<script data-locale-sync>
(function () {
    var enabled = @json($enabled);
    function readCookie() { var m = document.cookie.match(/(?:^|; )locale=([^;]*)/); return m ? decodeURIComponent(m[1]) : null; }
    var cookie = readCookie(), stored = null;
    try { stored = localStorage.getItem('locale'); } catch (e) {}
    if (stored && enabled.indexOf(stored) === -1) stored = null;
    if (cookie && enabled.indexOf(cookie) === -1) cookie = null;

    if (cookie) {
        // The server only changes the cookie deliberately, so it is the newer value.
        if (stored !== cookie) { try { localStorage.setItem('locale', cookie); } catch (e) {} }
        return;
    }
    if (!stored) return;
    // Cookie cleared but this device chose before: restore it once and reload.
    // With cookies blocked entirely the restore cannot stick; hide the chooser instead of looping.
    document.documentElement.setAttribute('data-locale-stored', stored);
    var guard = 'locale-restored';
    try { if (sessionStorage.getItem(guard)) return; sessionStorage.setItem(guard, '1'); } catch (e) { return; }
    document.cookie = 'locale=' + encodeURIComponent(stored) + '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
    if (readCookie() === stored) location.reload();
})();
</script>
<style>html[data-locale-stored] .lc-overlay{display:none!important}</style>
@endif
