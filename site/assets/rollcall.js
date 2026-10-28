/* ============================================================================
   ROLLCALL front page: taking the roll.

   Vanilla, no framework, no network. Anything that moves is gated twice: the
   <head> script only adds .motion when the system has no reduced-motion
   request, and motionOn() checks again before anything starts. Without
   motion the register is already complete: every tick drawn, every hand up,
   every word in the DOM from the first paint. The roll call only changes
   opacity and strokes, so screen readers always get the full text.

   Sections: 1. setup  2. hero  3. the roll  4. reveals and chart
             5. terminal  6. tabs  7. copy buttons  8. say "here" (shh)
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
    els.forEach(function (el) {
      io.observe(el);
    });
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
    $all(".desk").forEach(function (d) { d.classList.remove("is-called"); d.classList.add("is-present"); });
    $all(".term.is-typing").forEach(function (t) { t.classList.remove("is-typing"); });
    $all("[data-type]").forEach(function (el) {
      if (el.dataset.full) { el.textContent = el.dataset.full; }
    });
    $all("[data-count]").forEach(function (el) { el.textContent = el.dataset.count; });
    var caller = document.querySelector("[data-caller]");
    if (caller) { caller.textContent = "everyone. All present."; }
  }

  if (reduce && reduce.addEventListener) {
    reduce.addEventListener("change", function (e) {
      if (e.matches) { finishEverything(); }
    });
  }

  /* ---------------------------------------------------------------- 2. hero */

  // Clicking the mark opens the book again: the register rows re-run.
  var heroArt = document.querySelector(".hero__art");
  var heroIcon = document.querySelector(".hero__icon");
  if (heroArt && heroIcon) {
    heroIcon.addEventListener("click", function () {
      if (!motionOn()) {
        say("The register is already filled in. Everyone's here.");
        return;
      }
      heroArt.classList.add("is-replay");
      void heroArt.offsetWidth; // restart the CSS animations
      heroArt.classList.remove("is-replay");
    });
  }

  /* ----------------------------------------------------------- 3. the roll */

  var classroom = document.querySelector("[data-roll]");
  var caller = document.querySelector("[data-caller]");
  var replayBtn = document.querySelector("[data-replay]");
  var desks = classroom ? $all(".desk", classroom) : [];
  var queue = [];
  var calling = false;
  var talliesDone = false;

  function setCaller(text) {
    if (!caller) { return; }
    caller.textContent = text;
    caller.classList.remove("is-new");
    void caller.offsetWidth;
    caller.classList.add("is-new");
  }

  function callNext() {
    if (!queue.length) {
      calling = false;
      if (desks.every(function (d) { return d.classList.contains("is-present"); })) {
        later(function () {
          setCaller("everyone. All present.");
          countTallies();
        }, 450);
      }
      return;
    }
    calling = true;
    var desk = queue.shift();
    var name = desk.querySelector(".desk__name").textContent;
    var back = desk.classList.contains("desk--back");
    desk.classList.add("is-called");
    setCaller(name + "…");
    later(function () {
      desk.classList.add("is-present");
      setCaller(name + ": " + (back ? "present! (from the back)" : "present"));
    }, back ? 380 : 220);
    later(function () {
      desk.classList.remove("is-called");
    }, back ? 900 : 520);
    later(callNext, back ? 700 : 330);
  }

  function enqueueRow(row) {
    $all(".desk", row).forEach(function (d) {
      if (!d.classList.contains("is-present") && queue.indexOf(d) === -1) { queue.push(d); }
    });
    if (!calling) { callNext(); }
  }

  function countTallies() {
    if (talliesDone) { return; }
    talliesDone = true;
    $all("[data-count]", classroom).forEach(function (el) {
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
    classroom.classList.add("roll-waiting");
    desks.forEach(function (d) { d.classList.remove("is-present", "is-called"); });
    $all("[data-count]", classroom).forEach(function (el) { el.textContent = "0"; });
    setCaller("the roll");
  }

  if (classroom && motionOn()) {
    startRoll();
    // Each row is called when it scrolls into view, front row first; a row
    // that arrives early waits its turn in the queue.
    onceVisible($all(".row", classroom), 0.45, enqueueRow);

    if (replayBtn) {
      replayBtn.addEventListener("click", function () {
        timers.forEach(window.clearTimeout);
        timers = [];
        startRoll();
        later(function () {
          $all(".row", classroom).forEach(enqueueRow);
        }, 250);
      });
    }
  }

  /* ---------------------------------------------- 4. reveals and the chart */

  if (motionOn()) {
    onceVisible($all(".reveal, .flow"), 0.2, function (el) { el.classList.add("is-in"); });
    onceVisible($all(".pupil"), 0.3, function (el) {
      var i = $all(".pupil").indexOf(el) % 4;
      later(function () { el.classList.add("is-in"); }, i * 70);
    });
  } else {
    $all(".reveal, .flow, .pupil").forEach(function (el) { el.classList.add("is-in"); });
  }

  /* ------------------------------------------------------------ 5. terminal */

  function typeTerm(term) {
    var lines = $all(".term__body > span", term);
    var t = 0;
    lines.forEach(function (line) {
      var typed = line.querySelector("[data-type]");
      if (typed) {
        var full = typed.textContent;
        typed.dataset.full = full;
        typed.textContent = "";
        // The command line itself is visible; only its letters arrive.
        for (var c = 1; c <= full.length; c++) {
          (function (n) {
            later(function () { typed.textContent = full.slice(0, n); }, t + n * 38);
          })(c);
        }
        t += full.length * 38 + 260;
      } else {
        (function (el, at) {
          later(function () { el.classList.add("is-shown"); }, at);
        })(line, t);
        t += 55;
      }
    });
    later(function () { term.classList.remove("is-typing"); }, t + 100);
  }

  if (motionOn()) {
    $all("[data-term]").forEach(function (term) { term.classList.add("is-typing"); });
    onceVisible($all("[data-term]"), 0.35, typeTerm);
  }

  /* ---------------------------------------------------------------- 6. tabs */

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

  /* -------------------------------------------------------- 7. copy buttons */

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

  $all(".copy-btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var text = btn.dataset.copy;
      if (!text && btn.dataset.copyFrom) {
        text = document.querySelector(btn.dataset.copyFrom).textContent;
      }
      var label = btn.querySelector(".copy-btn__label");
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

  /* -------------------------------------------------- 8. say "here" (shh) */

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
