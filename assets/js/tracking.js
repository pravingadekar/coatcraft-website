/*
 * GA4 + Meta Pixel loader for every page. Fill in the two IDs below to turn
 * tracking on; while they are empty nothing is loaded.
 * Lead / WhatsApp / call events are fired by lead-capture.js.
 */
(function () {
  var GA4_ID = "";        // e.g. "G-AB12CD34EF"  (GA4 > Admin > Data streams)
  var META_PIXEL_ID = ""; // e.g. "123456789012345" (Meta Events Manager)

  if (GA4_ID) {
    var ga = document.createElement("script");
    ga.async = true;
    ga.src = "https://www.googletagmanager.com/gtag/js?id=" + GA4_ID;
    document.head.appendChild(ga);
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag("js", new Date());
    window.gtag("config", GA4_ID);
  }

  if (META_PIXEL_ID) {
    /* Standard Meta Pixel bootstrap */
    !function (f, b, e, v, n, t, s) {
      if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
      if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = "2.0"; n.queue = [];
      t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
    }(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
    window.fbq("init", META_PIXEL_ID);
    window.fbq("track", "PageView");
  }
})();
