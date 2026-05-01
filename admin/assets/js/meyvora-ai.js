/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Meyvora SEO – AI assistant: generate title/description, suggest keywords, improve content.
 * All requests go through PHP proxy; API key never sent to frontend.
 *
 * @package Meyvora_SEO
 */

(function () {
  "use strict";

  var cfg = typeof meyvoraSeoAi !== "undefined" ? meyvoraSeoAi : {};
  var ajaxUrl = cfg.ajaxUrl || "";
  var nonce = cfg.nonce || "";
  var rateLimit = cfg.rateLimit || 20;
  var remaining = cfg.remaining || 0;
  var i18n = cfg.i18n || {};
  var postId = (typeof meyvoraSeo !== "undefined" && meyvoraSeo.postId)
    ? parseInt(meyvoraSeo.postId, 10) : 0;

  function $id(id) {
    return document.getElementById(id);
  }

  function getFocusKeyword() {
    var hidden = $id("meyvora_seo_focus_keyword");
    if (!hidden || !hidden.value) return "";
    try {
      var arr = JSON.parse(hidden.value);
      return Array.isArray(arr) && arr.length > 0 ? arr[0] || "" : "";
    } catch (e) {
      return "";
    }
  }

  function request(actionType, extraData, onSuccess, onError) {
    if (remaining <= 0) {
      if (onError)
        onError({
          message: i18n.rateLimitReached || "Daily AI limit reached.",
          code: "rate_limit",
        });
      return;
    }
    var data = new FormData();
    data.append("action", "meyvora_seo_ai_request");
    data.append("nonce", nonce);
    data.append("action_type", actionType);
    data.append("post_id", postId);
    data.append("focus_keyword", getFocusKeyword());
    if (extraData) {
      Object.keys(extraData).forEach(function (k) {
        data.append(k, extraData[k]);
      });
    }
    var xhr = new XMLHttpRequest();
    xhr.open("POST", ajaxUrl);
    xhr.onload = function () {
      try {
        var res = JSON.parse(xhr.responseText);
        if (res.success && res.data) {
          if (onSuccess) onSuccess(res.data);
          if (typeof cfg.remaining !== "undefined")
            cfg.remaining = (cfg.remaining || remaining) - 1;
          remaining = remaining - 1;
        } else {
          if (onError) onError(res.data || { message: i18n.error || "Error" });
        }
      } catch (e) {
        if (onError)
          onError({ message: i18n.error || "Something went wrong." });
      }
    };
    xhr.onerror = function () {
      if (onError) onError({ message: i18n.error || "Network error." });
    };
    xhr.send(data);
  }

  function setButtonLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    btn.textContent = loading
      ? i18n.loading || "Generating…"
      : btn.getAttribute("data-original-text") || btn.textContent;
  }

  function showModal(title, items, onUse) {
    var overlay = document.getElementById("meyvora_ai_modal_overlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "meyvora_ai_modal_overlay";
      overlay.className = "meyvora-ai-modal-overlay";
      overlay.innerHTML =
        '<div class="meyvora-ai-modal"><div class="meyvora-ai-modal-header"><h3 class="meyvora-ai-modal-title"></h3><button type="button" class="meyvora-ai-modal-close" aria-label="' +
        (i18n.close || "Close") +
        '">&times;</button></div><div class="meyvora-ai-modal-body"></div></div>';
      document.body.appendChild(overlay);
      overlay
        .querySelector(".meyvora-ai-modal-close")
        .addEventListener("click", function () {
          overlay.classList.remove("is-open");
          overlay.style.display = "none";
        });
      overlay.addEventListener("click", function (e) {
        if (e.target === overlay) {
          overlay.classList.remove("is-open");
          overlay.style.display = "none";
        }
      });
    }
    overlay.style.display = "";
    overlay.querySelector(".meyvora-ai-modal-title").textContent = title;
    var body = overlay.querySelector(".meyvora-ai-modal-body");
    body.innerHTML = "";
    items.forEach(function (item, idx) {
      var row = document.createElement("div");
      row.className = "meyvora-ai-modal-option";
      var text = typeof item === "string" ? item : item.keyword || item;
      var tier = typeof item === "object" && item.tier ? item.tier : null;
      row.innerHTML =
        '<span class="meyvora-ai-option-text">' +
        escapeHtml(text) +
        "</span>" +
        (tier
          ? '<span class="meyvora-ai-option-tier">' +
            escapeHtml(tier) +
            "</span>"
          : "") +
        '<button type="button" class="button button-small meyvora-ai-use-btn">' +
        (i18n.useThis || "Use this") +
        "</button>";
      var useBtn = row.querySelector(".meyvora-ai-use-btn");
      useBtn.addEventListener("click", function () {
        if (onUse) onUse(text, item);
        overlay.classList.remove("is-open");
        overlay.style.display = "none";
      });
      body.appendChild(row);
    });
    overlay.classList.add("is-open");
  }

  function escapeHtml(s) {
    var div = document.createElement("div");
    div.textContent = s;
    return div.innerHTML;
  }

  function notifyRequestError(err) {
    var msg = (err && err.message) || i18n.error || "Error";
    if (err && err.code === "rate_limit") {
      if (typeof window.mevToast === "function") {
        window.mevToast(i18n.rateLimitReached || msg, "error");
      } else {
        alert(i18n.rateLimitReached || msg);
      }
      return;
    }
    alert(msg);
  }

  function addFocusKeyword(keyword) {
    var hidden = $id("meyvora_seo_focus_keyword");
    var container = $id("meyvora_focus_keywords_tags");
    var input = $id("meyvora_seo_focus_keyword_input");
    if (!hidden || !container) return;
    var list = [];
    try {
      var parsed = JSON.parse(hidden.value || "[]");
      if (Array.isArray(parsed)) list = parsed.slice();
    } catch (e) {}
    if (list.indexOf(keyword) !== -1) return;
    if (list.length >= 5) list.pop();
    list.push(keyword);
    hidden.value = JSON.stringify(list);
    var pill = document.createElement("span");
    pill.className = "mev-focus-pill";
    pill.setAttribute("data-keyword", keyword);
    pill.innerHTML =
      escapeHtml(keyword) +
      ' <button type="button" class="mev-focus-pill-remove" aria-label="Remove">&times;</button>';
    container.insertBefore(pill, input);
    var removeBtn = pill.querySelector(".mev-focus-pill-remove");
    if (removeBtn) {
      removeBtn.addEventListener("click", function () {
        var idx = list.indexOf(keyword);
        if (idx !== -1) {
          list.splice(idx, 1);
          hidden.value = JSON.stringify(list);
        }
        pill.remove();
      });
    }
  }

  // —— Title ———
  var btnTitle = $id("meyvora_ai_btn_title");
  if (btnTitle) {
    btnTitle.setAttribute("data-original-text", btnTitle.textContent);
    btnTitle.addEventListener("click", function () {
      setButtonLoading(btnTitle, true);
      request(
        "generate_title",
        {},
        function (data) {
          setButtonLoading(btnTitle, false);
          var options = data.options || [];
          if (options.length === 0) {
            alert(i18n.error || "No options returned.");
            return;
          }
          showModal(i18n.titles || "Choose a title", options, function (text) {
            var el = $id("meyvora_seo_title");
            if (el) {
              el.value = text;
              el.dispatchEvent(new Event("input", { bubbles: true }));
            }
          });
        },
        function (err) {
          setButtonLoading(btnTitle, false);
          notifyRequestError(err);
        }
      );
    });
  }

  // —— Description ———
  var btnDesc = $id("meyvora_ai_btn_desc");
  if (btnDesc) {
    btnDesc.setAttribute("data-original-text", btnDesc.textContent);
    btnDesc.addEventListener("click", function () {
      setButtonLoading(btnDesc, true);
      request(
        "generate_description",
        {},
        function (data) {
          setButtonLoading(btnDesc, false);
          var options = data.options || [];
          if (options.length === 0) {
            alert(i18n.error || "No options returned.");
            return;
          }
          showModal(
            i18n.descriptions || "Choose a description",
            options,
            function (text) {
              var el = $id("meyvora_seo_description");
              if (el) {
                el.value = text;
                el.dispatchEvent(new Event("input", { bubbles: true }));
              }
            }
          );
        },
        function (err) {
          setButtonLoading(btnDesc, false);
          notifyRequestError(err);
        }
      );
    });
  }

  // —— Description A/B variants ———
  var btnDescVariants = $id("meyvora_ai_btn_desc_variants");
  if (btnDescVariants) {
    btnDescVariants.setAttribute("data-original-text", btnDescVariants.textContent);
    btnDescVariants.addEventListener("click", function () {
      setButtonLoading(btnDescVariants, true);
      request(
        "generate_desc_variants",
        {},
        function (data) {
          setButtonLoading(btnDescVariants, false);
          var va = (data.variant_a != null && data.variant_a !== "") ? String(data.variant_a) : "";
          var vb = (data.variant_b != null && data.variant_b !== "") ? String(data.variant_b) : "";
          var elA = $id("meyvora_seo_desc_variant_a");
          var elB = $id("meyvora_seo_desc_variant_b");
          if (elA) { elA.value = va; elA.dispatchEvent(new Event("input", { bubbles: true })); }
          if (elB) { elB.value = vb; elB.dispatchEvent(new Event("input", { bubbles: true })); }
        },
        function (err) {
          setButtonLoading(btnDescVariants, false);
          notifyRequestError(err);
        }
      );
    });
  }

  // —— Keywords ———
  var btnKeywords = $id("meyvora_ai_btn_keywords");
  if (btnKeywords) {
    btnKeywords.setAttribute("data-original-text", btnKeywords.textContent);
    btnKeywords.addEventListener("click", function () {
      setButtonLoading(btnKeywords, true);
      request(
        "suggest_keywords",
        {},
        function (data) {
          setButtonLoading(btnKeywords, false);
          var keywords = data.keywords || [];
          if (keywords.length === 0) {
            alert(i18n.error || "No suggestions returned.");
            return;
          }
          showModal("Keyword suggestions", keywords, function (text) {
            addFocusKeyword(text);
          });
        },
        function (err) {
          setButtonLoading(btnKeywords, false);
          notifyRequestError(err);
        }
      );
    });
  }

  // —— Improve for SEO (slide-in panel) ———
  var btnImprove = $id("meyvora_ai_btn_improve");
  var panel = $id("meyvora_ai_improve_panel");
  if (btnImprove && panel) {
    btnImprove.setAttribute("data-original-text", btnImprove.textContent);
    btnImprove.addEventListener("click", function () {
      setButtonLoading(btnImprove, true);
      panel.setAttribute("aria-hidden", "true");
      panel.classList.remove("is-open");
      panel.innerHTML = "";
      request(
        "improve_content",
        {},
        function (data) {
          setButtonLoading(btnImprove, false);
          var score =
            data.readability_score != null ? data.readability_score : 0;
          var tips = data.tips || [];
          var headings = data.suggested_headings || [];
          var html =
            '<div class="meyvora-ai-improve-header">' +
            "<h4>" +
            (i18n.readabilityScore || "Readability score") +
            "</h4>" +
            '<div class="meyvora-ai-improve-score">' +
            score +
            "/100</div>" +
            '<button type="button" class="meyvora-ai-panel-close" aria-label="' +
            (i18n.close || "Close") +
            '">&times;</button>' +
            "</div>";
          if (tips.length > 0) {
            html +=
              '<div class="meyvora-ai-improve-block"><h4>' +
              (i18n.improvementTips || "Improvement tips") +
              "</h4><ul>";
            tips.forEach(function (t) {
              html += "<li>" + escapeHtml(t) + "</li>";
            });
            html += "</ul></div>";
          }
          if (headings.length > 0) {
            html +=
              '<div class="meyvora-ai-improve-block"><h4>' +
              (i18n.suggestedHeadings || "Suggested headings") +
              "</h4><ul>";
            headings.forEach(function (h) {
              html += "<li>" + escapeHtml(h) + "</li>";
            });
            html += "</ul></div>";
          }
          panel.innerHTML = html;
          panel.classList.add("is-open");
          panel.setAttribute("aria-hidden", "false");
          var closeBtn = panel.querySelector(".meyvora-ai-panel-close");
          if (closeBtn) {
            closeBtn.addEventListener("click", function () {
              panel.classList.remove("is-open");
              panel.setAttribute("aria-hidden", "true");
            });
          }
        },
        function (err) {
          setButtonLoading(btnImprove, false);
          notifyRequestError(err);
        }
      );
    });
  }

  // —— AI Assistant panel ———
  var assistantMode = $id("meyvora_ai_assistant_mode");
  var assistantInput = $id("meyvora_ai_assistant_input");
  var assistantGenerate = $id("meyvora_ai_assistant_generate");
  var assistantResult = $id("meyvora_ai_assistant_result");
  var assistantResultContent = assistantResult
    ? assistantResult.querySelector(".meyvora-ai-assistant-result-content")
    : null;
  var assistantInsert = $id("meyvora_ai_assistant_insert");
  var assistantCopy = $id("meyvora_ai_assistant_copy");

  function getPostTitle() {
    var el = $id("meyvora_seo_title");
    return el && el.value ? el.value.trim() : "";
  }

  function updateAssistantInputPlaceholder() {
    if (!assistantInput || !assistantMode) return;
    var mode = assistantMode.value;
    if (mode === "outline") {
      var title = getPostTitle();
      var kw = getFocusKeyword();
      assistantInput.value = title + (kw ? " | " + kw : "");
      assistantInput.placeholder = i18n.inputPlaceholder || "Paste or type content…";
    } else {
      assistantInput.placeholder = i18n.inputPlaceholder || "Paste or type content…";
    }
  }

  function insertContentIntoEditor(text) {
    if (!text || typeof text !== "string") return;
    var trimmed = text.trim();
    if (trimmed === "") return;
    // Gutenberg: wp.data.dispatch("core/block-editor").insertBlocks() and wp.blocks.createBlock
    if (
      typeof wp !== "undefined" &&
      wp.data &&
      wp.blocks &&
      wp.data.dispatch("core/block-editor") &&
      typeof wp.data.dispatch("core/block-editor").insertBlocks === "function"
    ) {
      var blockEditor = wp.data.dispatch("core/block-editor");
      var blocks = trimmed.split(/\n\n+/).map(function (para) {
        return wp.blocks.createBlock("core/paragraph", {
          content: para.replace(/\n/g, "<br>"),
        });
      });
      if (blocks.length === 0) {
        blocks = [wp.blocks.createBlock("core/paragraph", { content: trimmed.replace(/\n/g, "<br>") })];
      }
      blockEditor.insertBlocks(blocks);
      return;
    }
    // Fallback: core/editor (e.g. older Gutenberg)
    if (
      typeof wp !== "undefined" &&
      wp.data &&
      wp.blocks &&
      wp.data.dispatch("core/editor") &&
      typeof wp.data.dispatch("core/editor").insertBlocks === "function"
    ) {
      var blocks = trimmed.split(/\n\n+/).map(function (para) {
        return wp.blocks.createBlock("core/paragraph", {
          content: para.replace(/\n/g, "<br>"),
        });
      });
      if (blocks.length === 0) {
        blocks = [wp.blocks.createBlock("core/paragraph", { content: trimmed.replace(/\n/g, "<br>") })];
      }
      wp.data.dispatch("core/editor").insertBlocks(blocks);
      return;
    }
    // Classic editor: TinyMCE
    if (typeof tinymce !== "undefined" && tinymce.activeEditor) {
      tinymce.activeEditor.insertContent(
        "<p>" + trimmed.replace(/\n/g, "</p><p>") + "</p>"
      );
      return;
    }
  }

  if (assistantMode) {
    assistantMode.addEventListener("change", updateAssistantInputPlaceholder);
  }

  if (assistantGenerate && assistantResult && assistantResultContent) {
    assistantGenerate.setAttribute("data-original-text", assistantGenerate.textContent);
    assistantGenerate.addEventListener("click", function () {
      var mode = assistantMode ? assistantMode.value : "outline";
      var inputText = assistantInput ? assistantInput.value.trim() : "";
      if (
        (mode === "expand_paragraph" || mode === "improve_readability" || mode === "check_tone") &&
        inputText === ""
      ) {
        alert(i18n.error || "Please enter some text.");
        return;
      }
      setButtonLoading(assistantGenerate, true);
      assistantResult.style.display = "none";
      var extraData = { assistant_input: assistantInput ? assistantInput.value : "" };
      if (mode === "outline") {
        extraData.title = getPostTitle();
      }
      request(
        mode,
        extraData,
        function (data) {
          setButtonLoading(assistantGenerate, false);
          var text = (data && data.text) ? data.text : "";
          assistantResultContent.textContent = text;
          assistantResultContent.setAttribute("data-result-text", text);
          assistantResult.style.display = text ? "block" : "none";
          if (assistantInsert) assistantInsert.disabled = !text;
          if (assistantCopy) assistantCopy.disabled = !text;
        },
        function (err) {
          setButtonLoading(assistantGenerate, false);
          notifyRequestError(err);
        }
      );
    });
  }

  if (assistantInsert) {
    assistantInsert.addEventListener("click", function () {
      var text =
        assistantResultContent &&
        assistantResultContent.getAttribute("data-result-text");
      if (text) insertContentIntoEditor(text);
    });
  }

  if (assistantCopy) {
    assistantCopy.addEventListener("click", function () {
      var text =
        assistantResultContent &&
        assistantResultContent.getAttribute("data-result-text");
      if (!text) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(
          function () {
            if (assistantCopy) {
              var orig = assistantCopy.textContent;
              assistantCopy.textContent = i18n.copied || "Copied!";
              setTimeout(function () {
                assistantCopy.textContent = orig;
              }, 1500);
            }
          },
          function () {
            alert(i18n.error || "Copy failed.");
          }
        );
      } else {
        alert(i18n.error || "Copy not supported.");
      }
    });
  }

  // Pre-fill outline on load
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", updateAssistantInputPlaceholder);
  } else {
    updateAssistantInputPlaceholder();
  }
})();
