/**
 * Meyvora SEO – Meta box: tabs, character counters, live snippet preview, AJAX analysis & autosave.
 *
 * @package Meyvora_SEO
 */

(function () {
  "use strict";

  var config = typeof meyvoraSeo !== "undefined" ? meyvoraSeo : {};
  var ajaxUrl = config.ajaxUrl || "";
  var nonce = config.nonce || "";
  var postId = config.postId || 0;
  var titleMin = config.titleMin || 30;
  var titleMax = config.titleMax || 60;
  var descMin = config.descMin || 120;
  var descMax = config.descMax || 160;
  var i18n = config.i18n || {};

  function $id(id) {
    return document.getElementById(id);
  }

  function charCount(str) {
    return str ? str.length : 0;
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var a = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(null, a);
      }, ms);
    };
  }

  function switchTab(tabId) {
    var panel = document.getElementById("meyvora-tab-" + tabId);
    var tabs = document.querySelectorAll(".meyvora-seo-tab");
    var panels = document.querySelectorAll(".meyvora-seo-tabpanel");
    if (!panel) return;
    tabs.forEach(function (tab) {
      var isActive = tab.getAttribute("data-tab") === tabId;
      tab.classList.toggle("is-active", isActive);
      tab.setAttribute("aria-selected", isActive ? "true" : "false");
      tab.setAttribute("tabindex", isActive ? "0" : "-1");
    });
    panels.forEach(function (p) {
      var isActive = p.id === "meyvora-tab-" + tabId;
      p.classList.toggle("is-active", isActive);
      p.hidden = !isActive;
      p.setAttribute("tabindex", isActive ? "0" : "-1");
    });
    if (tabId === "schema") {
      updateSchemaTypeVisibility();
    }
    var activePanel = document.getElementById("meyvora-tab-" + tabId);
    if (activePanel) {
      activePanel.focus();
    }
  }

  function updateSchemaTypeVisibility() {
    var sel = document.getElementById("meyvora_seo_schema_type");
    var type = sel ? sel.value : "";
    document.querySelectorAll(".mev-schema-fields").forEach(function (block) {
      block.style.display =
        block.getAttribute("data-schema-type") === type ? "block" : "none";
    });
  }

  function updateTitleCounter() {
    var titleEl = document.getElementById("meyvora_seo_title");
    var postTitle = document.querySelector("#title")
      ? document.querySelector("#title").value
      : "";
    var title = titleEl && titleEl.value ? titleEl.value : postTitle;
    var len = charCount(title);
    var counter = $id("meyvora_title_counter");
    var bar = $id("meyvora_title_bar");
    if (!counter || !bar) return;
    counter.textContent = (i18n.charCount || "%d / %d characters")
      .replace("%d", len)
      .replace("%d", titleMax);
    counter.className =
      "meyvora-char-counter" +
      (len >= titleMin && len <= titleMax
        ? " is-good"
        : len > 0
          ? " is-warn"
          : " is-bad");
    bar.style.width = Math.min(100, (len / titleMax) * 100) + "%";
    bar.style.backgroundColor =
      len >= titleMin && len <= titleMax
        ? "#00B67A"
        : len > 0
          ? "#F59E0B"
          : "#EF4444";
  }

  function updateDescCounter() {
    var descEl = document.getElementById("meyvora_seo_description");
    var len = charCount(descEl ? descEl.value : "");
    var counter = $id("meyvora_desc_counter");
    var bar = $id("meyvora_desc_bar");
    if (!counter || !bar) return;
    counter.textContent = (i18n.charCount || "%d / %d characters")
      .replace("%d", len)
      .replace("%d", descMax);
    counter.className =
      "meyvora-char-counter" +
      (len >= descMin && len <= descMax
        ? " is-good"
        : len > 0
          ? " is-warn"
          : " is-bad");
    bar.style.width = Math.min(100, (len / descMax) * 100) + "%";
    bar.style.backgroundColor =
      len >= descMin && len <= descMax
        ? "#00B67A"
        : len > 0
          ? "#F59E0B"
          : "#EF4444";
  }

  var ogTitleMax = 88;
  var ogDescMax = 200;

  function updateOgTitleCounter() {
    var el = document.getElementById("meyvora_seo_og_title");
    var len = charCount(el ? el.value : "");
    var counter = $id("meyvora_og_title_counter");
    var bar = $id("meyvora_og_title_bar");
    var previewCount = $id("mev-og-title-count");
    if (counter) counter.textContent = len + " / " + ogTitleMax;
    if (bar) {
      bar.style.width = Math.min(100, (len / ogTitleMax) * 100) + "%";
      bar.style.backgroundColor =
        len <= ogTitleMax
          ? len >= 40
            ? "#00B67A"
            : len > 0
              ? "#F59E0B"
              : "#EF4444"
          : "#EF4444";
    }
    if (previewCount) previewCount.textContent = len + " / " + ogTitleMax;
  }

  function updateOgDescCounter() {
    var el = document.getElementById("meyvora_seo_og_description");
    var len = charCount(el ? el.value : "");
    var counter = $id("meyvora_og_desc_counter");
    var bar = $id("meyvora_og_desc_bar");
    var previewCount = $id("mev-og-desc-count");
    if (counter) counter.textContent = len + " / " + ogDescMax;
    if (bar) {
      bar.style.width = Math.min(100, (len / ogDescMax) * 100) + "%";
      bar.style.backgroundColor =
        len <= ogDescMax
          ? len >= 100
            ? "#00B67A"
            : len > 0
              ? "#F59E0B"
              : "#EF4444"
          : "#EF4444";
    }
    if (previewCount) previewCount.textContent = len + " / " + ogDescMax;
  }

  function getOgImageUrl() {
    // Read from the hidden input (attachment ID → data-url attribute set by PHP),
    // NOT from the preview img.src which may be unrendered or resolve to a different URL.
    var wrap = document.querySelector(
      "#meyvora-tab-social .meyvora-media-picker-wrap"
    );
    if (!wrap) return "";
    // The hidden input stores the attachment ID; the wrap carries the resolved URL.
    var dataUrl = wrap.getAttribute("data-image-url") || "";
    if (dataUrl) return dataUrl;
    // Fallback: try the preview img (may be empty on first load)
    var img = wrap.querySelector(".meyvora-media-preview img");
    return img && img.getAttribute("src") ? img.getAttribute("src") : "";
  }

  function getTwitterImageUrl() {
    var socialTab = document.getElementById("meyvora-tab-social");
    if (!socialTab) return "";
    var wraps = socialTab.querySelectorAll(".meyvora-media-picker-wrap");
    if (wraps.length < 2) return "";
    var wrap = wraps[1];
    var dataUrl = wrap.getAttribute("data-image-url") || "";
    if (dataUrl) return dataUrl;
    var img = wrap.querySelector(".meyvora-media-preview img");
    return img && img.getAttribute("src") ? img.getAttribute("src") : "";
  }

  function updateSocialPreview() {
    var ogTitleEl = document.getElementById("meyvora_seo_og_title");
    var ogDescEl = document.getElementById("meyvora_seo_og_description");
    var twTitleEl = document.getElementById("meyvora_seo_twitter_title");
    var twDescEl = document.getElementById("meyvora_seo_twitter_description");
    var postTitleEl = document.querySelector("#title");
    var snippetContainer = document.querySelector(".meyvora-snippet-preview");
    var serpContainer = document.querySelector(".mev-serp-container");
    var defaultUrl =
      (snippetContainer && snippetContainer.getAttribute("data-url")) ||
      (serpContainer && serpContainer.getAttribute("data-initial-url")) ||
      "";
    var socialTab = document.getElementById("meyvora-tab-social");
    if (!defaultUrl && socialTab) defaultUrl = socialTab.getAttribute("data-snippet-url") || "";
    var canonicalEl = document.getElementById("meyvora_seo_canonical");
    var url =
      canonicalEl && canonicalEl.value.trim()
        ? canonicalEl.value.trim()
        : defaultUrl;
    var domain = "";
    if (url) {
      try {
        var a = document.createElement("a");
        a.href = url;
        domain = a.hostname ? a.hostname.replace(/^www\./, "") : "";
      } catch (e) {}
    }
    var postTitle = postTitleEl ? postTitleEl.value : "";
    var ogTitle = ogTitleEl && ogTitleEl.value ? ogTitleEl.value : postTitle;
    var ogDesc = ogDescEl && ogDescEl.value ? ogDescEl.value : "";
    var twTitle = twTitleEl && twTitleEl.value ? twTitleEl.value : ogTitle;
    var twDesc = twDescEl && twDescEl.value ? twDescEl.value : ogDesc;
    var ogImgUrl = getOgImageUrl();
    var twImgUrl = getTwitterImageUrl() || ogImgUrl;

    var fbDomain = $id("mev-preview-fb-domain");
    var fbTitle = $id("mev-preview-fb-title");
    var fbDesc = $id("mev-preview-fb-desc");
    var fbImage = $id("mev-preview-fb-img");
    var fbPlaceholder = $id("mev-preview-fb-placeholder");
    var twTitleNode = $id("mev-preview-tw-title");
    var twDescNode = $id("mev-preview-tw-desc");
    var twDomain = $id("mev-preview-tw-domain");
    var twImage = $id("mev-preview-tw-img");
    var twPlaceholder = $id("mev-preview-tw-placeholder");

    if (fbDomain) fbDomain.textContent = domain || "";
    if (fbTitle) fbTitle.textContent = ogTitle || "";
    if (fbDesc)
      fbDesc.textContent = (ogDesc || "").split(/\s+/).slice(0, 30).join(" ");
    if (fbImage && fbPlaceholder) {
      if (ogImgUrl) {
        fbImage.src = ogImgUrl;
        fbImage.style.display = "";
        fbPlaceholder.style.display = "none";
      } else {
        fbImage.style.display = "none";
        fbPlaceholder.style.display = "flex";
      }
    } else if (fbImage) {
      if (ogImgUrl) {
        fbImage.src = ogImgUrl;
        fbImage.style.display = "";
      } else {
        fbImage.style.display = "none";
      }
    } else if (fbPlaceholder) {
      fbPlaceholder.style.display = ogImgUrl ? "none" : "flex";
    }

    if (twTitleNode) twTitleNode.textContent = twTitle || "";
    if (twDescNode)
      twDescNode.textContent = (twDesc || "")
        .split(/\s+/)
        .slice(0, 30)
        .join(" ");
    if (twDomain) twDomain.textContent = domain || "";
    if (twImage && twPlaceholder) {
      if (twImgUrl) {
        twImage.src = twImgUrl;
        twImage.style.display = "";
        twPlaceholder.style.display = "none";
      } else {
        twImage.style.display = "none";
        twPlaceholder.style.display = "flex";
      }
    } else if (twImage) {
      if (twImgUrl) {
        twImage.src = twImgUrl;
        twImage.style.display = "";
      } else {
        twImage.style.display = "none";
      }
    } else if (twPlaceholder) {
      twPlaceholder.style.display = twImgUrl ? "none" : "flex";
    }

    // Social tab inline preview cards (vanilla JS, same data)
    var fbPreviewDomain = document.getElementById("meyvora-fb-preview-domain");
    var fbPreviewTitle = document.getElementById("meyvora-fb-preview-title");
    var fbPreviewDesc = document.getElementById("meyvora-fb-preview-desc");
    var fbPreviewImg = document.getElementById("meyvora-fb-preview-img");
    var fbPreviewPlace = document.getElementById("meyvora-fb-preview-placeholder");
    var twPreviewTitle = document.getElementById("meyvora-tw-preview-title");
    var twPreviewDesc = document.getElementById("meyvora-tw-preview-desc");
    var twPreviewDomain = document.getElementById("meyvora-tw-preview-domain");
    var twPreviewImg = document.getElementById("meyvora-tw-preview-img");
    var twPreviewPlace = document.getElementById("meyvora-tw-preview-placeholder");
    if (fbPreviewDomain) fbPreviewDomain.textContent = domain || "";
    if (fbPreviewTitle) fbPreviewTitle.textContent = ogTitle || "";
    if (fbPreviewDesc) fbPreviewDesc.textContent = (ogDesc || "").split(/\s+/).slice(0, 30).join(" ");
    if (fbPreviewImg && fbPreviewPlace) {
      if (ogImgUrl) {
        fbPreviewImg.src = ogImgUrl;
        fbPreviewImg.style.display = "";
        fbPreviewPlace.style.display = "none";
      } else {
        fbPreviewImg.style.display = "none";
        fbPreviewPlace.style.display = "";
      }
    } else if (fbPreviewImg) {
      if (ogImgUrl) { fbPreviewImg.src = ogImgUrl; fbPreviewImg.style.display = ""; } else { fbPreviewImg.style.display = "none"; }
    } else if (fbPreviewPlace) { fbPreviewPlace.style.display = ogImgUrl ? "none" : ""; }
    if (twPreviewTitle) twPreviewTitle.textContent = twTitle || "";
    if (twPreviewDesc) twPreviewDesc.textContent = (twDesc || "").split(/\s+/).slice(0, 30).join(" ");
    if (twPreviewDomain) twPreviewDomain.textContent = domain || "";
    if (twPreviewImg && twPreviewPlace) {
      if (twImgUrl) {
        twPreviewImg.src = twImgUrl;
        twPreviewImg.style.display = "";
        twPreviewPlace.style.display = "none";
      } else {
        twPreviewImg.style.display = "none";
        twPreviewPlace.style.display = "";
      }
    } else if (twPreviewImg) {
      if (twImgUrl) { twPreviewImg.src = twImgUrl; twPreviewImg.style.display = ""; } else { twPreviewImg.style.display = "none"; }
    } else if (twPreviewPlace) { twPreviewPlace.style.display = twImgUrl ? "none" : ""; }
  }

  function switchSocialSubtab(subtabId) {
    document
      .querySelectorAll(".mev-social-preview-subtab")
      .forEach(function (t) {
        t.classList.toggle(
          "is-active",
          t.getAttribute("data-subtab") === subtabId
        );
        t.setAttribute(
          "aria-selected",
          t.getAttribute("data-subtab") === subtabId ? "true" : "false"
        );
      });
    document.querySelectorAll(".mev-social-subpanel").forEach(function (p) {
      var isActive = p.id === "mev-social-subpanel-" + subtabId;
      p.classList.toggle("is-active", isActive);
      p.hidden = !isActive;
    });
  }

  function initMediaPickers() {
    if (typeof wp === "undefined" || !wp.media) return;
    var socialTab = document.getElementById("meyvora-tab-social");
    if (!socialTab) return;
    var ogPicker = socialTab.querySelector(".meyvora-og-image-picker");
    var twPicker = socialTab.querySelector(".meyvora-twitter-image-picker");
    var ogInput = document.getElementById("meyvora_seo_og_image");
    var twInput = document.getElementById("meyvora_seo_twitter_image");
    function openFrame(hiddenInput, previewWrap, onSelect) {
      var frame = wp.media({
        library: { type: "image" },
        multiple: false,
      });
      frame.on("select", function () {
        var att = frame.state().get("selection").first().toJSON();
        if (att && att.id) {
          hiddenInput.value = att.id;
          var preview = previewWrap.querySelector(".meyvora-media-preview");
          if (preview) {
            var url =
              att.sizes && att.sizes.medium
                ? att.sizes.medium.url
                : att.url || "";
            if (!url) url = att.url || "";
            var img = preview.querySelector("img");
            if (img) img.src = url;
            else {
              img = document.createElement("img");
              img.src = url;
              img.alt = "";
              preview.innerHTML = "";
              preview.appendChild(img);
            }
          }
          if (onSelect)
            onSelect(
              att.url ||
                (att.sizes && att.sizes.medium ? att.sizes.medium.url : "")
            );
        }
      });
      frame.open();
    }
    if (ogPicker && ogInput) {
      var ogWrap = ogPicker.closest(".meyvora-media-picker-wrap");
      ogPicker.addEventListener("click", function () {
        openFrame(ogInput, ogWrap, function () {
          updateSocialPreview();
        });
      });
    }
    if (twPicker && twInput) {
      var twWrap = twPicker.closest(".meyvora-media-picker-wrap");
      twPicker.addEventListener("click", function () {
        openFrame(twInput, twWrap, function () {
          updateSocialPreview();
        });
      });
    }
  }

  function updateSnippetPreview() {
    var titleEl = document.getElementById("meyvora_seo_title");
    var descEl = document.getElementById("meyvora_seo_description");
    var canonicalEl = document.getElementById("meyvora_seo_canonical");
    var postTitleEl = document.querySelector("#title");
    var snippetContainer = document.querySelector(".meyvora-snippet-preview");
    var defaultUrl = snippetContainer
      ? snippetContainer.getAttribute("data-url")
      : "";
    var url =
      canonicalEl && canonicalEl.value.trim()
        ? canonicalEl.value.trim()
        : defaultUrl;
    var title =
      titleEl && titleEl.value
        ? titleEl.value
        : postTitleEl
          ? postTitleEl.value
          : "";
    var desc = descEl && descEl.value ? descEl.value : "";
    var titleNode = $id("meyvora_snippet_title");
    var descNode = $id("meyvora_snippet_desc");
    var urlNode = $id("meyvora_snippet_url");
    if (titleNode) titleNode.textContent = title || "";
    if (descNode) descNode.textContent = desc || "";
    if (urlNode) urlNode.textContent = url || "";
  }

  function getFocusKeywordsValue() {
    var hidden = document.getElementById("meyvora_seo_focus_keyword");
    return hidden ? hidden.value : "";
  }

  function syncFocusKeywordsHidden(pills) {
    var hidden = document.getElementById("meyvora_seo_focus_keyword");
    if (!hidden) return;
    hidden.value = JSON.stringify(pills);
  }

  function initFocusKeywordsTags() {
    var container = document.getElementById("meyvora_focus_keywords_tags");
    var input = document.getElementById("meyvora_seo_focus_keyword_input");
    var hidden = document.getElementById("meyvora_seo_focus_keyword");
    if (!container || !input || !hidden) return;
    var max = parseInt(container.getAttribute("data-max"), 10) || 5;

    function getPills() {
      var pills = [];
      container.querySelectorAll(".mev-focus-pill").forEach(function (el) {
        var kw = el.getAttribute("data-keyword");
        if (kw) pills.push(kw);
      });
      return pills;
    }

    function addPill(keyword) {
      keyword = keyword.trim();
      if (!keyword) return;
      var pills = getPills();
      if (pills.indexOf(keyword) !== -1) return;
      if (pills.length >= max) return;
      var span = document.createElement("span");
      span.className = "mev-focus-pill";
      span.setAttribute("data-keyword", keyword);
      span.textContent = keyword + " ";
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "mev-focus-pill-remove";
      btn.setAttribute("aria-label", "Remove");
      btn.innerHTML = "&times;";
      btn.addEventListener("click", function () {
        span.remove();
        syncFocusKeywordsHidden(getPills());
        debounce(autosave, 300)();
        debounce(runAnalysis, 400)();
      });
      span.appendChild(btn);
      container.insertBefore(span, input);
      syncFocusKeywordsHidden(getPills());
    }

    function onInputCommit() {
      var raw = input.value.trim();
      input.value = "";
      if (raw.indexOf(",") !== -1) {
        raw.split(",").forEach(function (k) {
          addPill(k);
        });
      } else {
        addPill(raw);
      }
      autosave();
      runAnalysis();
    }

    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === ",") {
        e.preventDefault();
        onInputCommit();
      }
      if (
        e.key === "Backspace" &&
        input.value === "" &&
        getPills().length > 0
      ) {
        var last = container.querySelector(".mev-focus-pill:last-of-type");
        if (last) last.remove();
        syncFocusKeywordsHidden(getPills());
      }
    });
    input.addEventListener("blur", function () {
      if (input.value.trim()) onInputCommit();
      debounce(autosave, 300)();
    });
    input.addEventListener(
      "input",
      debounce(function () {
        if (input.value.indexOf(",") !== -1) onInputCommit();
      }, 100)
    );
  }

  function runAnalysis() {
    if (!postId || !ajaxUrl || !nonce) return;
    var content = "";
    if (
      typeof wp !== "undefined" &&
      wp.data &&
      wp.data.select &&
      wp.data.select("core/editor")
    ) {
      content = wp.data.select("core/editor").getEditedPostContent();
    } else {
      var ce = document.getElementById("content");
      if (ce) content = ce.value || "";
    }
    var titleEl = document.getElementById("meyvora_seo_title");
    var descEl = document.getElementById("meyvora_seo_description");
    var title = titleEl ? titleEl.value : "";
    var desc = descEl ? descEl.value : "";
    var focusKeyword = getFocusKeywordsValue();
    var body =
      "action=meyvora_seo_analyze&nonce=" +
      encodeURIComponent(nonce) +
      "&post_id=" +
      postId +
      "&content=" +
      encodeURIComponent(content);
    if (title) body += "&title=" + encodeURIComponent(title);
    if (desc) body += "&description=" + encodeURIComponent(desc);
    if (focusKeyword)
      body += "&focus_keyword=" + encodeURIComponent(focusKeyword);
    var xhr = new XMLHttpRequest();
    xhr.open("POST", ajaxUrl);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onload = function () {
      var res;
      try {
        res = JSON.parse(xhr.responseText);
      } catch (e) {
        return;
      }
      if (res.success && res.data) {
        updateScoreUI(res.data);
      }
    };
    xhr.send(body);
  }

  function updateScoreUI(data) {
    var score = data.score != null ? data.score : 0;
    var status =
      data.status || (score >= 80 ? "good" : score >= 50 ? "okay" : "poor");
    var maxScore = data.max_score || 100;
    var results = data.results || [];
    var gaugeVal = $id("meyvora_gauge_value");
    var gaugeFill = $id("meyvora_gauge_fill");
    var badge = $id("meyvora_score_badge");
    if (gaugeVal) gaugeVal.textContent = score;
    if (gaugeFill) {
      var circumference = 232.5;
      if (gaugeFill.getAttribute("stroke-dasharray")) {
        circumference =
          parseFloat(gaugeFill.getAttribute("stroke-dasharray"), 10) ||
          circumference;
      }
      gaugeFill.style.strokeDashoffset =
        circumference - (score / maxScore) * circumference;
      gaugeFill.setAttribute(
        "class",
        "mev-gauge-fill mev-gauge-fill--" + status
      );
    }
    if (badge) {
      badge.textContent =
        status === "good"
          ? i18n.great || "Great!"
          : status === "okay"
            ? i18n.almostThere || "Almost There"
            : i18n.needsWork || "Needs Work";
      badge.className = "mev-score-status-label";
    }
  }

  function collectFormData() {
    var form = document.querySelector(".meyvora-seo-panel");
    if (!form) return {};
    var data = {};
    var inputs = form.querySelectorAll("input, textarea, select");
    inputs.forEach(function (el) {
      var name = el.getAttribute("name");
      if (!name) return;
      if (el.type === "checkbox") {
        data[name] = el.checked ? "1" : "";
      } else {
        data[name] = el.value || "";
      }
    });
    return data;
  }

  function autosave() {
    if (!postId || !ajaxUrl || !nonce) return;
    var data = collectFormData();
    var xhr = new XMLHttpRequest();
    xhr.open("POST", ajaxUrl);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onload = function () {
      var res;
      try {
        res = JSON.parse(xhr.responseText);
      } catch (e) {
        return;
      }
      if (res.success && typeof mevShowToast === "function") {
        mevShowToast(i18n.saved || "SEO data saved", "success");
      }
    };
    var body =
      "action=meyvora_seo_autosave&nonce=" +
      encodeURIComponent(nonce) +
      "&post_id=" +
      postId +
      "&data=" +
      encodeURIComponent(JSON.stringify(data));
    xhr.send(body);
  }

  function init() {
    var panel = document.querySelector(".meyvora-seo-panel");
    if (!panel) return;

    // Tabs
    panel.querySelectorAll(".meyvora-seo-tab").forEach(function (btn) {
      btn.addEventListener("click", function () {
        switchTab(btn.getAttribute("data-tab"));
      });
      btn.addEventListener("keydown", function (e) {
        var tabs = Array.from(panel.querySelectorAll(".meyvora-seo-tab"));
        var idx = tabs.indexOf(e.currentTarget);
        if (e.key === "ArrowRight" && idx < tabs.length - 1) {
          e.preventDefault();
          tabs[idx + 1].focus();
          tabs[idx + 1].click();
        } else if (e.key === "ArrowLeft" && idx > 0) {
          e.preventDefault();
          tabs[idx - 1].focus();
          tabs[idx - 1].click();
        }
      });
    });

    // Title / description counters and snippet
    var titleEl = document.getElementById("meyvora_seo_title");
    var descEl = document.getElementById("meyvora_seo_description");
    if (titleEl) {
      titleEl.addEventListener("input", function () {
        updateTitleCounter();
        updateSnippetPreview();
      });
      titleEl.addEventListener("blur", debounce(autosave, 300));
    }
    if (descEl) {
      descEl.addEventListener("input", function () {
        updateDescCounter();
        updateSnippetPreview();
      });
      descEl.addEventListener("blur", debounce(autosave, 300));
    }
    updateTitleCounter();
    updateDescCounter();

    // A/B Test: Start button (set hidden and submit form)
    var abStartBtn = document.getElementById("meyvora_seo_ab_start_btn");
    var abStartNow = document.getElementById("meyvora_seo_ab_start_now");
    if (abStartBtn && abStartNow) {
      abStartBtn.addEventListener("click", function () {
        var va = document.getElementById("meyvora_seo_desc_variant_a");
        var vb = document.getElementById("meyvora_seo_desc_variant_b");
        if (va && vb && (!va.value.trim() || !vb.value.trim())) {
          if (typeof mevShowToast === "function") mevShowToast(i18n.abFillBoth || "Please fill both Variant A and Variant B.", "default");
          return;
        }
        abStartNow.value = "1";
        var form = document.getElementById("post");
        if (form) form.submit();
      });
    }

    // A/B Test: Switch variant (AJAX)
    document.querySelectorAll(".mev-ab-switch-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var variant = btn.getAttribute("data-variant");
        if (!variant || !postId || !ajaxUrl || !nonce) return;
        btn.disabled = true;
        var fd = new FormData();
        fd.append("action", "meyvora_seo_ab_switch");
        fd.append("nonce", nonce);
        fd.append("post_id", postId);
        fd.append("variant", variant);
        var xhr = new XMLHttpRequest();
        xhr.open("POST", ajaxUrl);
        xhr.onload = function () {
          btn.disabled = false;
          try {
            var res = JSON.parse(xhr.responseText);
            if (res.success && res.data && res.data.variant) {
              if (typeof mevShowToast === "function") mevShowToast(i18n.saved || "Saved.", "default");
              window.location.reload();
            } else {
              if (typeof mevShowToast === "function") mevShowToast(res.data && res.data.message ? res.data.message : (i18n.error || "Error"), "default");
            }
          } catch (e) {
            if (typeof mevShowToast === "function") mevShowToast(i18n.error || "Error", "default");
          }
        };
        xhr.onerror = function () { btn.disabled = false; if (typeof mevShowToast === "function") mevShowToast(i18n.error || "Error", "default"); };
        xhr.send(fd);
      });
    });

    // Focus keywords (tag input)
    initFocusKeywordsTags();

    // Snippet desktop/mobile toggle
    panel.querySelectorAll(".meyvora-snippet-mode").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var mode = btn.getAttribute("data-mode");
        var preview = $id("meyvora_snippet_preview");
        panel.querySelectorAll(".meyvora-snippet-mode").forEach(function (b) {
          b.classList.remove("is-active");
        });
        btn.classList.add("is-active");
        if (preview) preview.classList.toggle("is-mobile", mode === "mobile");
      });
    });

    // OG title / description: counters + social preview
    var ogTitleEl = document.getElementById("meyvora_seo_og_title");
    var ogDescEl = document.getElementById("meyvora_seo_og_description");
    if (ogTitleEl) {
      ogTitleEl.addEventListener("input", function () {
        updateOgTitleCounter();
        updateSocialPreview();
      });
    }
    if (ogDescEl) {
      ogDescEl.addEventListener("input", function () {
        updateOgDescCounter();
        updateSocialPreview();
      });
    }
    updateOgTitleCounter();
    updateOgDescCounter();

    // Social Preview sub-tabs (Facebook / Twitter)
    panel
      .querySelectorAll(".mev-social-preview-subtab")
      .forEach(function (btn) {
        btn.addEventListener("click", function () {
          switchSocialSubtab(btn.getAttribute("data-subtab"));
        });
      });

    // Twitter fields -> social preview
    ["meyvora_seo_twitter_title", "meyvora_seo_twitter_description"].forEach(
      function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener("input", updateSocialPreview);
      }
    );

    // Media pickers for OG and Twitter image
    initMediaPickers();
    updateSocialPreview();

    // Schema tab: show/hide fields by type
    var schemaTypeEl = document.getElementById("meyvora_seo_schema_type");
    if (schemaTypeEl) {
      schemaTypeEl.addEventListener("change", updateSchemaTypeVisibility);
      updateSchemaTypeVisibility();
    }

    // Schema: Add step (HowTo)
    var addStepBtn = panel.querySelector(".mev-add-schema-step");
    if (addStepBtn) {
      addStepBtn.addEventListener("click", function () {
        var wrap = panel.querySelector(".mev-schema-steps-wrap");
        if (!wrap) return;
        var idx = wrap.querySelectorAll(".mev-schema-step").length;
        var div = document.createElement("div");
        div.className = "mev-schema-step";
        div.innerHTML =
          '<input type="text" name="meyvora_seo_schema_howto[steps][' +
          idx +
          '][name]" value="" placeholder="Step name" />' +
          '<textarea name="meyvora_seo_schema_howto[steps][' +
          idx +
          '][text]" rows="2" placeholder="Step text"></textarea>' +
          '<div class="meyvora-media-picker-wrap"><input type="hidden" name="meyvora_seo_schema_howto[steps][' +
          idx +
          '][image]" value="" class="mev-step-image-id" /><button type="button" class="button mev-picker-step-image">Image</button></div>';
        wrap.appendChild(div);
      });
    }

    // Schema: Add ingredient / instruction (Recipe)
    var addIngredientBtn = panel.querySelector(".mev-add-ingredient");
    if (addIngredientBtn) {
      addIngredientBtn.addEventListener("click", function () {
        var wrap = panel.querySelector(".mev-schema-ingredients-wrap");
        if (!wrap) return;
        var input = document.createElement("input");
        input.type = "text";
        input.name = "meyvora_seo_schema_recipe[ingredients][]";
        input.value = "";
        wrap.appendChild(input);
      });
    }
    var addInstructionBtn = panel.querySelector(".mev-add-instruction");
    if (addInstructionBtn) {
      addInstructionBtn.addEventListener("click", function () {
        var wrap = panel.querySelector(".mev-schema-instructions-wrap");
        if (!wrap) return;
        var ta = document.createElement("textarea");
        ta.name = "meyvora_seo_schema_recipe[instructions][]";
        ta.rows = 1;
        ta.value = "";
        wrap.appendChild(ta);
      });
    }

    // Schema FAQ: Add question
    var addFaqBtn = panel.querySelector(".mev-add-faq-pair");
    if (addFaqBtn) {
      addFaqBtn.addEventListener("click", function () {
        var wrap = panel.querySelector(".mev-schema-faq-wrap");
        if (!wrap) return;
        var idx = wrap.querySelectorAll(".mev-schema-faq-row").length;
        var div = document.createElement("div");
        div.className = "mev-schema-faq-row";
        div.innerHTML =
          '<input type="text" name="meyvora_seo_faq[' + idx + '][question]" value="" placeholder="' + (i18n.question || "Question") + '" />' +
          '<textarea name="meyvora_seo_faq[' + idx + '][answer]" rows="2" placeholder="' + (i18n.answer || "Answer") + '"></textarea>' +
          '<button type="button" class="button button-small mev-remove-faq-row" aria-label="' + (i18n.remove || "Remove") + '">×</button>';
        wrap.appendChild(div);
        panel.querySelectorAll(".mev-remove-faq-row").forEach(function (btn) {
          if (!btn._faqBound) {
            btn._faqBound = true;
            btn.addEventListener("click", function () {
              var row = btn.closest(".mev-schema-faq-row");
              if (row) row.remove();
            });
          }
        });
      });
    }

    // Schema FAQ: Remove row (delegate)
    panel.querySelectorAll(".mev-remove-faq-row").forEach(function (btn) {
      if (!btn._faqBound) {
        btn._faqBound = true;
        btn.addEventListener("click", function () {
          var row = btn.closest(".mev-schema-faq-row");
          if (row) row.remove();
        });
      }
    });

    // Schema FAQ: Generate with AI
    var generateFaqBtn = panel.querySelector(".mev-ai-generate-faq");
    if (generateFaqBtn) {
      var faqWrap = panel.querySelector(".mev-schema-faq-wrap");
      var faqSpinner = panel.querySelector(".mev-faq-spinner");
      generateFaqBtn.addEventListener("click", function () {
        if (generateFaqBtn.disabled) return;
        var postId = generateFaqBtn.getAttribute("data-post-id") || config.postId || "0";
        var focusKwEl = document.getElementById("meyvora_seo_focus_keyword");
        var focusKeyword = "";
        if (focusKwEl && focusKwEl.value) {
          try {
            var arr = JSON.parse(focusKwEl.value);
            if (Array.isArray(arr) && arr.length) focusKeyword = arr[0];
          } catch (e) {}
        }
        if (faqSpinner) faqSpinner.style.display = "inline-block";
        generateFaqBtn.disabled = true;
        var form = new FormData();
        form.append("action", "meyvora_seo_ai_request");
        form.append("nonce", config.aiNonce || "");
        form.append("action_type", "generate_faq");
        form.append("post_id", String(postId));
        if (focusKeyword) form.append("focus_keyword", focusKeyword);
        var xhr = new XMLHttpRequest();
        xhr.open("POST", ajaxUrl);
        xhr.onload = function () {
          if (faqSpinner) faqSpinner.style.display = "none";
          generateFaqBtn.disabled = false;
          try {
            var res = JSON.parse(xhr.responseText);
            if (res.success && res.data && Array.isArray(res.data.faq) && faqWrap) {
              faqWrap.innerHTML = "";
              res.data.faq.forEach(function (pair, i) {
                var div = document.createElement("div");
                div.className = "mev-schema-faq-row";
                var q = (pair && pair.question) ? String(pair.question) : "";
                var a = (pair && pair.answer) ? String(pair.answer) : "";
                var inp = document.createElement("input");
                inp.type = "text";
                inp.name = "meyvora_seo_faq[" + i + "][question]";
                inp.value = q;
                inp.placeholder = i18n.question || "Question";
                var ta = document.createElement("textarea");
                ta.name = "meyvora_seo_faq[" + i + "][answer]";
                ta.rows = 2;
                ta.placeholder = i18n.answer || "Answer";
                ta.textContent = a;
                var rm = document.createElement("button");
                rm.type = "button";
                rm.className = "button button-small mev-remove-faq-row";
                rm.setAttribute("aria-label", i18n.remove || "Remove");
                rm.textContent = "×";
                div.appendChild(inp);
                div.appendChild(ta);
                div.appendChild(rm);
                faqWrap.appendChild(div);
                if (!rm._faqBound) {
                  rm._faqBound = true;
                  rm.addEventListener("click", function () {
                    var row = rm.closest(".mev-schema-faq-row");
                    if (row) row.remove();
                  });
                }
              });
              panel.querySelectorAll(".mev-remove-faq-row").forEach(function (btn) {
                if (!btn._faqBound) {
                  btn._faqBound = true;
                  btn.addEventListener("click", function () {
                    var row = btn.closest(".mev-schema-faq-row");
                    if (row) row.remove();
                  });
                }
              });
            }
          } catch (e) {}
        };
        xhr.onerror = function () {
          if (faqSpinner) faqSpinner.style.display = "none";
          generateFaqBtn.disabled = false;
        };
        xhr.send(form);
      });
    }

    // Schema: Pre-fill with AI (delegate for all four schema types)
    panel.addEventListener("click", function (e) {
      if (!e.target || !e.target.classList.contains("mev-ai-prefill-schema")) return;
      e.preventDefault();
      var btn = e.target;
      if (btn.disabled) return;
      var schemaPanel = btn.closest(".mev-schema-fields");
      if (!schemaPanel) return;
      var schemaType = btn.getAttribute("data-schema-type");
      var postId = btn.getAttribute("data-post-id") || config.postId || "0";
      var replaceChk = schemaPanel.querySelector(".mev-ai-replace-schema");
      var replace = replaceChk ? replaceChk.checked : false;
      var spinner = schemaPanel.querySelector(".mev-schema-prefill-spinner");
      if (spinner) spinner.style.display = "inline-block";
      btn.disabled = true;
      var form = new FormData();
      form.append("action", "meyvora_seo_ai_request");
      form.append("nonce", config.aiNonce || "");
      form.append("action_type", "extract_schema_fields");
      form.append("schema_type", schemaType);
      form.append("post_id", String(postId));
      var xhr = new XMLHttpRequest();
      xhr.open("POST", ajaxUrl);
      xhr.onload = function () {
        if (spinner) spinner.style.display = "none";
        btn.disabled = false;
        try {
          var res = JSON.parse(xhr.responseText);
          if (res.success && res.data && res.data.fields && typeof res.data.fields === "object") {
            setSchemaFieldsFromAi(schemaPanel, schemaType, res.data.fields, replace);
          }
        } catch (err) {}
      };
      xhr.onerror = function () {
        if (spinner) spinner.style.display = "none";
        btn.disabled = false;
      };
      xhr.send(form);
    });

    function setSchemaFieldsFromAi(panelEl, schemaType, fields, replace) {
      function setVal(selectorOrName, value) {
        if (value === undefined || value === null) return;
        var str = String(value).trim();
        var el = typeof selectorOrName === "string" && selectorOrName.indexOf("[") !== -1
          ? panelEl.querySelector('[name="' + selectorOrName.replace(/"/g, '\\"') + '"]')
          : panelEl.querySelector(selectorOrName);
        if (!el) return;
        var current = (el.value || "").trim();
        if (!replace && current !== "") return;
        if (el.tagName === "TEXTAREA") el.value = str; else el.value = str;
      }
      if (schemaType === "HowTo") {
        setVal("meyvora_seo_schema_howto[name]", fields.name);
        setVal("meyvora_seo_schema_howto[totalTime]", fields.totalTime);
        setVal("meyvora_seo_schema_howto[estimatedCost]", fields.estimatedCost);
        setVal("meyvora_seo_schema_howto[description]", fields.description);
        var steps = Array.isArray(fields.steps) ? fields.steps : [];
        var wrap = panelEl.querySelector(".mev-schema-steps-wrap");
        if (wrap) {
          var existing = wrap.querySelectorAll(".mev-schema-step");
          var i;
          for (i = 0; i < steps.length; i++) {
            var step = steps[i];
            var nameVal = (step && step.name) ? String(step.name).trim() : "";
            var textVal = (step && step.text) ? String(step.text).trim() : "";
            if (i < existing.length) {
              var nameInp = existing[i].querySelector('input[name$="[name]"]');
              var textTa = existing[i].querySelector('textarea[name$="[text]"]');
              if (nameInp && (replace || !(nameInp.value || "").trim())) nameInp.value = nameVal;
              if (textTa && (replace || !(textTa.value || "").trim())) textTa.value = textVal;
            } else {
              var addStepBtn = panelEl.querySelector(".mev-add-schema-step");
              if (addStepBtn) addStepBtn.click();
              var newRows = wrap.querySelectorAll(".mev-schema-step");
              var last = newRows[newRows.length - 1];
              if (last) {
                var ni = last.querySelector('input[name$="[name]"]');
                var nt = last.querySelector('textarea[name$="[text]"]');
                if (ni) ni.value = nameVal;
                if (nt) nt.value = textVal;
              }
            }
          }
        }
      }
      if (schemaType === "Recipe") {
        setVal("meyvora_seo_schema_recipe[recipeName]", fields.recipeName);
        setVal("meyvora_seo_schema_recipe[recipeYield]", fields.recipeYield);
        setVal("meyvora_seo_schema_recipe[prepTime]", fields.prepTime);
        setVal("meyvora_seo_schema_recipe[cookTime]", fields.cookTime);
        var ingredients = Array.isArray(fields.ingredients) ? fields.ingredients : [];
        var ingWrap = panelEl.querySelector(".mev-schema-ingredients-wrap");
        if (ingWrap) {
          var ingInputs = ingWrap.querySelectorAll('input[name="meyvora_seo_schema_recipe[ingredients][]"]');
          var j;
          for (j = 0; j < ingredients.length; j++) {
            var ingVal = String(ingredients[j] || "").trim();
            if (j < ingInputs.length) {
              if (replace || !(ingInputs[j].value || "").trim()) ingInputs[j].value = ingVal;
            } else {
              var addIng = panelEl.querySelector(".mev-add-ingredient");
              if (addIng) addIng.click();
              var newIngs = ingWrap.querySelectorAll('input[name="meyvora_seo_schema_recipe[ingredients][]"]');
              if (newIngs[newIngs.length - 1]) newIngs[newIngs.length - 1].value = ingVal;
            }
          }
        }
        var instructions = Array.isArray(fields.recipeInstructions) ? fields.recipeInstructions : (Array.isArray(fields.instructions) ? fields.instructions : []);
        var instrWrap = panelEl.querySelector(".mev-schema-instructions-wrap");
        if (instrWrap) {
          var instrTas = instrWrap.querySelectorAll('textarea[name="meyvora_seo_schema_recipe[instructions][]"]');
          var k;
          for (k = 0; k < instructions.length; k++) {
            var instrVal = String(instructions[k] || "").trim();
            if (k < instrTas.length) {
              if (replace || !(instrTas[k].value || "").trim()) instrTas[k].value = instrVal;
            } else {
              var addInstr = panelEl.querySelector(".mev-add-instruction");
              if (addInstr) addInstr.click();
              var newInstr = instrWrap.querySelectorAll('textarea[name="meyvora_seo_schema_recipe[instructions][]"]');
              if (newInstr[newInstr.length - 1]) newInstr[newInstr.length - 1].value = instrVal;
            }
          }
        }
      }
      if (schemaType === "Event") {
        setVal("meyvora_seo_schema_event[name]", fields.name);
        setVal("meyvora_seo_schema_event[startDate]", fields.startDate);
        setVal("meyvora_seo_schema_event[endDate]", fields.endDate);
        setVal("meyvora_seo_schema_event[eventStatus]", fields.eventStatus);
        setVal("meyvora_seo_schema_event[eventAttendanceMode]", fields.eventAttendanceMode);
        setVal("meyvora_seo_schema_event[description]", fields.description);
        var loc = fields.location;
        if (loc && typeof loc === "object") {
          setVal("meyvora_seo_schema_event[location][name]", loc.name);
          setVal("meyvora_seo_schema_event[location][address]", loc.address);
        } else if (typeof fields.location === "string") {
          setVal("meyvora_seo_schema_event[location][name]", fields.location);
        }
      }
      if (schemaType === "JobPosting") {
        setVal("meyvora_seo_schema_jobposting[title]", fields.title);
        setVal("meyvora_seo_schema_jobposting[description]", fields.description);
        setVal("meyvora_seo_schema_jobposting[datePosted]", fields.datePosted);
        setVal("meyvora_seo_schema_jobposting[validThrough]", fields.validThrough);
        var ho = fields.hiringOrganization;
        if (ho && typeof ho === "object") setVal("meyvora_seo_schema_jobposting[hiringOrganization][name]", ho.name);
        var jl = fields.jobLocation;
        if (jl && typeof jl === "object") {
          setVal("meyvora_seo_schema_jobposting[jobLocation][city]", jl.addressLocality || jl.city);
          setVal("meyvora_seo_schema_jobposting[jobLocation][streetAddress]", jl.streetAddress);
          setVal("meyvora_seo_schema_jobposting[jobLocation][country]", jl.addressCountry || jl.country);
        }
        var sal = fields.baseSalary;
        if (sal && typeof sal === "object" && sal.value !== undefined) {
          setVal("meyvora_seo_schema_jobposting[baseSalary][value]", sal.value);
        } else if (fields.salary !== undefined) {
          setVal("meyvora_seo_schema_jobposting[baseSalary][value]", fields.salary);
        }
      }
    }

    // Schema step image pickers
    if (typeof wp !== "undefined" && wp.media) {
      panel.addEventListener("click", function (e) {
        if (!e.target || !e.target.classList.contains("mev-picker-step-image"))
          return;
        e.preventDefault();
        var wrap = e.target.closest(".meyvora-media-picker-wrap");
        var input = wrap ? wrap.querySelector(".mev-step-image-id") : null;
        if (!input) return;
        var frame = wp.media({ library: { type: "image" }, multiple: false });
        frame.on("select", function () {
          var att = frame.state().get("selection").first().toJSON();
          if (att && att.id) {
            input.value = att.id;
          }
        });
        frame.open();
      });
    }

    // Blur autosave for other fields
    [
      "meyvora_seo_canonical",
      "meyvora_seo_og_title",
      "meyvora_seo_og_description",
      "meyvora_seo_twitter_title",
      "meyvora_seo_twitter_description",
      "meyvora_seo_schema_type",
      "meyvora_seo_breadcrumb_title",
    ].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) {
        el.addEventListener("blur", debounce(autosave, 300));
        if (id === "meyvora_seo_canonical")
          el.addEventListener("input", updateSnippetPreview);
      }
    });
    panel.querySelectorAll(".meyvora-sec-kw").forEach(function (el) {
      el.addEventListener("blur", debounce(autosave, 300));
    });

    // Color-coded analysis row classes (pass / warning / fail)
    var checklist = document.getElementById("meyvora_seo_checklist");
    if (checklist) {
      checklist.querySelectorAll(".mev-checklist-item").forEach(function (el) {
        if (el.classList.contains("mev-checklist-item--pass")) {
          el.classList.add("mev-check-pass");
        } else if (el.classList.contains("mev-checklist-item--warn")) {
          el.classList.add("mev-check-warning");
        } else if (el.classList.contains("mev-checklist-item--fail")) {
          el.classList.add("mev-check-fail");
        }
      });
    }

    // Passed checklist toggle
    var togglePassed = $id("meyvora_toggle_passed");
    var passedList = $id("meyvora_passed_list");
    if (togglePassed && passedList) {
      togglePassed.addEventListener("click", function () {
        var hidden = passedList.style.display === "none";
        passedList.style.display = hidden ? "" : "none";
        togglePassed.textContent = hidden
          ? i18n.hidePassed || "Hide passed checks"
          : (i18n.showPassed || "Show passed checks").replace(
              "%d",
              passedList.querySelectorAll("li").length
            );
      });
    }

    // Keyword Research panel (when DataForSEO key is set)
    if (config.keywordResearchEnabled) {
      var krToggle = $id("meyvora_keyword_research_toggle");
      var krPanel = $id("meyvora_keyword_research_panel");
      var krBtn = $id("meyvora_keyword_research_btn");
      var krResult = $id("meyvora_keyword_research_result");
      if (krToggle && krPanel) {
        krToggle.addEventListener("click", function () {
          var open = krPanel.hidden;
          krPanel.hidden = !open;
          krToggle.setAttribute("aria-expanded", open ? "true" : "false");
          var span = krToggle.querySelector("span");
          if (span) span.textContent = open ? "▲" : "▼";
        });
      }
      if (krBtn && krResult) {
        krBtn.addEventListener("click", function () {
          var pills = [];
          var container = document.getElementById("meyvora_focus_keywords_tags");
          if (container) {
            container.querySelectorAll(".mev-focus-pill").forEach(function (el) {
              var kw = el.getAttribute("data-keyword");
              if (kw) pills.push(kw);
            });
          }
          var keyword = pills.length ? pills[0] : "";
          if (!keyword.trim()) {
            krResult.innerHTML = "<p class=\"meyvora-keyword-research-error\">" + (i18n.keywordResearchError || "Enter a focus keyword first.") + "</p>";
            return;
          }
          krBtn.disabled = true;
          krBtn.textContent = i18n.keywordResearching || "Researching…";
          krResult.innerHTML = "";
          var form = new FormData();
          form.append("action", "meyvora_seo_keyword_research");
          form.append("nonce", nonce);
          form.append("keyword", keyword);
          var xhr = new XMLHttpRequest();
          xhr.open("POST", ajaxUrl);
          xhr.onload = function () {
            krBtn.disabled = false;
            krBtn.textContent = i18n.keywordResearchBtn || "Research";
            try {
              var res = JSON.parse(xhr.responseText);
              if (res.success && res.data) {
                renderKeywordResearchTable(krResult, res.data, i18n, function (sugKeyword) {
                  var secInputs = document.querySelectorAll(".meyvora-sec-kw");
                  for (var i = 0; i < secInputs.length; i++) {
                    if (!secInputs[i].value.trim()) {
                      secInputs[i].value = sugKeyword;
                      secInputs[i].dispatchEvent(new Event("change", { bubbles: true }));
                      break;
                    }
                  }
                });
              } else {
                krResult.innerHTML = "<p class=\"meyvora-keyword-research-error\">" + (res.data && res.data.message ? res.data.message : (i18n.keywordResearchError || "Request failed.")) + "</p>";
              }
            } catch (e) {
              krResult.innerHTML = "<p class=\"meyvora-keyword-research-error\">" + (i18n.keywordResearchError || "Request failed.") + "</p>";
            }
          };
          xhr.onerror = function () {
            krBtn.disabled = false;
            krBtn.textContent = i18n.keywordResearchBtn || "Research";
            krResult.innerHTML = "<p class=\"meyvora-keyword-research-error\">" + (i18n.keywordResearchError || "Request failed.") + "</p>";
          };
          xhr.send(form);
        });
      }
    }
    function renderKeywordResearchTable(container, data, i18n, onAddSecondary) {
      var vol = i18n.keywordVolume || "Volume";
      var comp = i18n.keywordCompetition || "Competition";
      var cpc = i18n.keywordCpc || "CPC";
      var addLabel = i18n.keywordAddSecondary || "Add";
      var html = "<table class=\"meyvora-keyword-research-table\"><thead><tr><th>" + (i18n.focusKeywords || "Keyword") + "</th><th>" + vol + "</th><th>" + comp + "</th><th>" + cpc + "</th><th></th></tr></thead><tbody>";
      html += "<tr><td>" + (data.keyword || "") + "</td><td>" + (data.search_volume || 0) + "</td><td>" + (data.competition != null ? Number(data.competition).toFixed(2) : "0") + "</td><td>$" + (data.cpc != null ? Number(data.cpc).toFixed(2) : "0") + "</td><td>—</td></tr>";
      var suggestions = data.suggestions || [];
      suggestions.forEach(function (s) {
        var kw = s.keyword || "";
        var sv = s.search_volume != null ? s.search_volume : 0;
        var co = s.competition != null ? Number(s.competition).toFixed(2) : "0";
        var cp = s.cpc != null ? "$" + Number(s.cpc).toFixed(2) : "$0";
        html += "<tr><td>" + kw + "</td><td>" + sv + "</td><td>" + co + "</td><td>" + cp + "</td><td><button type=\"button\" class=\"button button-small meyvora-kr-add\" data-keyword=\"" + kw.replace(/"/g, "&quot;") + "\">+</button></td></tr>";
      });
      html += "</tbody></table>";
      container.innerHTML = html;
      container.querySelectorAll(".meyvora-kr-add").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var kw = btn.getAttribute("data-keyword");
          if (kw && onAddSecondary) onAddSecondary(kw);
        });
      });
    }

    // Initial snippet
    updateSnippetPreview();
  }

  // Toast notification (used by meta box and audit page)
  window.mevShowToast = function (message, type) {
    var existing = document.getElementById("mev-toast-container");
    if (existing) existing.remove();
    var toast = document.createElement("div");
    toast.id = "mev-toast-container";
    toast.className = "mev-toast mev-toast--" + (type || "default");
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        toast.classList.add("is-visible");
      });
    });
    setTimeout(function () {
      toast.classList.remove("is-visible");
      setTimeout(function () {
        toast.remove();
      }, 300);
    }, 3000);
  };

  // Audit page expand row (called from onclick in PHP)
  window.mevToggleDetail = function (pid) {
    var row = document.getElementById("mev-detail-" + pid);
    if (!row) return;
    row.style.display = row.style.display === "none" ? "table-row" : "none";
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
