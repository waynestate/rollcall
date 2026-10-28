/* ============================================================================
   ROLLCALL front page: taking the roll.

   Vanilla, no framework, no network. Anything that moves is gated twice: the
   <head> script only adds .motion when the system has no reduced-motion
   request, and motionOn() checks again before anything starts. Without
   motion the register is already filled in: every stamp down, every hand
   up, every word in the DOM from the first paint. The roll call only hides
   and shows stamps, so screen readers always get the whole register.

   Sections: 1. setup  2. title page  3. the roll  4. reveals and chart
             5. tabs   6. copy buttons  7. say "here" (shh)
   ========================================================================== */
(function () {
  "use strict";

  /* --------------------------------------------------------------- 1. setup */

  var root = document.documentElement;
  var reduce = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)") : null;
  var hasIO = "IntersectionObserver" in window;

  function motionOn() {
    return root.classList.contains("motion") && !(reduce && reduce.matches);
  }

  function $all(sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  }

  function onceVisible(els, threshold, fn) {
    if (!hasIO) {
      els.forEach(fn);
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          io.unobserve(entry.target);
          fn(entry.target);
        }
      });
    }, { threshold: threshold, rootMargin: "0px 0px -10% 0px" });
    els.forEach(function (el) { io.observe(el); });
  }

  // Timers we may need to cancel if someone turns reduced motion on mid-roll.
  var timers = [];
  function later(fn, ms) {
    timers.push(window.setTimeout(fn, ms));
  }

  function finishEverything() {
    timers.forEach(window.clearTimeout);
    timers = [];
    root.classList.remove("motion");
    $all(".roll-waiting").forEach(function (el) { el.classList.remove("roll-waiting"); });
    $all(".roll tr.is-called").forEach(function (tr) { tr.classList.remove("is-called"); });
    $all(".reveal, .flow, .feed, [data-chart], .classlist li").forEach(function (el) { el.classList.add("is-in"); });
    $all("[data-count]").forEach(function (el) { el.textContent = el.dataset.count; });
    if (callerEl) { callerEl.textContent = "everyone. All present."; }
  }

  if (reduce && reduce.addEventListener) {
    reduce.addEventListener("change", function (e) {
      if (e.matches) { finishEverything(); }
    });
  }

  /* ---------------------------------------------------------- 2. title page */

  // Pressing the bookplate takes today's sample roll again.
  var sheet = document.querySelector(".sheet");
  var bookplate = document.querySelector(".bookplate");
  if (sheet && bookplate) {
    bookplate.addEventListener("click", function () {
      if (!motionOn()) {
        say("The roll is already taken. Everyone answered.");
        return;
      }
      sheet.classList.add("is-replay");
      void sheet.offsetWidth; // restart the CSS animations
      sheet.classList.remove("is-replay");
      say("Settle down, class. Taking the roll again.");
    });
  }

  /* ----------------------------------------------------------- 3. the roll */

  var register = document.querySelector("[data-roll]");
  var callerEl = document.querySelector("[data-caller]");
  var replayBtn = document.querySelector("[data-replay]");
  var seats = register ? $all("tbody[data-seat]", register) : [];
  var pupils = register ? $all("tbody[data-seat] tr:not(.seat__label)", register) : [];
  var queue = [];
  var calling = false;
  var talliesDone = false;

  function setCaller(text) {
    if (!callerEl) { return; }
    callerEl.textContent = text;
    callerEl.classList.remove("is-new");
    void callerEl.offsetWidth;
    callerEl.classList.add("is-new");
  }

  function answerFor(tr) {
    if (tr.classList.contains("is-back")) { return "present! (from the back row)"; }
    if (tr.classList.contains("is-mumble")) { return "inaudible (no version in the tag)"; }
    if (tr.querySelector(".stamp--late")) { return "present (a little late)"; }
    return "present";
  }

  function callNext() {
    if (!queue.length) {
      calling = false;
      if (pupils.every(function (tr) { return tr.classList.contains("is-done"); })) {
        later(function () {
          setCaller("everyone. All present.");
          countTallies();
        }, 450);
      }
      return;
    }
    calling = true;
    var tr = queue.shift();
    var name = tr.querySelector("th[scope=row]").textContent;
    var slow = tr.classList.contains("is-back") || tr.classList.contains("is-mumble");
    tr.classList.add("is-called");
    setCaller(name + "…");
    later(function () {
      tr.classList.add("is-done");
      setCaller(name + ": " + answerFor(tr));
    }, slow ? 420 : 240);
    later(function () { tr.classList.remove("is-called"); }, slow ? 1000 : 600);
    later(callNext, slow ? 760 : 380);
  }

  function enqueueSeat(seat) {
    $all("tr:not(.seat__label)", seat).forEach(function (tr) {
      if (!tr.classList.contains("is-done") && queue.indexOf(tr) === -1) { queue.push(tr); }
    });
    if (!calling) { callNext(); }
  }

  function countTallies() {
    if (talliesDone) { return; }
    talliesDone = true;
    $all("[data-count]", register).forEach(function (el) {
      var target = parseInt(el.dataset.count, 10);
      var start = performance.now();
      var dur = 700;
      (function step(now) {
        var t = Math.min(1, (now - start) / dur);
        el.textContent = String(Math.round(target * (1 - Math.pow(1 - t, 3))));
        if (t < 1 && motionOn()) {
          window.requestAnimationFrame(step);
        } else {
          el.textContent = String(target);
        }
      })(start);
    });
  }

  function startRoll() {
    queue = [];
    calling = false;
    talliesDone = false;
    register.classList.add("roll-waiting");
    pupils.forEach(function (tr) { tr.classList.remove("is-done", "is-called"); });
    $all("[data-count]", register).forEach(function (el) { el.textContent = "0"; });
    setCaller("the roll");
  }

  if (register && motionOn()) {
    startRoll();
    // Each row of seats is called when it scrolls into view, front row
    // first; a row that arrives early waits its turn.
    onceVisible(seats, 0.3, enqueueSeat);

    if (replayBtn) {
      replayBtn.addEventListener("click", function () {
        timers.forEach(window.clearTimeout);
        timers = [];
        startRoll();
        later(function () { seats.forEach(enqueueSeat); }, 250);
      });
    }
  }

  /* ---------------------------------------------- 4. reveals and the chart */

  var listItems = $all(".classlist li");
  if (motionOn()) {
    onceVisible($all(".reveal, .flow, .feed, [data-chart]"), 0.2, function (el) { el.classList.add("is-in"); });
    onceVisible(listItems, 0.4, function (el) {
      var i = listItems.indexOf(el) % 2;
      later(function () { el.classList.add("is-in"); }, i * 120);
    });
  } else {
    $all(".reveal, .flow, .feed, [data-chart]").forEach(function (el) { el.classList.add("is-in"); });
    listItems.forEach(function (el) { el.classList.add("is-in"); });
  }

  /* ---------------------------------------------------------------- 5. tabs */

  var tabs = $all('[role="tab"]');
  function selectTab(tab, focus) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute("aria-selected", on ? "true" : "false");
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute("aria-controls")).hidden = !on;
    });
    if (focus) { tab.focus(); }
  }
  tabs.forEach(function (tab, i) {
    tab.addEventListener("click", function () { selectTab(tab, false); });
    tab.addEventListener("keydown", function (e) {
      var next = null;
      if (e.key === "ArrowRight") { next = tabs[(i + 1) % tabs.length]; }
      if (e.key === "ArrowLeft") { next = tabs[(i - 1 + tabs.length) % tabs.length]; }
      if (e.key === "Home") { next = tabs[0]; }
      if (e.key === "End") { next = tabs[tabs.length - 1]; }
      if (next) { e.preventDefault(); selectTab(next, true); }
    });
  });
  // Windows visitors see their own shell first.
  if (/Win/.test(navigator.platform || navigator.userAgent) && tabs[1]) {
    selectTab(tabs[1], false);
  }

  /* -------------------------------------------------------- 6. copy buttons */

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement("textarea");
      ta.value = text;
      ta.setAttribute("readonly", "");
      ta.style.position = "fixed";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand("copy") ? resolve() : reject(); } catch (err) { reject(err); }
      document.body.removeChild(ta);
    });
  }

  $all(".copy").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var text = btn.dataset.copy;
      if (!text && btn.dataset.copyFrom) {
        text = document.querySelector(btn.dataset.copyFrom).textContent;
      }
      var label = btn.querySelector(".copy__label");
      copyText(text).then(function () {
        btn.classList.add("is-copied");
        label.textContent = "Present!";
        say("Copied. Marked present.");
      }, function () {
        label.textContent = "Select it";
      });
      window.setTimeout(function () {
        btn.classList.remove("is-copied");
        label.textContent = "Copy";
      }, 1800);
    });
  });

  /* -------------------------------------------------- 7. say "here" (shh) */

  var toast = document.querySelector(".toast");
  var toastTimer = 0;
  function say(text) {
    if (!toast) { return; }
    toast.textContent = "";
    window.clearTimeout(toastTimer);
    window.setTimeout(function () { toast.textContent = text; }, 30);
    toastTimer = window.setTimeout(function () { toast.textContent = ""; }, 3200);
  }

  // Type "here" or "present" anywhere outside a field and the register
  // answers. Nobody is told about this, which is half the fun.
  var typed = "";
  document.addEventListener("keydown", function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) { return; }
    var tag = (e.target && e.target.tagName) || "";
    if (/INPUT|TEXTAREA|SELECT/.test(tag)) { return; }
    typed = (typed + e.key.toLowerCase()).slice(-7);
    if (/here$/.test(typed) || /present$/.test(typed)) {
      typed = "";
      say("Noted. You're marked present. Pull up a chair in the front row.");
    }
  });
})();
