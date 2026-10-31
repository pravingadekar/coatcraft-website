/*
 * Lead capture for every enquiry form on the site.
 *
 * Any <form data-lead-form> is posted to the enquiry dashboard's sendmail.php,
 * so the lead is saved (and synced to the CRM) before anything else happens.
 * Also remembers where the visitor came from (UTM params, landing page,
 * referrer) and fires GA4 / Meta Pixel lead events when those are installed.
 *
 * Form options (data attributes):
 *   data-lead-form           — opt the form in
 *   data-lead-whatsapp="1"   — also open WhatsApp with the enquiry text
 *                              (homepage quote form keeps its old behaviour)
 */
(function () {
  var ENDPOINT = "https://coatcraftcrm.workmanager.in/sendmail.php";
  var FORM_TOKEN = "0588b882776e64262fcafeca38af1743";
  var WHATSAPP_NUMBER = "917745889111";
  var STORAGE_KEY = "cc_lead_source";

  function track(event, params) {
    try {
      if (typeof window.gtag === "function") window.gtag("event", event, params || {});
      if (typeof window.fbq === "function") {
        window.fbq(event === "generate_lead" ? "track" : "trackCustom", event === "generate_lead" ? "Lead" : event, params || {});
      }
    } catch (e) {}
  }

  // First touch wins: keep the UTM/landing page from the visitor's first page
  // view this session, not whatever page they happen to submit from.
  function readSource() {
    var stored = null;
    try {
      stored = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || "null");
    } catch (e) {}
    if (stored) return stored;

    var params = new URLSearchParams(window.location.search);
    var source = {
      utm_source: params.get("utm_source") || "",
      utm_medium: params.get("utm_medium") || "",
      utm_campaign: params.get("utm_campaign") || "",
      landing_page: window.location.pathname,
      referrer: document.referrer && document.referrer.indexOf(window.location.host) === -1 ? document.referrer : ""
    };
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(source));
    } catch (e) {}
    return source;
  }

  function addHidden(form, name, value) {
    var input = form.querySelector('input[name="' + name + '"]');
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      form.appendChild(input);
    }
    input.value = value;
  }

  function addHoneypot(form) {
    if (form.querySelector('input[name="company_website"]')) return;
    var wrap = document.createElement("div");
    wrap.setAttribute("aria-hidden", "true");
    wrap.style.cssText = "position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;";
    wrap.innerHTML = '<input type="text" name="company_website" tabindex="-1" autocomplete="off">';
    form.appendChild(wrap);
  }

  function showStatus(form, ok, text) {
    var box = form.querySelector(".lead-form-status");
    if (!box) {
      box = document.createElement("div");
      box.className = "lead-form-status";
      box.setAttribute("role", "status");
      box.style.marginTop = "12px";
      form.appendChild(box);
    }
    box.style.color = ok ? "#1a7f37" : "#c62828";
    box.textContent = text;
  }

  function whatsappText(data) {
    return "Hello, I have a new enquiry from website:\n\n" +
      "Name: " + (data.get("name") || "") + "\n" +
      "Email: " + (data.get("email") || "") + "\n" +
      "Phone: " + (data.get("phone") || "") + "\n" +
      "Message: " + (data.get("message") || "");
  }

  function setup(form) {
    var source = readSource();
    addHidden(form, "form_token", FORM_TOKEN);
    addHidden(form, "utm_source", source.utm_source);
    addHidden(form, "utm_medium", source.utm_medium);
    addHidden(form, "utm_campaign", source.utm_campaign);
    addHidden(form, "landing_page", source.landing_page);
    addHidden(form, "referrer", source.referrer);
    addHidden(form, "form_started_at", String(Date.now()));
    addHoneypot(form);

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (!form.reportValidity()) return;

      var data = new FormData(form);
      // sendmail.php has no subject column — fold it into the message.
      var subject = (data.get("subject") || "").trim();
      if (subject) {
        data.set("message", "Subject: " + subject + "\n" + (data.get("message") || ""));
        data.delete("subject");
      }

      var button = form.querySelector('[type="submit"]');
      if (button) button.disabled = true;

      // Opened synchronously so popup blockers allow it; the lead is still
      // saved by the request below (keepalive survives the tab switch).
      if (form.getAttribute("data-lead-whatsapp") === "1") {
        window.open("https://wa.me/" + WHATSAPP_NUMBER + "?text=" + encodeURIComponent(whatsappText(data)), "_blank");
      }

      fetch(ENDPOINT, { method: "POST", body: data, keepalive: true })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success) {
            showStatus(form, true, "Thank you! Our flooring expert will contact you shortly.");
            track("generate_lead", { form_id: form.id || "lead_form", page: window.location.pathname });
            form.reset();
            addHidden(form, "form_started_at", String(Date.now()));
          } else {
            showStatus(form, false, (json && json.message) || "Something went wrong. Please call +91 77458 89111.");
          }
        })
        .catch(function () {
          showStatus(form, false, "Could not send right now. Please call or WhatsApp +91 77458 89111.");
        })
        .then(function () {
          if (button) button.disabled = false;
        });
    });
  }

  function trackClicks() {
    document.addEventListener("click", function (e) {
      var link = e.target.closest ? e.target.closest("a[href]") : null;
      if (!link) return;
      var href = link.getAttribute("href") || "";
      if (href.indexOf("wa.me") !== -1 || href.indexOf("whatsapp") !== -1) {
        track("whatsapp_click", { page: window.location.pathname });
      } else if (href.indexOf("tel:") === 0) {
        track("phone_click", { page: window.location.pathname });
      } else if (href.indexOf("enquiry.html") !== -1) {
        track("enquiry_form_click", { page: window.location.pathname });
      }
    });
  }

  function init() {
    readSource();
    var forms = document.querySelectorAll("form[data-lead-form]");
    for (var i = 0; i < forms.length; i++) setup(forms[i]);
    trackClicks();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
