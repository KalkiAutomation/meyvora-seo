/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Meyvora SEO – Gutenberg sidebar: tabbed UI (SEO, Readability, Social), live fields, analysis.
 * Reads/writes meta via core/editor; analysis sends live field values to AJAX.
 *
 * Block Editor Architecture (keep consistent):
 * - Use wp.element only; no jQuery or vanilla DOM for editor UI (document.addEventListener only for script bootstrap).
 * - Read/write meta via useSelect("core/editor") and useDispatch("core/editor").editPost().
 * - Use PluginDocumentSettingPanel only (not PluginSidebar).
 * - New meta keys must be registered in register_rest_meta() with show_in_rest: true.
 * - Analysis is debounced (800ms min); AJAX uses nonce meyvora_seo_nonce. PHP must not update_post_meta on analyze when overrides are sent.
 *
 * @package Meyvora_SEO
 */

(function () {
  "use strict";

  var config = typeof meyvoraSeoBlock !== "undefined" ? meyvoraSeoBlock : {};
  var ajaxUrl = config.ajaxUrl || "";
  var nonce = config.nonce || "";
  var postId = config.postId || 0;
  var currentMeta = config.currentMeta || {};
  var i18n = config.i18n || {};
  var titleMin = config.titleMin || 30;
  var titleMax = config.titleMax || 60;
  var descMin = config.descMin || 120;
  var descMax = config.descMax || 160;

  var KEYS = {
    FOCUS_KEYWORD: "_meyvora_seo_focus_keyword",
    TITLE: "_meyvora_seo_title",
    DESCRIPTION: "_meyvora_seo_description",
    DESC_VARIANT_A: "_meyvora_seo_desc_variant_a",
    DESC_VARIANT_B: "_meyvora_seo_desc_variant_b",
    DESC_AB_ACTIVE: "_meyvora_seo_desc_ab_active",
    DESC_AB_START: "_meyvora_seo_desc_ab_start",
    DESC_AB_RESULT: "_meyvora_seo_desc_ab_result",
    OG_TITLE: "_meyvora_seo_og_title",
    OG_DESCRIPTION: "_meyvora_seo_og_description",
    OG_IMAGE: "_meyvora_seo_og_image",
    TWITTER_TITLE: "_meyvora_seo_twitter_title",
    TWITTER_DESCRIPTION: "_meyvora_seo_twitter_description",
    TWITTER_IMAGE: "_meyvora_seo_twitter_image",
    SCORE: "_meyvora_seo_score",
    ANALYSIS: "_meyvora_seo_analysis",
    READABILITY: "_meyvora_seo_readability",
    SEARCH_INTENT: "_meyvora_seo_search_intent",
    CANONICAL: "_meyvora_seo_canonical",
    BREADCRUMB_TITLE: "_meyvora_seo_breadcrumb_title",
    NOINDEX: "_meyvora_seo_noindex",
    NOFOLLOW: "_meyvora_seo_nofollow",
    CORNERSTONE: "_meyvora_seo_cornerstone",
    NOODP: "_meyvora_seo_noodp",
    NOARCHIVE: "_meyvora_seo_noarchive",
    NOSNIPPET: "_meyvora_seo_nosnippet",
    MAX_SNIPPET: "_meyvora_seo_max_snippet",
    MAX_IMAGE_PREVIEW: "_meyvora_seo_max_image_preview",
    MAX_VIDEO_PREVIEW: "_meyvora_seo_max_video_preview",
    SCHEMA_TYPE: "_meyvora_seo_schema_type",
    FAQ: "_meyvora_seo_faq",
    SCHEMA_HOWTO: "_meyvora_seo_schema_howto",
    SCHEMA_RECIPE: "_meyvora_seo_schema_recipe",
    SCHEMA_EVENT: "_meyvora_seo_schema_event",
    SCHEMA_COURSE: "_meyvora_seo_schema_course",
    SCHEMA_JOBPOSTING: "_meyvora_seo_schema_jobposting",
    SCHEMA_REVIEW: "_meyvora_seo_schema_review",
    SCHEMA_PRODUCT: "_meyvora_seo_schema_product",
  };

  function debounce(fn, ms) {
    var t;
    function wrapped() {
      var a = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(null, a);
      }, ms);
    }
    wrapped.cancel = function () {
      clearTimeout(t);
      t = null;
    };
    return wrapped;
  }

  function parseFocusKeywords(raw) {
    if (raw === undefined || raw === null) return [];
    var s = String(raw).trim();
    if (!s) return [];
    if (s.charAt(0) === "[") {
      try {
        var arr = JSON.parse(s);
        return Array.isArray(arr)
          ? arr
              .slice(0, 5)
              .filter(function (v) {
                return typeof v === "string" && v.trim();
              })
              .map(function (v) {
                return v.trim();
              })
          : [s];
      } catch (e) {
        return [s];
      }
    }
    return [s];
  }

  function runAnalysis(params, callback, effectivePostId) {
    var pid =
      effectivePostId !== undefined && effectivePostId !== null
        ? Number(effectivePostId)
        : params.post_id !== undefined && params.post_id !== null
          ? params.post_id
          : postId;
    if (!pid || !ajaxUrl || !nonce) {
      if (callback) callback(null);
      return;
    }
    var body =
      "action=meyvora_seo_analyze&nonce=" +
      encodeURIComponent(nonce) +
      "&post_id=" +
      pid;
    if (params.content !== undefined)
      body += "&content=" + encodeURIComponent(params.content);
    if (params.title !== undefined)
      body += "&title=" + encodeURIComponent(params.title);
    if (params.description !== undefined)
      body += "&description=" + encodeURIComponent(params.description);
    if (params.focus_keyword !== undefined)
      body +=
        "&focus_keyword=" +
        encodeURIComponent(
          typeof params.focus_keyword === "string"
            ? params.focus_keyword
            : JSON.stringify(params.focus_keyword || [])
        );
    var xhr = new XMLHttpRequest();
    xhr.open("POST", ajaxUrl);
    xhr.setRequestHeader(
      "Content-Type",
      "application/x-www-form-urlencoded; charset=UTF-8"
    );
    xhr.onload = function () {
      var res;
      try {
        res = JSON.parse(xhr.responseText);
      } catch (e) {
        if (callback) callback(null);
        return;
      }
      if (callback) callback(res.success ? res.data : null);
    };
    xhr.onerror = function () {
      if (callback) callback(null);
    };
    xhr.send(body);
  }

  var registerAttempts = 0;
  var maxRegisterAttempts = 30;

  function registerPlugin() {
    // Require core editor globals. Do not require wp.editPost — in WP 6.6+ PluginDocumentSettingPanel
    // lives on wp.editor; we resolve the panel below and retry if it is not available yet.
    if (
      typeof wp === "undefined" ||
      !wp.plugins ||
      !wp.element ||
      !wp.data
    ) {
      if (registerAttempts >= maxRegisterAttempts) return;
      registerAttempts += 1;
      if (typeof wp !== "undefined" && wp.domReady) {
        wp.domReady(registerPlugin);
      } else {
        setTimeout(registerPlugin, 100);
      }
      return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var PluginDocumentSettingPanel =
      ( wp.editor && wp.editor.PluginDocumentSettingPanel )
        ? wp.editor.PluginDocumentSettingPanel
        : ( wp.editPost && wp.editPost.PluginDocumentSettingPanel )
          ? wp.editPost.PluginDocumentSettingPanel
          : null;

    if ( !PluginDocumentSettingPanel ) {
      // Neither wp.editor nor wp.editPost has PluginDocumentSettingPanel.
      // Retry — this may resolve once all editor scripts finish loading.
      if (registerAttempts >= maxRegisterAttempts) return;
      registerAttempts += 1;
      setTimeout(registerPlugin, 200);
      return;
    }

    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var SelectControl = wp.components.SelectControl;
    var ToggleControl = wp.components.ToggleControl;
    var Button = wp.components.Button;
    var TabPanel = wp.components.TabPanel;
    var Spinner = wp.components.Spinner;
    var __ = wp.i18n.__;
    var apiFetch = wp.apiFetch;

    var debouncedAnalyze = debounce(function (
      params,
      setResult,
      effectivePostId
    ) {
      runAnalysis(
        params,
        function (data) {
          if (setResult) setResult(data);
        },
        effectivePostId
      );
    }, 800);

    function FocusKeywordsTagInput(props) {
      var label = props.label || "Focus Keywords";
      var keywords = props.keywords || [];
      var onChange = props.onChange || function () {};
      var max = props.max || 5;
      var inputRef = wp.element.useRef(null);
      var pendingRef = wp.element.useRef("");

      function addKeyword(kw) {
        kw = (kw || "").trim();
        if (!kw || keywords.indexOf(kw) !== -1) return;
        if (keywords.length >= max) return;
        onChange(keywords.concat([kw]));
      }

      function removeKeyword(idx) {
        var next = keywords.slice(0, idx).concat(keywords.slice(idx + 1));
        onChange(next);
      }

      function onInputKeyDown(e) {
        if (e.key === "Enter" || e.key === ",") {
          e.preventDefault();
          var v = (e.target.value || "").trim();
          e.target.value = "";
          if (v.indexOf(",") !== -1) v.split(",").forEach(addKeyword);
          else addKeyword(v);
        }
      }

      function onInputChange(e) {
        var v = e.target.value || "";
        if (v.indexOf(",") !== -1) {
          v.split(",").forEach(addKeyword);
          e.target.value = "";
        }
      }

      return el("div", { className: "meyvora-block-editor-focus-keywords" }, [
        label
          ? el(
              "label",
              { key: "l", className: "components-base-control__label" },
              label
            )
          : null,
        el(
          "div",
          {
            key: "c",
            className: "meyvora-focus-keywords-tags meyvora-block-editor-tags",
          },
          [
            keywords.map(function (kw, i) {
              return el(
                "span",
                {
                  key: "p" + i,
                  className: "mev-focus-pill",
                },
                [
                  kw,
                  el(
                    "button",
                    {
                      key: "b",
                      type: "button",
                      className: "mev-focus-pill-remove",
                      "aria-label": __("Remove", "meyvora-seo"),
                      onClick: function () {
                        removeKeyword(i);
                      },
                    },
                    "×"
                  ),
                ]
              );
            }),
            keywords.length < max
              ? el("input", {
                  key: "i",
                  ref: inputRef,
                  type: "text",
                  className: "meyvora-focus-keyword-input",
                  placeholder: __(
                    "Add keyword (comma or Enter), max " + max,
                    "meyvora-seo"
                  ),
                  onKeyDown: onInputKeyDown,
                  onChange: onInputChange,
                })
              : null,
          ]
        ),
      ]);
    }

    function ScoreRing(props) {
      var score = props.score;
      if (score === null || score === undefined) score = 0;
      score = Math.max(0, Math.min(100, parseInt(score, 10)));
      var mountedState = useState(false);
      var mounted = mountedState[0];
      var setMounted = mountedState[1];
      useEffect(function () {
        var raf = requestAnimationFrame(function () {
          setMounted(true);
        });
        return function () {
          cancelAnimationFrame(raf);
        };
      }, []);
      var displayScore = mounted ? score : 0;
      var r = 18;
      var c = 2 * Math.PI * r;
      var off = c - (displayScore / 100) * c;
      var color = score >= 80 ? "#059669" : score >= 50 ? "#D97706" : "#DC2626";
      return el(
        "svg",
        {
          className: "meyvora-block-editor-gauge",
          width: 40,
          height: 40,
          viewBox: "0 0 40 40",
        },
        [
          el("circle", {
            cx: 20,
            cy: 20,
            r: r,
            fill: "none",
            stroke: "#E5E7EB",
            strokeWidth: 4,
          }),
          el("circle", {
            className: "meyvora-score-ring-progress",
            cx: 20,
            cy: 20,
            r: r,
            fill: "none",
            stroke: color,
            strokeWidth: 4,
            strokeDasharray: c,
            strokeDashoffset: off,
            strokeLinecap: "round",
            transform: "rotate(-90 20 20)",
          }),
          el(
            "text",
            {
              x: 20,
              y: 20,
              textAnchor: "middle",
              dominantBaseline: "central",
              fontSize: 10,
              fontWeight: "bold",
              fill: "#374151",
            },
            score
          ),
        ]
      );
    }

    function truncate(str, maxLen) {
      if (!str || str.length <= maxLen) return str;
      return str.slice(0, maxLen - 3) + "...";
    }

    function CharCountBar(props) {
      var current = props.current || 0;
      var min = props.min != null ? props.min : 0;
      var max = props.max != null ? props.max : 0;
      var fillPct = max > 0 ? Math.min(100, (current / max) * 100) : 0;
      var color = "#E5E7EB";
      var statusLabel = "";
      if (current >= min && current <= max) {
        color = "#16A34A";
        statusLabel = __("Good", "meyvora-seo");
      } else if (current > 0 && current < min) {
        color = "#D97706";
        statusLabel = __("Too short", "meyvora-seo");
      } else {
        color = "#DC2626";
        statusLabel =
          current > max
            ? __("Too long", "meyvora-seo")
            : __("Too short", "meyvora-seo");
      }
      return el("div", { className: "meyvora-char-bar-wrap" }, [
        el(
          "div",
          { key: "track", className: "meyvora-char-bar-track" },
          el("div", {
            key: "fill",
            className: "meyvora-char-bar-fill",
            style: { width: fillPct + "%", background: color },
          })
        ),
        el("div", { key: "label", className: "meyvora-char-bar-label" }, [
          el(
            "span",
            { key: "count" },
            current + " / " + max + " " + __("characters", "meyvora-seo")
          ),
          el("span", { key: "status" }, statusLabel),
        ]),
      ]);
    }

    function SnippetPreview(props) {
      var title = props.title || "";
      var desc = props.description || "";
      var siteDomain = props.siteDomain || "example.com";
      var postCategories = props.postCategories || "";
      var postSlug = props.postSlug || "";
      var previewMode = props.previewMode || "desktop";
      var isMobile = previewMode === "mobile";
      var breadcrumb = siteDomain;
      if (postCategories) breadcrumb += " › " + postCategories;
      if (postSlug) breadcrumb += " › " + postSlug;
      var titleMax = isMobile ? 63 : 60;
      var descMax = isMobile ? 130 : 160;
      var displayTitle = truncate(
        title || __("Your SEO title appears here", "meyvora-seo"),
        titleMax
      );
      var displayDesc = truncate(
        desc || __("Meta description will show here.", "meyvora-seo"),
        descMax
      );
      var wrapperClass =
        "meyvora-block-editor-snippet" + (isMobile ? " is-mobile" : "");
      return el("div", { className: wrapperClass }, [
        el("div", { className: "meyvora-snippet-url", key: "u" }, breadcrumb),
        el(
          "div",
          {
            className: "meyvora-snippet-title",
            key: "t",
            style: isMobile ? { maxWidth: "100%" } : { maxWidth: "580px" },
          },
          displayTitle
        ),
        el("div", { className: "meyvora-snippet-desc", key: "d" }, displayDesc),
      ]);
    }

    function MeyvoraSEOPanel() {
      var analysisResult = useState(null);
      var setAnalysisResult = analysisResult[1];
      var isAnalyzing = useState(false);
      var setIsAnalyzing = isAnalyzing[1];
      var ogImageUrlState = useState("");
      var setOgImageUrl = ogImageUrlState[1];
      var twitterImageUrlState = useState("");
      var setTwitterImageUrl = twitterImageUrlState[1];
      var previewModeState = useState("desktop");
      var previewMode = previewModeState[0];
      var setPreviewMode = previewModeState[1];
      var aiTitleOptionsState = useState(null);
      var aiDescOptionsState = useState(null);
      var aiLoadingTitleState = useState(false);
      var aiLoadingDescState = useState(false);
      var aiFaqLoadingState = useState(false);
      var schemaPrefillLoadingState = useState(false);
      var schemaPrefillReplaceState = useState({ HowTo: false, Recipe: false, Event: false, JobPosting: false });
      var intentClassifyLoadingState = useState(false);
      var aiErrorState = useState(null);
      var abPanelOpenState = useState(false);
      var aiDescVariantsLoadingState = useState(false);
      var linkSuggestionsState = useState([]);
      var linkSuggestionsLoadingState = useState(false);
      var linkSuggestionsOpenState = useState(true);
      var linkCopiedIndexState = useState(null);
      var expandedChecklistState = useState({});
      var schemaPreviewOpenState = useState(false);
      var copyJsonldLabel = useState(__("Copy JSON-LD", "meyvora-seo"));
      var keywordResearchOpenState = useState(false);
      var keywordResearchLoadingState = useState(false);
      var keywordResearchResultState = useState(null);
      var lastAnalyzed = useState(null);
      var contentHashAtLastAnalysis = useState("");
      var analysisClock = useState(0);
      var chatOpenState = useState(false);
      var chatOpen = chatOpenState[0];
      var setChatOpen = chatOpenState[1];
      var chatHistoryState = useState([]);
      var chatHistory = chatHistoryState[0];
      var setChatHistory = chatHistoryState[1];
      var chatInputState = useState("");
      var chatInput = chatInputState[0];
      var setChatInput = chatInputState[1];
      var chatLoadingState = useState(false);
      var chatLoading = chatLoadingState[0];
      var setChatLoading = chatLoadingState[1];

      var meta = useSelect(function (select) {
        // Subscribe to meta changes — empty dependency array causes stale reads
        // when editPost() updates meta; passing a stable selector key fixes reactivity.
        return select("core/editor").getEditedPostAttribute("meta") || {};
      });

      var content = useSelect(function (select) {
        return select("core/editor").getEditedPostContent();
      }, []);
      var contentStr = content || "";
      var wordCount = contentStr
        ? contentStr.replace(/<[^>]+>/g, " ").trim().split(/\s+/).filter(Boolean).length
        : 0;

      var editPost = useDispatch("core/editor").editPost;
      var livePostId = useSelect(function (select) {
        return select("core/editor").getCurrentPostId() || 0;
      }, []);
      var effectivePostId = livePostId || postId;
      var postSlug = useSelect(function (select) {
        return (
          select("core/editor").getEditedPostAttribute("slug") ||
          config.postSlug ||
          ""
        );
      }, []);
      var postCategories = useSelect(function (select) {
        var cats =
          select("core/editor").getEditedPostAttribute("categories") || [];
        return typeof window.meyvoraSeoBlock !== "undefined" &&
          window.meyvoraSeoBlock.postCategories
          ? window.meyvoraSeoBlock.postCategories
          : "";
      }, []);
      var postDate = useSelect(function (select) {
        return select("core/editor").getEditedPostAttribute("date") || "";
      }, []);
      var postModified = useSelect(function (select) {
        return select("core/editor").getEditedPostAttribute("modified") || "";
      }, []);
      var postTitle = useSelect(function (select) {
        return select("core/editor").getEditedPostAttribute("title") || "";
      }, []);

      var focusKeywordRaw = meta[KEYS.FOCUS_KEYWORD];
      var focusKeywordsArray = parseFocusKeywords(focusKeywordRaw);
      var focusKeywordStorage = Array.isArray(focusKeywordRaw)
        ? JSON.stringify(focusKeywordRaw)
        : focusKeywordRaw !== undefined && focusKeywordRaw !== null
          ? String(focusKeywordRaw)
          : "";
      var seoTitle =
        meta[KEYS.TITLE] !== undefined && meta[KEYS.TITLE] !== null
          ? String(meta[KEYS.TITLE])
          : "";
      var metaDesc =
        meta[KEYS.DESCRIPTION] !== undefined && meta[KEYS.DESCRIPTION] !== null
          ? String(meta[KEYS.DESCRIPTION])
          : "";
      var descVariantA = (meta[KEYS.DESC_VARIANT_A] != null && meta[KEYS.DESC_VARIANT_A] !== "") ? String(meta[KEYS.DESC_VARIANT_A]) : "";
      var descVariantB = (meta[KEYS.DESC_VARIANT_B] != null && meta[KEYS.DESC_VARIANT_B] !== "") ? String(meta[KEYS.DESC_VARIANT_B]) : "";
      var descAbActive = (meta[KEYS.DESC_AB_ACTIVE] != null && meta[KEYS.DESC_AB_ACTIVE] !== "") ? String(meta[KEYS.DESC_AB_ACTIVE]) : "";
      var descAbStart = (meta[KEYS.DESC_AB_START] != null && meta[KEYS.DESC_AB_START] !== "") ? String(meta[KEYS.DESC_AB_START]) : "";
      var descAbResultRaw = (meta[KEYS.DESC_AB_RESULT] != null && meta[KEYS.DESC_AB_RESULT] !== "") ? String(meta[KEYS.DESC_AB_RESULT]) : "";
      var ogTitle =
        meta[KEYS.OG_TITLE] !== undefined && meta[KEYS.OG_TITLE] !== null
          ? String(meta[KEYS.OG_TITLE])
          : "";
      var ogDesc =
        meta[KEYS.OG_DESCRIPTION] !== undefined &&
        meta[KEYS.OG_DESCRIPTION] !== null
          ? String(meta[KEYS.OG_DESCRIPTION])
          : "";
      var ogImageId = parseInt(meta[KEYS.OG_IMAGE], 10) || 0;
      var twitterImageId = parseInt(meta[KEYS.TWITTER_IMAGE], 10) || 0;
      var twitterTitle =
        meta[KEYS.TWITTER_TITLE] !== undefined &&
        meta[KEYS.TWITTER_TITLE] !== null
          ? String(meta[KEYS.TWITTER_TITLE])
          : "";
      var twitterDesc =
        meta[KEYS.TWITTER_DESCRIPTION] !== undefined &&
        meta[KEYS.TWITTER_DESCRIPTION] !== null
          ? String(meta[KEYS.TWITTER_DESCRIPTION])
          : "";
      var canonical =
        meta[KEYS.CANONICAL] !== undefined && meta[KEYS.CANONICAL] !== null
          ? String(meta[KEYS.CANONICAL])
          : "";
      var noindex = !!(
        meta[KEYS.NOINDEX] === "1" ||
        meta[KEYS.NOINDEX] === true ||
        meta[KEYS.NOINDEX] === 1
      );
      var nofollow = !!(
        meta[KEYS.NOFOLLOW] === "1" ||
        meta[KEYS.NOFOLLOW] === true ||
        meta[KEYS.NOFOLLOW] === 1
      );
      var noodp = !!(
        meta[KEYS.NOODP] === "1" ||
        meta[KEYS.NOODP] === true ||
        meta[KEYS.NOODP] === 1
      );
      var noarchive = !!(
        meta[KEYS.NOARCHIVE] === "1" ||
        meta[KEYS.NOARCHIVE] === true ||
        meta[KEYS.NOARCHIVE] === 1
      );
      var nosnippet = !!(
        meta[KEYS.NOSNIPPET] === "1" ||
        meta[KEYS.NOSNIPPET] === true ||
        meta[KEYS.NOSNIPPET] === 1
      );
      var maxSnippet =
        meta[KEYS.MAX_SNIPPET] !== undefined && meta[KEYS.MAX_SNIPPET] !== null && meta[KEYS.MAX_SNIPPET] !== ""
          ? parseInt(meta[KEYS.MAX_SNIPPET], 10)
          : -1;
      if (isNaN(maxSnippet)) maxSnippet = -1;
      var schemaType =
        meta[KEYS.SCHEMA_TYPE] !== undefined && meta[KEYS.SCHEMA_TYPE] !== null
          ? String(meta[KEYS.SCHEMA_TYPE])
          : "";
      var faqPairs = [];
      try {
        faqPairs = JSON.parse(meta[KEYS.FAQ] || "[]");
      } catch (e) {
        faqPairs = [];
      }
      if (!Array.isArray(faqPairs)) faqPairs = [];

      var fixHints = {
        focus_keyword_set: __(
          "Click the Focus Keywords field above and type your main keyword. Use a phrase your audience searches for.",
          "meyvora-seo"
        ),
        focus_keyword_title: __(
          "Edit the SEO Title field above and include your focus keyword. Place it near the start for best results.",
          "meyvora-seo"
        ),
        focus_keyword_description: __(
          "Edit the Meta Description and naturally include your focus keyword within the first sentence.",
          "meyvora-seo"
        ),
        focus_keyword_slug: __(
          "Go to the URL field in the block editor and update the slug to include your keyword.",
          "meyvora-seo"
        ),
        focus_keyword_content: __(
          "Add your focus keyword naturally into the post content so search engines understand the topic.",
          "meyvora-seo"
        ),
        focus_keyword_early: __(
          "Use the focus keyword in the first paragraph or two of your content.",
          "meyvora-seo"
        ),
        focus_keyword_secondary: __(
          "Review secondary keyword placement in title, description, and content.",
          "meyvora-seo"
        ),
        title_length:
          __(
            "Your title is outside the ideal range. Aim for 50–60 characters. Your current title: ",
            "meyvora-seo"
          ) +
          seoTitle.length +
          " " +
          __("chars", "meyvora-seo") +
          ".",
        title_pixel_width: __(
          "Shorten or lengthen the SEO title so it displays well in search results (about 200–600px wide).",
          "meyvora-seo"
        ),
        description_length: __(
          "Aim for 120–160 characters. Longer descriptions get cut off in search results.",
          "meyvora-seo"
        ),
        content_length: __(
          "Add more content. Longer, in-depth content tends to rank better.",
          "meyvora-seo"
        ),
        h1_count: __(
          "Use exactly one H1 heading in your content (the main title).",
          "meyvora-seo"
        ),
        headings_structure: __(
          "Add H2 or H3 subheadings to structure your content.",
          "meyvora-seo"
        ),
        images_alt: __(
          "Select each image in your content, open the block settings sidebar, and add descriptive alt text.",
          "meyvora-seo"
        ),
        keyword_in_image_alt: __(
          "Add the focus keyword to at least one image’s alt text in the block settings.",
          "meyvora-seo"
        ),
        image_per_300_words: __(
          "Add more images to break up long content (about one image per 300 words).",
          "meyvora-seo"
        ),
        internal_links: __(
          "Add 2–3 links to related posts on your site within your content.",
          "meyvora-seo"
        ),
        external_links: __(
          'Link to authoritative external sources. Avoid adding rel="nofollow" to all links.',
          "meyvora-seo"
        ),
        keyword_density: __(
          "Use the focus keyword 0.5–2.5% of the time. Avoid overstuffing or underusing it.",
          "meyvora-seo"
        ),
        keyword_in_first_h2: __(
          "Include your focus keyword in the first H2 heading of the content.",
          "meyvora-seo"
        ),
        keyword_in_h3_h4: __(
          "Add the focus keyword to at least one H3 or H4 subheading.",
          "meyvora-seo"
        ),
        keyword_in_last_10_percent: __(
          "Mention the focus keyword in the closing section of your content.",
          "meyvora-seo"
        ),
        toc_long_content: __(
          'For long content, add a table of contents (e.g. id="table-of-contents" or a TOC block).',
          "meyvora-seo"
        ),
        paragraph_count: __(
          "Split long blocks of text into shorter paragraphs for easier reading.",
          "meyvora-seo"
        ),
        sentence_length: __(
          "Shorten long sentences. Aim for 15–20 words per sentence on average.",
          "meyvora-seo"
        ),
        passive_voice: __(
          'Rewrite sentences to use active voice where possible (e.g. "The ball was thrown" → "She threw the ball").',
          "meyvora-seo"
        ),
        transition_words: __(
          "Add transition words (However, Therefore, In addition, etc.) to connect sentences and paragraphs.",
          "meyvora-seo"
        ),
        flesch_reading_ease: __(
          "Use simpler words and shorter sentences to improve readability score.",
          "meyvora-seo"
        ),
        og_image_set: __(
          "Set an OG image in the Social tab for better sharing on social networks.",
          "meyvora-seo"
        ),
        schema_set: __(
          "Choose a schema type in the Advanced tab (e.g. Article, WebPage).",
          "meyvora-seo"
        ),
      };
      var defaultHint = __(
        "Review this item and make improvements to your content.",
        "meyvora-seo"
      );

      function setMeta(key, value) {
        // Always read current meta from the store at call time so rapid typing
        // doesn't overwrite with a stale snapshot (which would blank other keys).
        var currentMeta =
          (wp.data && wp.data.select("core/editor") && wp.data.select("core/editor").getEditedPostAttribute("meta")) || {};
        var merged = Object.assign({}, currentMeta, { [key]: value });
        editPost({ meta: merged });
      }

      function inputsHash(c, title, desc, kw) {
        return (
          (c || "") +
          "\x00" +
          (title || "") +
          "\x00" +
          (desc || "") +
          "\x00" +
          (kw || "")
        );
      }

      function onAnalysisDone(data) {
        setAnalysisResult(data);
        lastAnalyzed[1](new Date());
        contentHashAtLastAnalysis[1](
          inputsHash(content, seoTitle, metaDesc, focusKeywordStorage)
        );
        setIsAnalyzing(false);
        if (data && (data.score != null || data.results)) {
          var currentMeta =
            (wp.data && wp.data.select("core/editor") && wp.data.select("core/editor").getEditedPostAttribute("meta")) || {};
          var scoreMerge = Object.assign({}, currentMeta);
          if (data.score != null) scoreMerge["_meyvora_seo_score"] = data.score;
          if (data.results)
            scoreMerge["_meyvora_seo_analysis"] = JSON.stringify({
              score: data.score,
              status: data.status,
              results: data.results,
            });
          editPost({ meta: scoreMerge });
        }
      }

      // Seed the editor store with saved values from PHP on first mount.
      // The block editor REST API often omits underscore-prefixed meta from its
      // initial GET response (even when registered with show_in_rest:true and a
      // default value), so Gutenberg's core/editor store starts with empty/undefined
      // for all our keys. We push the PHP-rendered values in immediately so:
      //  1. Fields show their saved values on load (not blank)
      //  2. On first save, Gutenberg sends ALL our keys in the PUT body
      //     (it only sends keys that exist in the editor dirty state)
      useEffect(function () {
        // Build a full meta object: start with ALL our keys set to their defaults,
        // then overlay whatever PHP has saved. This guarantees Gutenberg knows about
        // every key and will include them all in the next save request.
        var seed = {};
        var allKeys = Object.values(KEYS);
        for (var i = 0; i < allKeys.length; i++) {
          // Integer keys default to 0, string keys default to empty string
          var isInt =
            allKeys[i] === KEYS.OG_IMAGE ||
            allKeys[i] === KEYS.TWITTER_IMAGE ||
            allKeys[i] === KEYS.SCORE ||
            allKeys[i] === KEYS.MAX_SNIPPET;
          seed[allKeys[i]] = isInt ? (allKeys[i] === KEYS.MAX_SNIPPET ? -1 : 0) : "";
        }
        // Overlay PHP-provided saved values
        if (currentMeta && typeof currentMeta === "object") {
          for (var k in currentMeta) {
            if (Object.prototype.hasOwnProperty.call(currentMeta, k)) {
              seed[k] = currentMeta[k];
            }
          }
        }
        editPost({ meta: seed });
      }, []); // eslint-disable-line react-hooks/exhaustive-deps -- intentionally runs once on mount

      useEffect(
        function () {
          if (effectivePostId === 0) {
            setAnalysisResult(null);
            setIsAnalyzing(false);
            lastAnalyzed[1](null);
            contentHashAtLastAnalysis[1]("");
            return;
          }
          setIsAnalyzing(true);
          debouncedAnalyze(
            {
              content: content,
              title: seoTitle,
              description: metaDesc,
              focus_keyword: focusKeywordStorage,
            },
            onAnalysisDone,
            effectivePostId
          );
        },
        [effectivePostId, content, seoTitle, metaDesc, focusKeywordStorage]
      );

      useEffect(
        function () {
          if (!lastAnalyzed[0]) return;
          var id = setInterval(function () {
            analysisClock[1](function (n) {
              return n + 1;
            });
          }, 30000);
          return function () {
            clearInterval(id);
          };
        },
        [lastAnalyzed[0]]
      );

      useEffect(
        function () {
          if (!ogImageId) {
            setOgImageUrl("");
            return;
          }
          apiFetch({ path: "/wp/v2/media/" + ogImageId })
            .then(function (media) {
              setOgImageUrl(media.source_url || "");
            })
            .catch(function () {
              setOgImageUrl("");
            });
        },
        [ogImageId]
      );

      useEffect(
        function () {
          if (!twitterImageId) {
            setTwitterImageUrl("");
            return;
          }
          apiFetch({ path: "/wp/v2/media/" + twitterImageId })
            .then(function (media) {
              setTwitterImageUrl(media.source_url || "");
            })
            .catch(function () {
              setTwitterImageUrl("");
            });
        },
        [twitterImageId]
      );

      useEffect(
        function () {
          if (!results.length) return;
          expandedChecklistState[1](function (prev) {
            var next = {};
            for (var k in prev) next[k] = prev[k];
            results.forEach(function (r) {
              if ((r.status || "") === "fail" && (r.id || ""))
                next[r.id] = true;
            });
            return next;
          });
        },
        [results]
      );

      useEffect(
        function () {
          if (effectivePostId === 0 || !ajaxUrl) return;
          var t = setTimeout(function () {
            linkSuggestionsLoadingState[1](true);
            var body =
              "action=meyvora_seo_link_suggestions&nonce=" +
              encodeURIComponent(config.linkSuggestionsNonce || "") +
              "&post_id=" +
              effectivePostId;
            fetch(ajaxUrl, {
              method: "POST",
              headers: {
                "Content-Type":
                  "application/x-www-form-urlencoded; charset=UTF-8",
              },
              body: body,
            })
              .then(function (r) {
                return r.json();
              })
              .then(function (data) {
                linkSuggestionsLoadingState[1](false);
                if (
                  data.success &&
                  data.data &&
                  Array.isArray(data.data.suggestions)
                ) {
                  linkSuggestionsState[1](data.data.suggestions.slice(0, 5));
                } else {
                  linkSuggestionsState[1]([]);
                }
              })
              .catch(function () {
                linkSuggestionsLoadingState[1](false);
                linkSuggestionsState[1]([]);
              });
          }, 2000);
          return function () {
            clearTimeout(t);
          };
        },
        [effectivePostId, content, focusKeywordStorage]
      );

      var data = analysisResult[0];
      var savedScore =
        config.currentMeta && config.currentMeta[KEYS.SCORE]
          ? parseInt(config.currentMeta[KEYS.SCORE], 10)
          : null;
      if (savedScore === null || isNaN(savedScore)) savedScore = 0;
      var score =
        data && data.score != null
          ? data.score
          : meta[KEYS.SCORE] !== undefined && meta[KEYS.SCORE] !== ""
            ? parseInt(meta[KEYS.SCORE], 10)
            : savedScore;
      if (score === null || isNaN(score)) score = savedScore;
      var status =
        data && data.status
          ? data.status
          : score >= 80
            ? "good"
            : score >= 50
              ? "okay"
              : "poor";
      var statusLabel =
        status === "good"
          ? i18n.great || "Great!"
          : status === "okay"
            ? i18n.almostThere || "Almost There"
            : i18n.needsWork || "Needs Work";
      var results = data && data.results ? data.results : [];

      var readabilityData = null;
      try {
        var raw = meta[KEYS.READABILITY];
        if (raw && typeof raw === "string") readabilityData = JSON.parse(raw);
      } catch (e) {}

      var schemaTypeOptions = [
        { value: "", label: "None" },
        { value: "Article", label: "Article" },
        { value: "BlogPosting", label: "BlogPosting" },
        { value: "NewsArticle", label: "NewsArticle" },
        { value: "WebPage", label: "WebPage" },
        { value: "FAQPage", label: "FAQPage" },
        { value: "HowTo", label: "HowTo" },
        { value: "Recipe", label: "Recipe" },
        { value: "Event", label: "Event" },
        { value: "Course", label: "Course" },
        { value: "JobPosting", label: "JobPosting" },
        { value: "SoftwareApplication", label: "SoftwareApplication" },
        { value: "Review", label: "Review" },
        { value: "Book", label: "Book" },
        { value: "LocalBusiness", label: "LocalBusiness" },
      ];
      if (config.showStandaloneProduct) {
        schemaTypeOptions.splice(5, 0, { value: "Product", label: "Product (standalone)" });
      }
      var tabs = [
        { name: "seo", title: i18n.seo || "SEO", className: "meyvora-tab-seo" },
        {
          name: "readability",
          title: i18n.readability || "Readability",
          className: "meyvora-tab-readability",
        },
        {
          name: "social",
          title: i18n.social || "Social",
          className: "meyvora-tab-social",
        },
        {
          name: "advanced",
          title: i18n.advanced || "Advanced",
          className: "meyvora-tab-advanced",
        },
        {
          name: "eeat",
          title: i18n.eeat || "E-E-A-T",
          className: "meyvora-tab-eeat",
        },
      ];

      function sendChatMessage() {
        var msg = chatInput.trim();
        if (!msg || chatLoading) return;
        var newHistory = chatHistory.concat([{ role: "user", content: msg }]);
        setChatHistory(newHistory);
        setChatInput("");
        setChatLoading(true);
        var fd = new FormData();
        fd.append("action", "meyvora_seo_ai_request");
        fd.append("action_type", "chat");
        fd.append("message", msg);
        fd.append("nonce", config.aiNonce || config.nonce || "");
        fd.append("post_id", String(effectivePostId));
        fd.append("focus_keyword", focusKeywordsArray[0] || "");
        fd.append("title", postTitle);
        fd.append("content", (contentStr || "").slice(0, 500));
        fetch(window.ajaxurl || config.ajaxUrl || "/wp-admin/admin-ajax.php", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (res) {
            var reply =
              res.success && res.data && res.data.reply
                ? res.data.reply
                : __("Sorry, I could not get a response.", "meyvora-seo");
            setChatHistory(newHistory.concat([{ role: "assistant", content: reply }]));
          })
          .catch(function () {
            setChatHistory(
              newHistory.concat([
                {
                  role: "assistant",
                  content: __("Network error. Please try again.", "meyvora-seo"),
                },
              ])
            );
          })
          .finally(function () {
            setChatLoading(false);
          });
      }

      function switchAbVariant() {
        var fd = new FormData();
        fd.append("action", "meyvora_seo_ab_switch");
        fd.append("nonce", config.abTestNonce || config.nonce || "");
        fd.append("post_id", String(effectivePostId));
        fetch(window.ajaxurl || config.ajaxUrl || "/wp-admin/admin-ajax.php", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (res) {
            if (res.success && res.data && res.data.active) {
              setMeta(KEYS.DESC_AB_ACTIVE, res.data.active);
            }
          });
      }

      function stopAbTest(adoptVariant) {
        var v = adoptVariant || "a";
        var fd = new FormData();
        fd.append("action", "meyvora_seo_ab_stop");
        fd.append("nonce", config.abTestNonce || config.nonce || "");
        fd.append("post_id", String(effectivePostId));
        fd.append("adopt_variant", v);
        fetch(window.ajaxurl || config.ajaxUrl || "/wp-admin/admin-ajax.php", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (res) {
            if (res.success) {
              setMeta(KEYS.DESC_AB_ACTIVE, "");
              var variantKey = v === "a" ? KEYS.DESC_VARIANT_A : KEYS.DESC_VARIANT_B;
              setMeta(KEYS.DESCRIPTION, (meta[variantKey] != null && meta[variantKey] !== "") ? String(meta[variantKey]) : "");
            }
          });
      }

      return el(
        PluginDocumentSettingPanel,
        {
          name: "meyvora-seo-panel",
          title: i18n.panelTitle || "Meyvora SEO",
          className: "meyvora-seo-gutenberg-panel",
        },
        [
          el("div", { key: "header", className: "meyvora-panel-header" }, [
            el(ScoreRing, { score: score, key: "ring" }),
            el(
              "div",
              {
                key: "word-count",
                style: {
                  textAlign: "center",
                  fontSize: "12px",
                  color: "#6b7280",
                  marginTop: "4px",
                  marginBottom: "8px",
                },
              },
              wordCount.toLocaleString(),
              " ",
              __("words", "meyvora-seo")
            ),
            el(
              "span",
              { key: "status", className: "meyvora-panel-status" },
              statusLabel + " — " + score + "/100"
            ),
          ]),
          (config.analysisTimestamp || 0) > 0
            ? el(
                "p",
                {
                  key: "last-analyzed",
                  className: "mev-last-analyzed",
                },
                "Last analyzed: " +
                  new Date(config.analysisTimestamp * 1000).toLocaleDateString()
              )
            : null,
          effectivePostId === 0
            ? el(
                "p",
                { key: "new-post-notice", className: "meyvora-panel-notice" },
                i18n.saveToEnableAnalysis ||
                  "Save the post once to enable live SEO analysis."
              )
            : null,
          el(
            "p",
            { key: "intro", className: "meyvora-panel-intro" },
            i18n.useFieldsBelow ||
              "Use the fields below to set focus keyword, SEO title, and meta description. Analysis updates as you type."
          ),

          el(
            TabPanel,
            {
              key: "tabs",
              className: "meyvora-seo-tab-panel",
              tabs: tabs,
              initialTabName: "seo",
            },
            function (tab) {
              if (tab.name === "seo") {
                var searchIntent = meta[KEYS.SEARCH_INTENT];
                var intentColors = { informational: "#2563eb", navigational: "#7c3aed", commercial: "#d97706", transactional: "#059669" };
                return el(Fragment, { key: "seo" }, [
                  el(FocusKeywordsTagInput, {
                    key: "fk",
                    label: i18n.focusKeywords || "Focus Keywords",
                    keywords: focusKeywordsArray,
                    onChange: function (arr) {
                      setMeta(
                        KEYS.FOCUS_KEYWORD,
                        arr && arr.length ? JSON.stringify(arr) : ""
                      );
                    },
                    max: 5,
                  }),
                  el("div", { key: "intent-row", className: "meyvora-intent-row", style: { marginTop: "8px", display: "flex", alignItems: "center", gap: "8px", flexWrap: "wrap" } }, [
                    searchIntent ? el("span", { key: "intent-badge", style: { background: intentColors[searchIntent] || "#6b7280", color: "#fff", padding: "2px 6px", borderRadius: "3px", fontSize: "11px" } }, (searchIntent.charAt(0).toUpperCase() + searchIntent.slice(1))) : null,
                    el(Button, {
                      key: "classify-intent",
                      isSecondary: true,
                      isSmall: true,
                      disabled: intentClassifyLoadingState[0],
                      onClick: function () {
                        intentClassifyLoadingState[1](true);
                        var form = new FormData();
                        form.append("action", "meyvora_seo_ai_request");
                        form.append("nonce", config.aiNonce || "");
                        form.append("action_type", "classify_intent");
                        form.append("post_id", String(effectivePostId));
                        form.append("title", postTitle || "");
                        form.append("focus_keyword", focusKeywordsArray && focusKeywordsArray[0] ? focusKeywordsArray[0] : "");
                        form.append("content", (content || "").replace(/<[^>]+>/g, " ").slice(0, 5000));
                        var xhr = new XMLHttpRequest();
                        xhr.open("POST", ajaxUrl);
                        xhr.onload = function () {
                          intentClassifyLoadingState[1](false);
                          try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data && res.data.intent) {
                              setMeta(KEYS.SEARCH_INTENT, res.data.intent);
                            }
                          } catch (e) {}
                        };
                        xhr.onerror = function () { intentClassifyLoadingState[1](false); };
                        xhr.send(form);
                      },
                    }, intentClassifyLoadingState[0] ? (i18n.aiGenerating || "Generating…") : __("Classify intent", "meyvora-seo")),
                  ]),
                  config.keywordResearchEnabled
                    ? el(
                        "div",
                        {
                          key: "keyword-research-wrap",
                          className: "meyvora-keyword-research-wrap",
                        },
                        [
                          el(
                            "button",
                            {
                              key: "kr-toggle",
                              type: "button",
                              className: "meyvora-keyword-research-toggle",
                              "aria-expanded": keywordResearchOpenState[0],
                              onClick: function () {
                                keywordResearchOpenState[1](!keywordResearchOpenState[0]);
                              },
                            },
                            (i18n.keywordResearch || "Keyword Research") +
                              (keywordResearchOpenState[0] ? " ▲" : " ▼")
                          ),
                          keywordResearchOpenState[0]
                            ? el(
                                "div",
                                {
                                  key: "kr-panel",
                                  className: "meyvora-keyword-research-panel",
                                },
                                [
                                  el(
                                    Button,
                                    {
                                      key: "kr-btn",
                                      isSmall: true,
                                      isSecondary: true,
                                      disabled:
                                        keywordResearchLoadingState[0] ||
                                        !(
                                          focusKeywordsArray &&
                                          focusKeywordsArray[0]
                                        ),
                                      onClick: function () {
                                        var kw =
                                          focusKeywordsArray && focusKeywordsArray[0]
                                            ? focusKeywordsArray[0]
                                            : "";
                                        if (!kw) return;
                                        keywordResearchResultState[1](null);
                                        keywordResearchLoadingState[1](true);
                                        var form = new FormData();
                                        form.append(
                                          "action",
                                          "meyvora_seo_keyword_research"
                                        );
                                        form.append("nonce", nonce);
                                        form.append("keyword", kw);
                                        var xhr = new XMLHttpRequest();
                                        xhr.open("POST", ajaxUrl);
                                        xhr.onload = function () {
                                          keywordResearchLoadingState[1](false);
                                          try {
                                            var res = JSON.parse(xhr.responseText);
                                            if (res.success && res.data) {
                                              keywordResearchResultState[1](res.data);
                                            } else {
                                              keywordResearchResultState[1]({
                                                error:
                                                  (res.data && res.data.message) ||
                                                  (i18n.keywordResearchError ||
                                                    "Request failed."),
                                              });
                                            }
                                          } catch (e) {
                                            keywordResearchResultState[1]({
                                              error:
                                                i18n.keywordResearchError ||
                                                "Request failed.",
                                            });
                                          }
                                        };
                                        xhr.onerror = function () {
                                          keywordResearchLoadingState[1](false);
                                          keywordResearchResultState[1]({
                                            error:
                                              i18n.keywordResearchError ||
                                              "Request failed.",
                                          });
                                        };
                                        xhr.send(form);
                                      },
                                    },
                                    keywordResearchLoadingState[0]
                                      ? (i18n.keywordResearching || "Researching…")
                                      : (i18n.keywordResearchBtn || "Research")
                                  ),
                                  keywordResearchResultState[0]
                                    ? keywordResearchResultState[0].error
                                      ? el(
                                          "p",
                                          {
                                            key: "kr-err",
                                            className: "meyvora-keyword-research-error",
                                          },
                                          keywordResearchResultState[0].error
                                        )
                                      : el(
                                          "div",
                                          {
                                            key: "kr-table-wrap",
                                            className: "meyvora-keyword-research-table-wrap",
                                          },
                                          [
                                            el(
                                              "table",
                                              {
                                                key: "kr-table",
                                                className: "meyvora-keyword-research-table",
                                              },
                                              [
                                                el("thead", { key: "thead" }, [
                                                  el("tr", { key: "tr" }, [
                                                    el("th", { key: "tk" }, i18n.keyword || "Keyword"),
                                                    el("th", { key: "tv" }, i18n.keywordVolume || "Volume"),
                                                    el("th", { key: "tc" }, i18n.keywordCompetition || "Competition"),
                                                    el("th", { key: "tcp" }, i18n.keywordCpc || "CPC"),
                                                    el("th", { key: "ta" }, ""),
                                                  ]),
                                                ]),
                                                el("tbody", { key: "tbody" }, [
                                                  el("tr", { key: "seed" }, [
                                                    el("td", { key: "k" }, keywordResearchResultState[0].keyword),
                                                    el("td", { key: "v" }, String(keywordResearchResultState[0].search_volume || 0)),
                                                    el("td", { key: "c" }, String((keywordResearchResultState[0].competition || 0).toFixed(2))),
                                                    el("td", { key: "cp" }, "$" + (keywordResearchResultState[0].cpc || 0).toFixed(2)),
                                                    el("td", { key: "a" }, "—"),
                                                  ]),
                                                  (keywordResearchResultState[0].suggestions || []).map(
                                                    function (sug, idx) {
                                                      return el(
                                                        "tr",
                                                        { key: "sug-" + idx },
                                                        [
                                                          el("td", { key: "k" }, sug.keyword || ""),
                                                          el("td", { key: "v" }, String(sug.search_volume || 0)),
                                                          el("td", { key: "c" }, String((sug.competition || 0).toFixed(2))),
                                                          el("td", { key: "cp" }, "$" + (sug.cpc || 0).toFixed(2)),
                                                          el(
                                                            "td",
                                                            { key: "a" },
                                                            el(
                                                              Button,
                                                              {
                                                                isSmall: true,
                                                                isSecondary: true,
                                                                onClick: function () {
                                                                  var next = (focusKeywordsArray || []).slice();
                                                                  if (next.indexOf(sug.keyword) === -1 && next.length < 5) {
                                                                    next.push(sug.keyword);
                                                                    setMeta(KEYS.FOCUS_KEYWORD, JSON.stringify(next));
                                                                  }
                                                                },
                                                              },
                                                              "+"
                                                            )
                                                          ),
                                                        ]
                                                      );
                                                    }
                                                  ),
                                                ]),
                                              ]
                                            ),
                                          ]
                                        )
                                    : null,
                                ]
                              )
                            : null,
                        ]
                      )
                    : null,
                  el(TextControl, {
                    key: "st",
                    label: i18n.seoTitle || "SEO Title",
                    value: seoTitle,
                    onChange: function (v) {
                      setMeta(KEYS.TITLE, v || "");
                    },
                  }),
                  config.aiEnabled
                    ? el(
                        "div",
                        {
                          key: "ai-title-wrap",
                          className: "meyvora-ai-field-wrap",
                        },
                        [
                          el(
                            Button,
                            {
                              key: "ai-title-btn",
                              isSmall: true,
                              isSecondary: true,
                              disabled: aiLoadingTitleState[0],
                              onClick: function () {
                                aiErrorState[1](null);
                                aiTitleOptionsState[1](null);
                                aiLoadingTitleState[1](true);
                                var form = new FormData();
                                form.append("action", "meyvora_seo_ai_request");
                                form.append("nonce", config.aiNonce || "");
                                form.append("action_type", "generate_title");
                                form.append("post_id", String(effectivePostId));
                                form.append(
                                  "content",
                                  (content || "")
                                    .replace(/<[^>]+>/g, " ")
                                    .slice(0, 5000)
                                );
                                form.append(
                                  "focus_keyword",
                                  focusKeywordsArray && focusKeywordsArray[0]
                                    ? focusKeywordsArray[0]
                                    : ""
                                );
                                var xhr = new XMLHttpRequest();
                                xhr.open("POST", ajaxUrl);
                                xhr.onload = function () {
                                  aiLoadingTitleState[1](false);
                                  try {
                                    var res = JSON.parse(xhr.responseText);
                                    if (
                                      res.success &&
                                      res.data &&
                                      Array.isArray(res.data.options)
                                    ) {
                                      aiTitleOptionsState[1](res.data.options);
                                    } else {
                                      aiErrorState[1](
                                        i18n.aiUnavailable ||
                                          "AI unavailable. Check Settings > AI."
                                      );
                                    }
                                  } catch (e) {
                                    aiErrorState[1](
                                      i18n.aiUnavailable ||
                                        "AI unavailable. Check Settings > AI."
                                    );
                                  }
                                };
                                xhr.onerror = function () {
                                  aiLoadingTitleState[1](false);
                                  aiErrorState[1](
                                    i18n.aiUnavailable ||
                                      "AI unavailable. Check Settings > AI."
                                  );
                                };
                                xhr.send(form);
                              },
                            },
                            aiLoadingTitleState[0]
                              ? i18n.aiGenerating || "Generating…"
                              : i18n.aiGenerate || "✨ Generate"
                          ),
                          aiTitleOptionsState[0] &&
                          aiTitleOptionsState[0].length
                            ? el(
                                "div",
                                {
                                  key: "ai-title-options",
                                  className: "meyvora-ai-options",
                                },
                                [
                                  el(
                                    "div",
                                    {
                                      key: "ai-title-label",
                                      className: "meyvora-ai-options-label",
                                    },
                                    i18n.aiChooseTitle || "Choose a title"
                                  ),
                                  aiTitleOptionsState[0].map(function (opt, i) {
                                    return el(
                                      "div",
                                      {
                                        key: "opt-" + i,
                                        className: "meyvora-ai-option-row",
                                      },
                                      [
                                        el(
                                          "span",
                                          {
                                            key: "t",
                                            className: "meyvora-ai-option-text",
                                          },
                                          opt
                                        ),
                                        el(
                                          Button,
                                          {
                                            key: "b",
                                            isSmall: true,
                                            isSecondary: true,
                                            onClick: function () {
                                              setMeta(KEYS.TITLE, opt);
                                              aiTitleOptionsState[1](null);
                                            },
                                          },
                                          i18n.aiUseThis || "Use this"
                                        ),
                                      ]
                                    );
                                  }),
                                ]
                              )
                            : null,
                        ]
                      )
                    : el(
                        "p",
                        {
                          key: "ai-settings-link",
                          className: "meyvora-ai-settings-link",
                        },
                        el(
                          "a",
                          {
                            href:
                              config.settingsUrl ||
                              "admin.php?page=meyvora-seo-settings#tab-ai",
                            target: "_blank",
                            rel: "noopener",
                          },
                          i18n.aiEnableInSettings ||
                            "Enable AI features in Settings"
                        )
                      ),
                  el(CharCountBar, {
                    key: "st-count",
                    current: seoTitle.length,
                    min: titleMin,
                    max: titleMax,
                  }),
                  el(TextareaControl, {
                    key: "md",
                    label: i18n.metaDesc || "Meta Description",
                    value: metaDesc,
                    onChange: function (v) {
                      setMeta(KEYS.DESCRIPTION, v || "");
                    },
                    rows: 3,
                  }),
                  config.aiEnabled
                    ? el(
                        "div",
                        {
                          key: "ai-desc-wrap",
                          className: "meyvora-ai-field-wrap",
                        },
                        [
                          el(
                            Button,
                            {
                              key: "ai-desc-btn",
                              isSmall: true,
                              isSecondary: true,
                              disabled: aiLoadingDescState[0],
                              onClick: function () {
                                aiErrorState[1](null);
                                aiDescOptionsState[1](null);
                                aiLoadingDescState[1](true);
                                var form = new FormData();
                                form.append("action", "meyvora_seo_ai_request");
                                form.append("nonce", config.aiNonce || "");
                                form.append(
                                  "action_type",
                                  "generate_description"
                                );
                                form.append("post_id", String(effectivePostId));
                                form.append(
                                  "content",
                                  (content || "")
                                    .replace(/<[^>]+>/g, " ")
                                    .slice(0, 5000)
                                );
                                form.append(
                                  "focus_keyword",
                                  focusKeywordsArray && focusKeywordsArray[0]
                                    ? focusKeywordsArray[0]
                                    : ""
                                );
                                var xhr = new XMLHttpRequest();
                                xhr.open("POST", ajaxUrl);
                                xhr.onload = function () {
                                  aiLoadingDescState[1](false);
                                  try {
                                    var res = JSON.parse(xhr.responseText);
                                    if (
                                      res.success &&
                                      res.data &&
                                      Array.isArray(res.data.options)
                                    ) {
                                      aiDescOptionsState[1](res.data.options);
                                    } else {
                                      aiErrorState[1](
                                        i18n.aiUnavailable ||
                                          "AI unavailable. Check Settings > AI."
                                      );
                                    }
                                  } catch (e) {
                                    aiErrorState[1](
                                      i18n.aiUnavailable ||
                                        "AI unavailable. Check Settings > AI."
                                    );
                                  }
                                };
                                xhr.onerror = function () {
                                  aiLoadingDescState[1](false);
                                  aiErrorState[1](
                                    i18n.aiUnavailable ||
                                      "AI unavailable. Check Settings > AI."
                                  );
                                };
                                xhr.send(form);
                              },
                            },
                            aiLoadingDescState[0]
                              ? i18n.aiGenerating || "Generating…"
                              : i18n.aiGenerate || "✨ Generate"
                          ),
                          aiDescOptionsState[0] && aiDescOptionsState[0].length
                            ? el(
                                "div",
                                {
                                  key: "ai-desc-options",
                                  className: "meyvora-ai-options",
                                },
                                [
                                  el(
                                    "div",
                                    {
                                      key: "ai-desc-label",
                                      className: "meyvora-ai-options-label",
                                    },
                                    i18n.aiChooseDescription ||
                                      "Choose a description"
                                  ),
                                  aiDescOptionsState[0].map(function (opt, i) {
                                    return el(
                                      "div",
                                      {
                                        key: "opt-" + i,
                                        className: "meyvora-ai-option-row",
                                      },
                                      [
                                        el(
                                          "span",
                                          {
                                            key: "t",
                                            className: "meyvora-ai-option-text",
                                          },
                                          opt
                                        ),
                                        el(
                                          Button,
                                          {
                                            key: "b",
                                            isSmall: true,
                                            isSecondary: true,
                                            onClick: function () {
                                              setMeta(KEYS.DESCRIPTION, opt);
                                              aiDescOptionsState[1](null);
                                            },
                                          },
                                          i18n.aiUseThis || "Use this"
                                        ),
                                      ]
                                    );
                                  }),
                                ]
                              )
                            : null,
                        ]
                      )
                    : null,
                  aiErrorState[0]
                    ? el(
                        "p",
                        { key: "ai-error", className: "meyvora-ai-error" },
                        aiErrorState[0]
                      )
                    : null,
                  el(CharCountBar, {
                    key: "md-count",
                    current: metaDesc.length,
                    min: descMin,
                    max: descMax,
                  }),
                  el(
                    "div",
                    { key: "ab-panel-wrap", style: { marginTop: "12px" } },
                    [
                      el(
                        Button,
                        {
                          key: "ab-toggle",
                          isSecondary: true,
                          isSmall: true,
                          onClick: function () {
                            abPanelOpenState[1](!abPanelOpenState[0]);
                          },
                        },
                        abPanelOpenState[0]
                          ? __("A/B Test ▲", "meyvora-seo")
                          : __("A/B Test ▼", "meyvora-seo")
                      ),
                      abPanelOpenState[0]
                        ? el(
                            "div",
                            {
                              key: "ab-content",
                              style: {
                                marginTop: "10px",
                                padding: "10px",
                                border: "1px solid #ddd",
                                borderRadius: "4px",
                              },
                            },
                            [
                              el(
                                Button,
                                {
                                  key: "ab-gen-variants",
                                  isSmall: true,
                                  isSecondary: true,
                                  disabled: aiDescVariantsLoadingState[0],
                                  onClick: function () {
                                    aiDescVariantsLoadingState[1](true);
                                    var form = new FormData();
                                    form.append("action", "meyvora_seo_ai_request");
                                    form.append("nonce", config.aiNonce || "");
                                    form.append(
                                      "action_type",
                                      "generate_desc_variants"
                                    );
                                    form.append(
                                      "post_id",
                                      String(effectivePostId)
                                    );
                                    form.append(
                                      "content",
                                      (content || "")
                                        .replace(/<[^>]+>/g, " ")
                                        .slice(0, 5000)
                                    );
                                    form.append(
                                      "focus_keyword",
                                      focusKeywordsArray &&
                                        focusKeywordsArray[0]
                                        ? focusKeywordsArray[0]
                                        : ""
                                    );
                                    var xhr = new XMLHttpRequest();
                                    xhr.open("POST", ajaxUrl);
                                    xhr.onload = function () {
                                      aiDescVariantsLoadingState[1](false);
                                      try {
                                        var res = JSON.parse(
                                          xhr.responseText
                                        );
                                        if (
                                          res.success &&
                                          res.data &&
                                          res.data.variant_a != null &&
                                          res.data.variant_b != null
                                        ) {
                                          setMeta(
                                            KEYS.DESC_VARIANT_A,
                                            String(res.data.variant_a)
                                          );
                                          setMeta(
                                            KEYS.DESC_VARIANT_B,
                                            String(res.data.variant_b)
                                          );
                                        }
                                      } catch (e) {}
                                    };
                                    xhr.onerror = function () {
                                      aiDescVariantsLoadingState[1](false);
                                    };
                                    xhr.send(form);
                                  },
                                },
                                aiDescVariantsLoadingState[0]
                                  ? __("Generating…", "meyvora-seo")
                                  : __(
                                      "Generate 2 variants with AI",
                                      "meyvora-seo"
                                    )
                              ),
                              el(TextareaControl, {
                                key: "ab-va",
                                label: __("Variant A", "meyvora-seo"),
                                value: descVariantA,
                                onChange: function (v) {
                                  setMeta(
                                    KEYS.DESC_VARIANT_A,
                                    v != null && v !== "" ? String(v) : ""
                                  );
                                },
                                rows: 2,
                                style: { marginTop: "8px" },
                              }),
                              el(TextareaControl, {
                                key: "ab-vb",
                                label: __("Variant B", "meyvora-seo"),
                                value: descVariantB,
                                onChange: function (v) {
                                  setMeta(
                                    KEYS.DESC_VARIANT_B,
                                    v != null && v !== "" ? String(v) : ""
                                  );
                                },
                                rows: 2,
                                style: { marginTop: "8px" },
                              }),
                              descAbActive !== "a" && descAbActive !== "b"
                                ? el(
                                    Button,
                                    {
                                      key: "ab-start",
                                      isPrimary: true,
                                      isSmall: true,
                                      style: { marginTop: "8px" },
                                      onClick: function () {
                                        if (
                                          !descVariantA.trim() ||
                                          !descVariantB.trim()
                                        ) {
                                          return;
                                        }
                                        setMeta(KEYS.DESC_AB_ACTIVE, "a");
                                        setMeta(
                                          KEYS.DESC_AB_START,
                                          String(
                                            Math.floor(
                                              Date.now() / 1000
                                            )
                                          )
                                        );
                                        setMeta(KEYS.DESC_AB_RESULT, "");
                                      },
                                    },
                                    __("Start A/B Test", "meyvora-seo")
                                  )
                                : el("div", { key: "ab-running", style: { marginTop: "8px" } }, [
                                    (function () {
                                      var startTs = parseInt(
                                        descAbStart,
                                        10
                                      ) || 0;
                                      var days = startTs
                                        ? Math.floor(
                                            (Date.now() / 1000 - startTs) /
                                              86400
                                          )
                                        : 0;
                                      return el(
                                        "p",
                                        {
                                          key: "ab-status",
                                          style: {
                                            margin: "0 0 8px",
                                            fontSize: "13px",
                                          },
                                        },
                                        __("Current variant: ", "meyvora-seo") +
                                          descAbActive.toUpperCase() +
                                          " · " +
                                          (days === 1
                                            ? __("1 day running", "meyvora-seo")
                                            : __(
                                                days + " days running",
                                                "meyvora-seo"
                                              ))
                                      );
                                    })(),
                                    el(
                                      "div",
                                      {
                                        key: "ab-switch-row",
                                        style: {
                                          display: "flex",
                                          gap: "6px",
                                          marginBottom: "8px",
                                        },
                                      },
                                      [
                                        el(
                                          Button,
                                          {
                                            key: "sw-a",
                                            isSmall: true,
                                            isSecondary: true,
                                            onClick: switchAbVariant,
                                          },
                                          "⇄ " + __("Serve A", "meyvora-seo")
                                        ),
                                        el(
                                          Button,
                                          {
                                            key: "sw-b",
                                            isSmall: true,
                                            isSecondary: true,
                                            onClick: switchAbVariant,
                                          },
                                          "⇄ " + __("Serve B", "meyvora-seo")
                                        ),
                                      ]
                                    ),
                                    el("hr", {
                                      key: "ab-hr",
                                      style: {
                                        margin: "8px 0",
                                        borderColor: "#e5e7eb",
                                      },
                                    }),
                                    el(
                                      "p",
                                      {
                                        key: "ab-end-heading",
                                        style: {
                                          fontSize: "12px",
                                          fontWeight: "600",
                                          margin: "0 0 4px",
                                        },
                                      },
                                      __("End test", "meyvora-seo")
                                    ),
                                    el(
                                      "p",
                                      {
                                        key: "ab-end-label",
                                        style: {
                                          fontSize: "11px",
                                          color: "#6b7280",
                                          margin: "0 0 6px",
                                        },
                                      },
                                      __(
                                        "End test — permanently adopt winning variant:",
                                        "meyvora-seo"
                                      )
                                    ),
                                    el(
                                      "div",
                                      {
                                        key: "ab-stop-row",
                                        style: { display: "flex", gap: "6px" },
                                      },
                                      [
                                        el(
                                          Button,
                                          {
                                            key: "stop-a",
                                            isSmall: true,
                                            isDestructive: true,
                                            onClick: function () {
                                              stopAbTest("a");
                                            },
                                          },
                                          __("Adopt A", "meyvora-seo")
                                        ),
                                        el(
                                          Button,
                                          {
                                            key: "stop-b",
                                            isSmall: true,
                                            isDestructive: true,
                                            onClick: function () {
                                              stopAbTest("b");
                                            },
                                          },
                                          __("Adopt B", "meyvora-seo")
                                        ),
                                      ]
                                    ),
                                  ]),
                              (function () {
                                if (!descAbResultRaw) return null;
                                try {
                                  var result = JSON.parse(descAbResultRaw);
                                  if (
                                    !result ||
                                    typeof result.winner === "undefined"
                                  )
                                    return null;
                                  var aCtr = Number(result.a_ctr) || 0;
                                  var bCtr = Number(result.b_ctr) || 0;
                                  return el(
                                    "div",
                                    {
                                      key: "ab-result",
                                      style: {
                                        marginTop: "12px",
                                        padding: "10px",
                                        background: "#f0fdf4",
                                        borderRadius: "6px",
                                        fontSize: "13px",
                                      },
                                    },
                                    [
                                      el(
                                        "strong",
                                        { key: "ab-winner" },
                                        __("Winner: Variant ", "meyvora-seo") +
                                          result.winner.toUpperCase()
                                      ),
                                      el(
                                        "p",
                                        {
                                          key: "ab-ctr",
                                          style: { margin: "6px 0 0" },
                                        },
                                        "A: " +
                                          aCtr.toFixed(1) +
                                          "% CTR · B: " +
                                          bCtr.toFixed(1) +
                                          "% CTR"
                                      ),
                                    ]
                                  );
                                } catch (e) {
                                  return null;
                                }
                              })(),
                            ]
                          )
                        : null,
                    ]
                  ),
                  el(
                    "div",
                    { key: "preview", className: "meyvora-snippet-wrap" },
                    [
                      el(
                        "strong",
                        { key: "pl" },
                        __("Google Preview", "meyvora-seo")
                      ),
                      el(
                        "div",
                        {
                          key: "snippet-toggle",
                          className: "meyvora-snippet-toggle",
                        },
                        [
                          el(
                            Button,
                            {
                              key: "desk",
                              isSecondary: true,
                              className:
                                previewMode === "desktop" ? "is-active" : "",
                              onClick: function () {
                                setPreviewMode("desktop");
                              },
                            },
                            __("Desktop", "meyvora-seo")
                          ),
                          el(
                            Button,
                            {
                              key: "mob",
                              isSecondary: true,
                              className:
                                previewMode === "mobile" ? "is-active" : "",
                              onClick: function () {
                                setPreviewMode("mobile");
                              },
                            },
                            __("Mobile", "meyvora-seo")
                          ),
                        ]
                      ),
                      el(SnippetPreview, {
                        key: "snip",
                        title: seoTitle,
                        description: metaDesc,
                        siteDomain:
                          typeof window.meyvoraSeoBlock !== "undefined" &&
                          window.meyvoraSeoBlock.siteUrl
                            ? window.meyvoraSeoBlock.siteUrl
                            : "example.com",
                        postCategories: postCategories,
                        postSlug: postSlug,
                        previewMode: previewMode,
                      }),
                    ]
                  ),
                  el(
                    "div",
                    {
                      key: "seo-checklist-wrap",
                      className: "meyvora-seo-checklist-wrap",
                    },
                    [
                      el(
                        "strong",
                        { key: "cl" },
                        i18n.seoChecklist || "SEO checklist"
                      ),
                      results.length === 0
                        ? el(
                            "p",
                            {
                              key: "seo-analyzing",
                              className: "meyvora-checklist-placeholder",
                            },
                            i18n.analyzing || "Analyzing…"
                          )
                        : (function () {
                            var seoResults = results.filter(function (r) {
                              var id = (r.id || "") + "";
                              return (
                                id.indexOf("sentence_length") === -1 &&
                                id.indexOf("passive_voice") === -1 &&
                                id.indexOf("flesch") === -1 &&
                                id.indexOf("transition") === -1 &&
                                id.indexOf("paragraph") === -1
                              );
                            });
                            var expanded = expandedChecklistState[0];
                            var setExpanded = expandedChecklistState[1];
                            return el(
                              "ul",
                              {
                                key: "seo-list",
                                className: "meyvora-gutenberg-checklist",
                              },
                              seoResults.map(function (r, i) {
                                var status = r.status || "";
                                var icon =
                                  status === "pass"
                                    ? "✅"
                                    : status === "warning"
                                      ? "⚠️"
                                      : "❌";
                                var id = r.id || "item-" + i;
                                var isExpanded = !!expanded[id];
                                var statusClass =
                                  status === "pass"
                                    ? "mev-check-pass"
                                    : status === "warning"
                                      ? "mev-check-warning"
                                      : "mev-check-fail";
                                return el(
                                  "li",
                                  {
                                    key: id,
                                    className: "is-" + status + " " + statusClass,
                                  },
                                  [
                                    el(
                                      "span",
                                      {
                                        key: "msg",
                                        className: "mev-check-message",
                                      },
                                      [icon + " ", r.message || r.label || ""]
                                    ),
                                    el(
                                      "button",
                                      {
                                        key: "tog",
                                        type: "button",
                                        className: "mev-check-toggle",
                                        "aria-expanded": isExpanded,
                                        onClick: function () {
                                          setExpanded(function (prev) {
                                            var next = {};
                                            for (var k in prev)
                                              next[k] = prev[k];
                                            next[id] = !prev[id];
                                            return next;
                                          });
                                        },
                                      },
                                      el(
                                        "svg",
                                        {
                                          width: 10,
                                          height: 10,
                                          viewBox: "0 0 10 10",
                                          style: {
                                            transform: isExpanded
                                              ? "rotate(180deg)"
                                              : "rotate(0deg)",
                                            transition: "transform 0.2s ease",
                                          },
                                        },
                                        el("path", {
                                          d: "M1 3l4 4 4-4",
                                          stroke: "currentColor",
                                          strokeWidth: 1.5,
                                          fill: "none",
                                          strokeLinecap: "round",
                                        })
                                      )
                                    ),
                                    el("div", {
                                      key: "hint",
                                      className: "mev-check-hint",
                                      style: {
                                        maxHeight: isExpanded ? "200px" : "0",
                                        overflow: "hidden",
                                        transition:
                                          "max-height 0.2s ease, padding-top 0.2s ease",
                                        paddingTop: isExpanded ? "6px" : "0",
                                      },
                                    }, fixHints[id] || defaultHint),
                                  ]
                                );
                              })
                            );
                          })(),
                    ]
                  ),
                  el(
                    "div",
                    {
                      key: "link-suggestions-wrap",
                      className: "meyvora-link-suggestions-wrap",
                    },
                    [
                      el(
                        "button",
                        {
                          key: "link-toggle",
                          type: "button",
                          className: "meyvora-link-suggestions-toggle",
                          "aria-expanded": linkSuggestionsOpenState[0],
                          onClick: function () {
                            linkSuggestionsOpenState[1](
                              !linkSuggestionsOpenState[0]
                            );
                          },
                        },
                        [
                          el(
                            "span",
                            {
                              key: "arrow",
                              className:
                                "meyvora-link-suggestions-arrow" +
                                (linkSuggestionsOpenState[0] ? " is-open" : ""),
                            },
                            "▶"
                          ),
                          el(
                            "span",
                            { key: "title" },
                            i18n.linkSuggestionsTitle ||
                              "Internal Link Suggestions"
                          ),
                        ]
                      ),
                      linkSuggestionsOpenState[0]
                        ? el(
                            "div",
                            {
                              key: "link-body",
                              className: "meyvora-link-suggestions-body",
                            },
                            [
                              linkSuggestionsLoadingState[0]
                                ? el(
                                    "div",
                                    {
                                      key: "link-spinner",
                                      className:
                                        "meyvora-link-suggestions-loading",
                                    },
                                    el(Spinner)
                                  )
                                : null,
                              !linkSuggestionsLoadingState[0] &&
                              linkSuggestionsState[0].length === 0
                                ? el(
                                    "p",
                                    {
                                      key: "link-none",
                                      className:
                                        "meyvora-link-suggestions-none",
                                    },
                                    i18n.linkNoSuggestions ||
                                      "No suggestions yet. Add a focus keyword first."
                                  )
                                : null,
                              !linkSuggestionsLoadingState[0] &&
                              linkSuggestionsState[0].length > 0
                                ? linkSuggestionsState[0]
                                    .slice(0, 5)
                                    .map(function (s, i) {
                                      var title =
                                        (s.title || "").length > 40
                                          ? (s.title || "").slice(0, 37) + "..."
                                          : s.title || "";
                                      var url = s.url || "";
                                      return el(
                                        "div",
                                        {
                                          key: "link-" + i,
                                          className:
                                            "meyvora-link-suggestion-row",
                                        },
                                        [
                                          el(
                                            "span",
                                            {
                                              key: "t",
                                              className:
                                                "meyvora-link-suggestion-title",
                                            },
                                            title
                                          ),
                                          el(
                                            Button,
                                            {
                                              key: "b",
                                              isSmall: true,
                                              isSecondary: true,
                                              onClick: function () {
                                                if (
                                                  url &&
                                                  typeof navigator !==
                                                    "undefined" &&
                                                  navigator.clipboard &&
                                                  navigator.clipboard.writeText
                                                ) {
                                                  navigator.clipboard.writeText(
                                                    url
                                                  );
                                                  linkCopiedIndexState[1](i);
                                                  setTimeout(function () {
                                                    linkCopiedIndexState[1](
                                                      null
                                                    );
                                                  }, 2000);
                                                }
                                              },
                                            },
                                            linkCopiedIndexState[0] === i
                                              ? i18n.linkCopied || "Copied!"
                                              : i18n.linkCopy || "Copy Link"
                                          ),
                                        ]
                                      );
                                    })
                                : null,
                            ]
                          )
                        : null,
                    ]
                  ),
                ]);
              }
              if (tab.name === "readability") {
                var readScore =
                  readabilityData && readabilityData.score != null
                    ? readabilityData.score
                    : "—";
                var readLabel =
                  readabilityData && readabilityData.label
                    ? readabilityData.label
                    : __("Not analyzed", "meyvora-seo");
                return el(Fragment, { key: "read" }, [
                  el(
                    "div",
                    { key: "rhead", className: "meyvora-readability-head" },
                    [
                      el("strong", null, __("Readability", "meyvora-seo")),
                      el(
                        "span",
                        { className: "meyvora-readability-value" },
                        readScore + " / 100 · " + readLabel
                      ),
                    ]
                  ),
                  results.length
                    ? el(
                        "ul",
                        {
                          key: "rlist",
                          className: "meyvora-gutenberg-checklist",
                        },
                        results
                          .filter(function (r) {
                            return (
                              (r.id || "").indexOf("sentence_length") !== -1 ||
                              (r.id || "").indexOf("passive_voice") !== -1 ||
                              (r.id || "").indexOf("flesch") !== -1 ||
                              (r.id || "").indexOf("transition") !== -1 ||
                              (r.id || "").indexOf("paragraph") !== -1
                            );
                          })
                          .slice(0, 6)
                          .map(function (r, i) {
                            var status = r.status || "";
                            var icon =
                              status === "pass"
                                ? "✅"
                                : status === "warning"
                                  ? "⚠️"
                                  : "❌";
                            var id = r.id || "read-" + i;
                            var isExpanded = !!expandedChecklistState[0][id];
                            var statusClass =
                              status === "pass"
                                ? "mev-check-pass"
                                : status === "warning"
                                  ? "mev-check-warning"
                                  : "mev-check-fail";
                            return el(
                              "li",
                              {
                                key: id,
                                className: "is-" + status + " " + statusClass,
                              },
                              [
                                el(
                                  "span",
                                  {
                                    key: "msg",
                                    className: "mev-check-message",
                                  },
                                  [icon + " ", r.message || r.label || ""]
                                ),
                                el(
                                  "button",
                                  {
                                    key: "tog",
                                    type: "button",
                                    className: "mev-check-toggle",
                                    "aria-expanded": isExpanded,
                                    onClick: function () {
                                      expandedChecklistState[1](
                                        function (prev) {
                                          var next = {};
                                          for (var k in prev) next[k] = prev[k];
                                          next[id] = !prev[id];
                                          return next;
                                        }
                                      );
                                    },
                                  },
                                  el(
                                    "svg",
                                    {
                                      width: 10,
                                      height: 10,
                                      viewBox: "0 0 10 10",
                                      style: {
                                        transform: isExpanded
                                          ? "rotate(180deg)"
                                          : "rotate(0deg)",
                                        transition: "transform 0.2s ease",
                                      },
                                    },
                                    el("path", {
                                      d: "M1 3l4 4 4-4",
                                      stroke: "currentColor",
                                      strokeWidth: 1.5,
                                      fill: "none",
                                      strokeLinecap: "round",
                                    })
                                  )
                                ),
                                el("div", {
                                  key: "hint",
                                  className: "mev-check-hint",
                                  style: {
                                    maxHeight: isExpanded ? "200px" : "0",
                                    overflow: "hidden",
                                    transition:
                                      "max-height 0.2s ease, padding-top 0.2s ease",
                                    paddingTop: isExpanded ? "6px" : "0",
                                  },
                                }, fixHints[id] || defaultHint),
                              ]
                            );
                          })
                      )
                    : el(
                        "p",
                        { key: "rnone" },
                        __(
                          "Save or run analysis to see readability details.",
                          "meyvora-seo"
                        )
                      ),
                ]);
              }
              if (tab.name === "social") {
                var ogImageUrl = ogImageUrlState[0];
                var twitterImageUrl = twitterImageUrlState[0];
                var siteUrlRaw =
                  typeof window.meyvoraSeoBlock !== "undefined" &&
                  window.meyvoraSeoBlock.siteUrl
                    ? window.meyvoraSeoBlock.siteUrl
                    : "example.com";
                var siteDomain = siteUrlRaw
                  .replace(/^https?:\/\/(www\.)?/i, "")
                  .split("/")[0] || "example.com";
                function truncate(str, len) {
                  if (!str || str.length <= len) return str || "";
                  return str.slice(0, len) + "…";
                }
                var socialTitle = ogTitle || seoTitle;
                var socialDesc = ogDesc || metaDesc;
                function openOgMedia() {
                  if (typeof wp === "undefined" || !wp.media) return;
                  var frame = wp.media({
                    title: __("Select OG Image", "meyvora-seo"),
                    multiple: false,
                    library: { type: "image" },
                  });
                  frame.on("select", function () {
                    var att = frame.state().get("selection").first().toJSON();
                    setMeta(KEYS.OG_IMAGE, att.id ? parseInt(att.id, 10) : 0);
                  });
                  frame.open();
                }
                function openTwitterMedia() {
                  if (typeof wp === "undefined" || !wp.media) return;
                  var frame = wp.media({
                    title: __("Select Twitter Image", "meyvora-seo"),
                    multiple: false,
                    library: { type: "image" },
                  });
                  frame.on("select", function () {
                    var att = frame.state().get("selection").first().toJSON();
                    setMeta(
                      KEYS.TWITTER_IMAGE,
                      att.id ? parseInt(att.id, 10) : 0
                    );
                  });
                  frame.open();
                }
                return el(Fragment, { key: "soc" }, [
                  el(
                    Button,
                    {
                      key: "copy-social",
                      variant: "secondary",
                      isSmall: true,
                      onClick: function () {
                        var currentMeta =
                          (wp.data && wp.data.select("core/editor") && wp.data.select("core/editor").getEditedPostAttribute("meta")) || {};
                        var merged = Object.assign({}, currentMeta, {
                          [KEYS.OG_TITLE]: seoTitle,
                          [KEYS.OG_DESCRIPTION]: metaDesc,
                          [KEYS.TWITTER_TITLE]: seoTitle,
                          [KEYS.TWITTER_DESCRIPTION]: metaDesc,
                        });
                        editPost({ meta: merged });
                      },
                    },
                    __("Copy SEO title & description to Social", "meyvora-seo")
                  ),
                  el(TextControl, {
                    key: "ogt",
                    label: i18n.ogTitle || "OG Title",
                    value: ogTitle,
                    onChange: function (v) {
                      setMeta(KEYS.OG_TITLE, v || "");
                    },
                  }),
                  el(CharCountBar, {
                    key: "ogt-count",
                    current: ogTitle.length,
                    min: 0,
                    max: 88,
                  }),
                  el(TextareaControl, {
                    key: "ogd",
                    label: i18n.ogDesc || "OG Description",
                    value: ogDesc,
                    onChange: function (v) {
                      setMeta(KEYS.OG_DESCRIPTION, v || "");
                    },
                    rows: 2,
                  }),
                  el(CharCountBar, {
                    key: "ogd-count",
                    current: ogDesc.length,
                    min: 0,
                    max: 200,
                  }),
                  el(
                    "div",
                    {
                      key: "ogimg-wrap",
                      className: "meyvora-social-image-wrap",
                    },
                    [
                      el(
                        "label",
                        {
                          key: "ogimg-l",
                          className: "components-base-control__label",
                        },
                        i18n.ogImage || "OG Image"
                      ),
                      ogImageUrl
                        ? el(
                            "div",
                            {
                              key: "ogimg-preview",
                              className: "meyvora-social-image-preview",
                            },
                            [
                              el("img", {
                                key: "ogimg-i",
                                src: ogImageUrl,
                                alt: "",
                                style: {
                                  width: 100,
                                  height: "auto",
                                  display: "block",
                                  marginBottom: 8,
                                },
                              }),
                            ]
                          )
                        : null,
                      el(
                        "div",
                        {
                          key: "ogimg-btns",
                          className: "meyvora-social-image-buttons",
                        },
                        [
                          el(
                            Button,
                            {
                              key: "ogimg-sel",
                              isSecondary: true,
                              onClick: openOgMedia,
                            },
                            __("Select Image", "meyvora-seo")
                          ),
                          ogImageId
                            ? el(
                                Button,
                                {
                                  key: "ogimg-rem",
                                  isDestructive: true,
                                  onClick: function () {
                                    setMeta(KEYS.OG_IMAGE, 0);
                                  },
                                  style: { marginLeft: 8 },
                                },
                                __("Remove", "meyvora-seo")
                              )
                            : null,
                        ]
                      ),
                    ]
                  ),
                  el(
                    "div",
                    {
                      key: "fb-preview",
                      className:
                        "meyvora-social-card meyvora-social-card-facebook",
                    },
                    [
                      el(
                        "strong",
                        {
                          key: "fb-head",
                          className: "meyvora-social-card-head",
                        },
                        __("Facebook", "meyvora-seo")
                      ),
                      el(
                        "div",
                        {
                          key: "fb-body",
                          className: "meyvora-social-card-body",
                        },
                        [
                          ogImageUrl
                            ? el(
                                "div",
                                {
                                  key: "fb-img",
                                  className: "meyvora-social-card-image",
                                },
                                el("img", { src: ogImageUrl, alt: "" })
                              )
                            : el(
                                "div",
                                {
                                  key: "fb-placeholder",
                                  className: "meyvora-social-card-placeholder",
                                },
                                __("No image selected", "meyvora-seo")
                              ),
                          el(
                            "div",
                            {
                              key: "fb-text",
                              className:
                                "meyvora-social-card-text meyvora-social-card-meta",
                            },
                            [
                              el(
                                "div",
                                {
                                  key: "fb-domain",
                                  className: "meyvora-social-card-domain",
                                },
                                siteDomain
                              ),
                              el(
                                "div",
                                {
                                  key: "fb-title",
                                  className: "meyvora-social-card-title",
                                },
                                truncate(socialTitle, 88) ||
                                  __("Your title", "meyvora-seo")
                              ),
                              el(
                                "div",
                                {
                                  key: "fb-desc",
                                  className: "meyvora-social-card-desc",
                                },
                                truncate(socialDesc, 200) ||
                                  __("Your description", "meyvora-seo")
                              ),
                            ]
                          ),
                        ]
                      ),
                    ]
                  ),
                  el(
                    "div",
                    {
                      key: "twimg-wrap",
                      className: "meyvora-social-image-wrap",
                    },
                    [
                      el(
                        "label",
                        {
                          key: "twimg-l",
                          className: "components-base-control__label",
                        },
                        __("Twitter Image", "meyvora-seo")
                      ),
                      twitterImageUrl
                        ? el(
                            "div",
                            {
                              key: "twimg-preview",
                              className: "meyvora-social-image-preview",
                            },
                            [
                              el("img", {
                                key: "twimg-i",
                                src: twitterImageUrl,
                                alt: "",
                                style: {
                                  width: 100,
                                  height: "auto",
                                  display: "block",
                                  marginBottom: 8,
                                },
                              }),
                            ]
                          )
                        : null,
                      el(
                        "div",
                        {
                          key: "twimg-btns",
                          className: "meyvora-social-image-buttons",
                        },
                        [
                          el(
                            Button,
                            {
                              key: "twimg-sel",
                              isSecondary: true,
                              onClick: openTwitterMedia,
                            },
                            __("Select Image", "meyvora-seo")
                          ),
                          twitterImageId
                            ? el(
                                Button,
                                {
                                  key: "twimg-rem",
                                  isDestructive: true,
                                  onClick: function () {
                                    setMeta(KEYS.TWITTER_IMAGE, 0);
                                  },
                                  style: { marginLeft: 8 },
                                },
                                __("Remove", "meyvora-seo")
                              )
                            : null,
                        ]
                      ),
                    ]
                  ),
                  el(TextControl, {
                    key: "twt",
                    label: __("Twitter Title", "meyvora-seo"),
                    value: twitterTitle,
                    onChange: function (v) {
                      setMeta(KEYS.TWITTER_TITLE, v || "");
                    },
                  }),
                  el(CharCountBar, {
                    key: "twt-count",
                    current: twitterTitle.length,
                    min: 0,
                    max: 70,
                  }),
                  el(TextareaControl, {
                    key: "twd",
                    label: __("Twitter Description", "meyvora-seo"),
                    value: twitterDesc,
                    onChange: function (v) {
                      setMeta(KEYS.TWITTER_DESCRIPTION, v || "");
                    },
                    rows: 2,
                  }),
                  el(CharCountBar, {
                    key: "twd-count",
                    current: twitterDesc.length,
                    min: 0,
                    max: 200,
                  }),
                  el(
                    "div",
                    {
                      key: "tw-preview",
                      className:
                        "meyvora-social-card meyvora-social-card-twitter",
                    },
                    [
                      el(
                        "strong",
                        {
                          key: "tw-head",
                          className: "meyvora-social-card-head",
                        },
                        __("Twitter / X", "meyvora-seo")
                      ),
                      el(
                        "div",
                        {
                          key: "tw-body",
                          className:
                            "meyvora-social-card-body meyvora-social-card-twitter-body",
                        },
                        [
                          twitterImageUrl
                            ? el(
                                "div",
                                {
                                  key: "tw-img",
                                  className:
                                    "meyvora-social-card-image meyvora-social-card-image-large",
                                },
                                el("img", { src: twitterImageUrl, alt: "" })
                              )
                            : el(
                                "div",
                                {
                                  key: "tw-placeholder",
                                  className:
                                    "meyvora-social-card-placeholder meyvora-social-card-placeholder-large",
                                },
                                __("No image selected", "meyvora-seo")
                              ),
                          el(
                            "div",
                            {
                              key: "tw-text",
                              className: "meyvora-social-card-text",
                            },
                            [
                              el(
                                "div",
                                {
                                  key: "tw-domain",
                                  className: "meyvora-social-card-domain",
                                },
                                siteDomain
                              ),
                              el(
                                "div",
                                {
                                  key: "tw-title",
                                  className: "meyvora-social-card-title",
                                },
                                truncate(
                                  twitterTitle || ogTitle || seoTitle,
                                  88
                                ) || __("Your title", "meyvora-seo")
                              ),
                              el(
                                "div",
                                {
                                  key: "tw-desc",
                                  className: "meyvora-social-card-desc",
                                },
                                truncate(
                                  twitterDesc || ogDesc || metaDesc,
                                  200
                                ) || __("Your description", "meyvora-seo")
                              ),
                            ]
                          ),
                        ]
                      ),
                    ]
                  ),
                ]);
              }
              if (tab.name === "advanced") {
                function parseSchemaMeta(meta, key) {
                  var raw = meta[key];
                  if (raw === undefined || raw === null || raw === "") return {};
                  try {
                    var o = JSON.parse(raw);
                    return typeof o === "object" && o !== null ? o : {};
                  } catch (e) {
                    return {};
                  }
                }
                function setSchemaMeta(key, data) {
                  var current =
                    wp.data.select("core/editor").getEditedPostAttribute("meta") || {};
                  var merged = Object.assign({}, current, {
                    [key]: typeof data === "string" ? data : JSON.stringify(data),
                  });
                  editPost({ meta: merged });
                }
                var howto = parseSchemaMeta(meta, KEYS.SCHEMA_HOWTO);
                var recipe = parseSchemaMeta(meta, KEYS.SCHEMA_RECIPE);
                var eventData = parseSchemaMeta(meta, KEYS.SCHEMA_EVENT);
                var course = parseSchemaMeta(meta, KEYS.SCHEMA_COURSE);
                var jobposting = parseSchemaMeta(meta, KEYS.SCHEMA_JOBPOSTING);
                var review = parseSchemaMeta(meta, KEYS.SCHEMA_REVIEW);
                var product = parseSchemaMeta(meta, KEYS.SCHEMA_PRODUCT);
                function buildJsonLdPreview() {
                  var ctx = { "@context": "https://schema.org" };
                  var type = schemaType || "Article";
                  if (type === "Article" || type === "BlogPosting" || type === "NewsArticle") {
                    ctx["@type"] = type;
                    ctx.headline = seoTitle || postTitle || "";
                    ctx.datePublished = postDate || null;
                    ctx.dateModified = postModified || null;
                    return ctx;
                  }
                  if (type === "HowTo") {
                    ctx["@type"] = "HowTo";
                    ctx.name = howto.name || "";
                    ctx.description = howto.description || "";
                    ctx.step = (howto.steps || []).map(function (s) {
                      return { "@type": "HowToStep", name: s.name || "", text: s.text || "" };
                    });
                    return ctx;
                  }
                  if (type === "Recipe") {
                    ctx["@type"] = "Recipe";
                    ctx.name = recipe.recipeName || "";
                    ctx.ingredients = recipe.ingredients || [];
                    ctx.recipeInstructions = (recipe.instructions || []).map(function (t) {
                      return { "@type": "HowToStep", text: t };
                    });
                    ctx.prepTime = recipe.prepTime || null;
                    ctx.cookTime = recipe.cookTime || null;
                    if (recipe.calories) ctx.nutrition = { "@type": "NutritionInformation", calories: recipe.calories };
                    return ctx;
                  }
                  if (type === "FAQPage") {
                    ctx["@type"] = "FAQPage";
                    ctx.mainEntity = (faqPairs || []).map(function (p) {
                      return { "@type": "Question", name: p.question || "", acceptedAnswer: { "@type": "Answer", text: p.answer || "" } };
                    });
                    return ctx;
                  }
                  if (type === "Event") {
                    ctx["@type"] = "Event";
                    ctx.name = eventData.name || "";
                    ctx.startDate = eventData.startDate || null;
                    ctx.endDate = eventData.endDate || null;
                    if (eventData.location && (eventData.location.name || eventData.location.address)) {
                      ctx.location = { "@type": "Place", name: eventData.location.name || "", address: eventData.location.address ? { "@type": "PostalAddress", streetAddress: eventData.location.address } : undefined };
                    }
                    ctx.organizer = eventData.organizer ? { "@type": "Organization", name: eventData.organizer } : null;
                    if (eventData.offers && (eventData.offers.price !== undefined || eventData.offers.currency)) {
                      ctx.offers = { "@type": "Offer", price: eventData.offers.price || "", priceCurrency: eventData.offers.currency || "" };
                    }
                    return ctx;
                  }
                  if (type === "JobPosting") {
                    ctx["@type"] = "JobPosting";
                    ctx.title = jobposting.title || "";
                    ctx.description = jobposting.description || "";
                    ctx.datePosted = jobposting.datePosted || null;
                    ctx.validThrough = jobposting.validThrough || null;
                    if (jobposting.hiringOrganization && jobposting.hiringOrganization.name) {
                      ctx.hiringOrganization = { "@type": "Organization", name: jobposting.hiringOrganization.name, sameAs: jobposting.hiringOrganization.url || "" };
                    }
                    if (jobposting.salary) ctx.baseSalary = { "@type": "MonetaryAmount", value: jobposting.salary };
                    return ctx;
                  }
                  if (type === "Course") {
                    ctx["@type"] = "Course";
                    ctx.name = course.name || "";
                    ctx.description = course.description || "";
                    ctx.provider = course.provider ? { "@type": "Organization", name: course.provider } : null;
                    ctx.url = course.url || null;
                    return ctx;
                  }
                  if (type === "Review") {
                    ctx["@type"] = "Review";
                    ctx.itemReviewed = { "@type": review.itemReviewed && review.itemReviewed.type ? review.itemReviewed.type : "Thing", name: (review.itemReviewed && review.itemReviewed.name) || "" };
                    ctx.reviewRating = review.ratingValue != null ? { "@type": "Rating", ratingValue: Number(review.ratingValue), bestRating: 5, worstRating: 1 } : null;
                    ctx.author = review.author ? { "@type": "Person", name: review.author } : null;
                    return ctx;
                  }
                  if (type === "Product") {
                    ctx["@type"] = "Product";
                    ctx.name = seoTitle || postTitle || "";
                    ctx.description = metaDesc || null;
                    if (product.price != null && product.price !== "" && !isNaN(Number(product.price))) {
                      ctx.offers = { "@type": "Offer", price: Number(product.price), priceCurrency: product.currency || "USD", availability: "https://schema.org/InStock" };
                    }
                    if (product.brand) ctx.brand = { "@type": "Brand", name: product.brand };
                    if (product.gtin) ctx.gtin = product.gtin;
                    return ctx;
                  }
                  if (type === "LocalBusiness") return Object.assign(ctx, { "@type": "LocalBusiness", name: __("Configure in Settings → Local SEO", "meyvora-seo") });
                  return ctx;
                }
                var jsonLdPreview = buildJsonLdPreview();
                var schemaPreviewOpen = schemaPreviewOpenState[0];
                var setSchemaPreviewOpen = schemaPreviewOpenState[1];
                var schemaFormParts = [];
                if (schemaType === "Article" || schemaType === "BlogPosting" || schemaType === "NewsArticle") {
                  schemaFormParts.push(
                    el("div", { key: "article-note", className: "meyvora-schema-note" }, __("Article uses SEO title as headline, post date published/modified, and featured image.", "meyvora-seo"))
                  );
                }
                if (schemaType === "HowTo") {
                  var steps = Array.isArray(howto.steps) ? howto.steps.slice() : [];
                  schemaFormParts.push(
                    el("div", { key: "howto-prefill", className: "meyvora-schema-prefill-row" }, [
                      el("label", { key: "howto-replace" }, el("input", { type: "checkbox", checked: schemaPrefillReplaceState[0].HowTo, onChange: function (e) { schemaPrefillReplaceState[1](Object.assign({}, schemaPrefillReplaceState[0], { HowTo: e.target.checked })); } }), " " + __("Replace existing values", "meyvora-seo")),
                      el(Button, { key: "howto-prefill-btn", isSecondary: true, isSmall: true, disabled: schemaPrefillLoadingState[0], onClick: function () {
                        schemaPrefillLoadingState[1](true);
                        var form = new FormData();
                        form.append("action", "meyvora_seo_ai_request");
                        form.append("nonce", config.aiNonce || "");
                        form.append("action_type", "extract_schema_fields");
                        form.append("schema_type", "HowTo");
                        form.append("post_id", String(effectivePostId));
                        form.append("content", (content || "").replace(/<[^>]+>/g, " ").slice(0, 5000));
                        var xhr = new XMLHttpRequest();
                        xhr.open("POST", ajaxUrl);
                        xhr.onload = function () {
                          schemaPrefillLoadingState[1](false);
                          try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data && res.data.fields && typeof res.data.fields === "object") {
                              var f = res.data.fields;
                              var replace = schemaPrefillReplaceState[0].HowTo;
                              var next = replace ? {} : Object.assign({}, howto);
                              if (replace || !(next.name || "").trim()) next.name = f.name != null ? String(f.name) : (next.name || "");
                              if (replace || !(next.description || "").trim()) next.description = f.description != null ? String(f.description) : (next.description || "");
                              if (replace || !(next.totalTime || "").trim()) next.totalTime = f.totalTime != null ? String(f.totalTime) : (next.totalTime || "");
                              if (replace || !(next.estimatedCost || "").trim()) next.estimatedCost = f.estimatedCost != null ? String(f.estimatedCost) : (next.estimatedCost || "");
                              if (Array.isArray(f.steps) && (replace || !(next.steps || []).length)) next.steps = f.steps.map(function (s) { return { name: (s && s.name) ? String(s.name) : "", text: (s && s.text) ? String(s.text) : "" }; });
                              setSchemaMeta(KEYS.SCHEMA_HOWTO, next);
                            }
                          } catch (e) {}
                        };
                        xhr.onerror = function () { schemaPrefillLoadingState[1](false); };
                        xhr.send(form);
                      } }, schemaPrefillLoadingState[0] ? (i18n.aiGenerating || "Generating…") : __("Pre-fill with AI", "meyvora-seo")),
                    ]),
                    el(TextControl, { key: "howto-name", label: __("Title", "meyvora-seo"), value: howto.name || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_HOWTO, Object.assign({}, howto, { name: v || "" })); } }),
                    el(TextareaControl, { key: "howto-desc", label: __("Description", "meyvora-seo"), value: howto.description || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_HOWTO, Object.assign({}, howto, { description: v || "" })); } }),
                    el("div", { key: "howto-steps", className: "meyvora-schema-list-label" }, __("Steps", "meyvora-seo")),
                    steps.map(function (s, i) {
                      return el("div", { key: "step-" + i, className: "meyvora-schema-step" }, [
                        el(TextControl, { label: __("Step name", "meyvora-seo"), value: s.name || "", onChange: function (v) { var next = steps.slice(); next[i] = Object.assign({}, next[i], { name: v || "" }); setSchemaMeta(KEYS.SCHEMA_HOWTO, Object.assign({}, howto, { steps: next })); } }),
                        el(TextareaControl, { label: __("Step text", "meyvora-seo"), value: s.text || "", onChange: function (v) { var next = steps.slice(); next[i] = Object.assign({}, next[i], { text: v || "" }); setSchemaMeta(KEYS.SCHEMA_HOWTO, Object.assign({}, howto, { steps: next })); } }),
                        el(Button, { isSecondary: true, isSmall: true, onClick: function () { var next = steps.filter(function (_, idx) { return idx !== i; }); setSchemaMeta(KEYS.SCHEMA_HOWTO, Object.assign({}, howto, { steps: next })); } }, __("Remove", "meyvora-seo")),
                      ]);
                    }),
                    el(Button, { key: "add-step", isSecondary: true, isSmall: true, onClick: function () { setSchemaMeta(KEYS.SCHEMA_HOWTO, Object.assign({}, howto, { steps: steps.concat([{ name: "", text: "" }]) })); } }, __("Add step", "meyvora-seo") )
                  );
                }
                if (schemaType === "Recipe") {
                  var ingredients = Array.isArray(recipe.ingredients) ? recipe.ingredients.slice() : [];
                  var instructions = Array.isArray(recipe.instructions) ? recipe.instructions.slice() : [];
                  schemaFormParts.push(
                    el("div", { key: "recipe-prefill", className: "meyvora-schema-prefill-row" }, [
                      el("label", { key: "recipe-replace" }, el("input", { type: "checkbox", checked: schemaPrefillReplaceState[0].Recipe, onChange: function (e) { schemaPrefillReplaceState[1](Object.assign({}, schemaPrefillReplaceState[0], { Recipe: e.target.checked })); } }), " " + __("Replace existing values", "meyvora-seo")),
                      el(Button, { key: "recipe-prefill-btn", isSecondary: true, isSmall: true, disabled: schemaPrefillLoadingState[0], onClick: function () {
                        schemaPrefillLoadingState[1](true);
                        var form = new FormData();
                        form.append("action", "meyvora_seo_ai_request");
                        form.append("nonce", config.aiNonce || "");
                        form.append("action_type", "extract_schema_fields");
                        form.append("schema_type", "Recipe");
                        form.append("post_id", String(effectivePostId));
                        form.append("content", (content || "").replace(/<[^>]+>/g, " ").slice(0, 5000));
                        var xhr = new XMLHttpRequest();
                        xhr.open("POST", ajaxUrl);
                        xhr.onload = function () {
                          schemaPrefillLoadingState[1](false);
                          try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data && res.data.fields && typeof res.data.fields === "object") {
                              var f = res.data.fields;
                              var replace = schemaPrefillReplaceState[0].Recipe;
                              var next = replace ? {} : Object.assign({}, recipe);
                              if (replace || !(next.recipeName || "").trim()) next.recipeName = f.recipeName != null ? String(f.recipeName) : (next.recipeName || "");
                              if (replace || !(next.recipeYield || "").trim()) next.recipeYield = f.recipeYield != null ? String(f.recipeYield) : (next.recipeYield || "");
                              if (replace || !(next.prepTime || "").trim()) next.prepTime = f.prepTime != null ? String(f.prepTime) : (next.prepTime || "");
                              if (replace || !(next.cookTime || "").trim()) next.cookTime = f.cookTime != null ? String(f.cookTime) : (next.cookTime || "");
                              if (Array.isArray(f.ingredients) && (replace || !(next.ingredients || []).length)) next.ingredients = f.ingredients.map(function (v) { return String(v || ""); });
                              var instr = Array.isArray(f.recipeInstructions) ? f.recipeInstructions : (Array.isArray(f.instructions) ? f.instructions : null);
                              if (instr && (replace || !(next.instructions || []).length)) next.instructions = instr.map(function (v) { return String(v || ""); });
                              setSchemaMeta(KEYS.SCHEMA_RECIPE, next);
                            }
                          } catch (e) {}
                        };
                        xhr.onerror = function () { schemaPrefillLoadingState[1](false); };
                        xhr.send(form);
                      } }, schemaPrefillLoadingState[0] ? (i18n.aiGenerating || "Generating…") : __("Pre-fill with AI", "meyvora-seo")),
                    ]),
                    el(TextControl, { key: "recipe-name", label: __("Recipe name", "meyvora-seo"), value: recipe.recipeName || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { recipeName: v || "" })); } }),
                    el("div", { key: "recipe-ing", className: "meyvora-schema-list-label" }, __("Ingredients", "meyvora-seo")),
                    ingredients.map(function (ing, i) {
                      return el("div", { key: "ing-" + i, className: "meyvora-schema-row" }, [
                        el(TextControl, { value: ing || "", onChange: function (v) { var next = ingredients.slice(); next[i] = v || ""; setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { ingredients: next })); } }),
                        el(Button, { isSecondary: true, isSmall: true, onClick: function () { var next = ingredients.filter(function (_, idx) { return idx !== i; }); setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { ingredients: next })); } }, "×"),
                      ]);
                    }),
                    el(Button, { key: "add-ing", isSecondary: true, isSmall: true, onClick: function () { setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { ingredients: ingredients.concat([""]) })); } }, __("Add ingredient", "meyvora-seo")),
                    el("div", { key: "recipe-instr-label", className: "meyvora-schema-list-label" }, __("Instructions", "meyvora-seo")),
                    instructions.map(function (inst, i) {
                      return el("div", { key: "inst-" + i, className: "meyvora-schema-row" }, [
                        el(TextareaControl, { value: inst || "", onChange: function (v) { var next = instructions.slice(); next[i] = v || ""; setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { instructions: next })); } }),
                        el(Button, { isSecondary: true, isSmall: true, onClick: function () { var next = instructions.filter(function (_, idx) { return idx !== i; }); setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { instructions: next })); } }, "×"),
                      ]);
                    }),
                    el(Button, { key: "add-instr", isSecondary: true, isSmall: true, onClick: function () { setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { instructions: instructions.concat([""]) })); } }, __("Add instruction", "meyvora-seo")),
                    el(TextControl, { key: "prep", label: __("Prep time (e.g. PT30M)", "meyvora-seo"), value: recipe.prepTime || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { prepTime: v || "" })); } }),
                    el(TextControl, { key: "cook", label: __("Cook time (e.g. PT1H)", "meyvora-seo"), value: recipe.cookTime || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { cookTime: v || "" })); } }),
                    el(TextControl, { key: "cal", label: __("Calories", "meyvora-seo"), value: (recipe.nutrition && recipe.nutrition.calories) || recipe.calories || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_RECIPE, Object.assign({}, recipe, { nutrition: Object.assign({}, recipe.nutrition || {}, { calories: v || "" }), calories: v || "" })); } })
                  );
                }
                if (schemaType === "FAQPage") {
                  schemaFormParts.push(el("div", { key: "faq-note", className: "meyvora-schema-note" }, "💡 " + __("FAQ data comes from the Meyvora FAQ block in your content. Add the FAQ block and fill questions/answers.", "meyvora-seo")));
                  schemaFormParts.push(
                    el(Button, {
                      key: "faq-generate-ai",
                      isSecondary: true,
                      isSmall: true,
                      disabled: aiFaqLoadingState[0],
                      onClick: function () {
                        aiFaqLoadingState[1](true);
                        var form = new FormData();
                        form.append("action", "meyvora_seo_ai_request");
                        form.append("nonce", config.aiNonce || "");
                        form.append("action_type", "generate_faq");
                        form.append("post_id", String(effectivePostId));
                        form.append("content", (content || "").replace(/<[^>]+>/g, " ").slice(0, 5000));
                        form.append("focus_keyword", focusKeywordsArray && focusKeywordsArray[0] ? focusKeywordsArray[0] : "");
                        var xhr = new XMLHttpRequest();
                        xhr.open("POST", ajaxUrl);
                        xhr.onload = function () {
                          aiFaqLoadingState[1](false);
                          try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data && Array.isArray(res.data.faq)) {
                              setMeta(KEYS.FAQ, JSON.stringify(res.data.faq));
                            }
                          } catch (e) {}
                        };
                        xhr.onerror = function () { aiFaqLoadingState[1](false); };
                        xhr.send(form);
                      },
                    }, aiFaqLoadingState[0] ? (i18n.aiGenerating || "Generating…") : __("Generate FAQ with AI", "meyvora-seo"))
                  );
                }
                if (schemaType === "Event") {
                  var loc = eventData.location || {};
                  var off = eventData.offers || {};
                  schemaFormParts.push(
                    el("div", { key: "event-prefill", className: "meyvora-schema-prefill-row" }, [
                      el("label", { key: "event-replace" }, el("input", { type: "checkbox", checked: schemaPrefillReplaceState[0].Event, onChange: function (e) { schemaPrefillReplaceState[1](Object.assign({}, schemaPrefillReplaceState[0], { Event: e.target.checked })); } }), " " + __("Replace existing values", "meyvora-seo")),
                      el(Button, { key: "event-prefill-btn", isSecondary: true, isSmall: true, disabled: schemaPrefillLoadingState[0], onClick: function () {
                        schemaPrefillLoadingState[1](true);
                        var form = new FormData();
                        form.append("action", "meyvora_seo_ai_request");
                        form.append("nonce", config.aiNonce || "");
                        form.append("action_type", "extract_schema_fields");
                        form.append("schema_type", "Event");
                        form.append("post_id", String(effectivePostId));
                        form.append("content", (content || "").replace(/<[^>]+>/g, " ").slice(0, 5000));
                        var xhr = new XMLHttpRequest();
                        xhr.open("POST", ajaxUrl);
                        xhr.onload = function () {
                          schemaPrefillLoadingState[1](false);
                          try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data && res.data.fields && typeof res.data.fields === "object") {
                              var f = res.data.fields;
                              var replace = schemaPrefillReplaceState[0].Event;
                              var next = replace ? {} : Object.assign({}, eventData);
                              if (replace || !(next.name || "").trim()) next.name = f.name != null ? String(f.name) : (next.name || "");
                              if (replace || !(next.startDate || "").trim()) next.startDate = f.startDate != null ? String(f.startDate) : (next.startDate || "");
                              if (replace || !(next.endDate || "").trim()) next.endDate = f.endDate != null ? String(f.endDate) : (next.endDate || "");
                              if (replace || !(next.eventStatus || "").trim()) next.eventStatus = f.eventStatus != null ? String(f.eventStatus) : (next.eventStatus || "");
                              if (replace || !(next.eventAttendanceMode || "").trim()) next.eventAttendanceMode = f.eventAttendanceMode != null ? String(f.eventAttendanceMode) : (next.eventAttendanceMode || "");
                              if (replace || !(next.description || "").trim()) next.description = f.description != null ? String(f.description) : (next.description || "");
                              var locIn = f.location;
                              if (locIn && (replace || !(loc.name || "").trim() || !(loc.address || "").trim())) {
                                next.location = next.location || {};
                                if (typeof locIn === "object") { next.location.name = locIn.name != null ? String(locIn.name) : (next.location.name || ""); next.location.address = locIn.address != null ? String(locIn.address) : (next.location.address || ""); }
                                else next.location.name = String(locIn);
                              }
                              setSchemaMeta(KEYS.SCHEMA_EVENT, next);
                            }
                          } catch (e) {}
                        };
                        xhr.onerror = function () { schemaPrefillLoadingState[1](false); };
                        xhr.send(form);
                      } }, schemaPrefillLoadingState[0] ? (i18n.aiGenerating || "Generating…") : __("Pre-fill with AI", "meyvora-seo")),
                    ]),
                    el(TextControl, { key: "ev-name", label: __("Event name", "meyvora-seo"), value: eventData.name || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { name: v || "" })); } }),
                    el(TextControl, { key: "ev-start", label: __("Start date (ISO)", "meyvora-seo"), value: eventData.startDate || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { startDate: v || "" })); } }),
                    el(TextControl, { key: "ev-end", label: __("End date (ISO)", "meyvora-seo"), value: eventData.endDate || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { endDate: v || "" })); } }),
                    el(TextControl, { key: "ev-loc-name", label: __("Location name", "meyvora-seo"), value: loc.name || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { location: Object.assign({}, loc, { name: v || "" }) })); } }),
                    el(TextControl, { key: "ev-loc-addr", label: __("Location address", "meyvora-seo"), value: loc.address || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { location: Object.assign({}, loc, { address: v || "" }) })); } }),
                    el(TextControl, { key: "ev-org", label: __("Organizer", "meyvora-seo"), value: eventData.organizer || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { organizer: v || "" })); } }),
                    el(TextControl, { key: "ev-price", label: __("Offer price", "meyvora-seo"), value: off.price !== undefined ? String(off.price) : "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { offers: Object.assign({}, off, { price: v || "" }) })); } }),
                    el(TextControl, { key: "ev-currency", label: __("Offer currency", "meyvora-seo"), value: off.currency || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_EVENT, Object.assign({}, eventData, { offers: Object.assign({}, off, { currency: v || "" }) })); } })
                  );
                }
                if (schemaType === "JobPosting") {
                  var ho = jobposting.hiringOrganization || {};
                  schemaFormParts.push(
                    el("div", { key: "job-prefill", className: "meyvora-schema-prefill-row" }, [
                      el("label", { key: "job-replace" }, el("input", { type: "checkbox", checked: schemaPrefillReplaceState[0].JobPosting, onChange: function (e) { schemaPrefillReplaceState[1](Object.assign({}, schemaPrefillReplaceState[0], { JobPosting: e.target.checked })); } }), " " + __("Replace existing values", "meyvora-seo")),
                      el(Button, { key: "job-prefill-btn", isSecondary: true, isSmall: true, disabled: schemaPrefillLoadingState[0], onClick: function () {
                        schemaPrefillLoadingState[1](true);
                        var form = new FormData();
                        form.append("action", "meyvora_seo_ai_request");
                        form.append("nonce", config.aiNonce || "");
                        form.append("action_type", "extract_schema_fields");
                        form.append("schema_type", "JobPosting");
                        form.append("post_id", String(effectivePostId));
                        form.append("content", (content || "").replace(/<[^>]+>/g, " ").slice(0, 5000));
                        var xhr = new XMLHttpRequest();
                        xhr.open("POST", ajaxUrl);
                        xhr.onload = function () {
                          schemaPrefillLoadingState[1](false);
                          try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data && res.data.fields && typeof res.data.fields === "object") {
                              var f = res.data.fields;
                              var replace = schemaPrefillReplaceState[0].JobPosting;
                              var next = replace ? {} : Object.assign({}, jobposting);
                              if (replace || !(next.title || "").trim()) next.title = f.title != null ? String(f.title) : (next.title || "");
                              if (replace || !(next.description || "").trim()) next.description = f.description != null ? String(f.description) : (next.description || "");
                              if (replace || !(next.datePosted || "").trim()) next.datePosted = f.datePosted != null ? String(f.datePosted) : (next.datePosted || "");
                              if (replace || !(next.validThrough || "").trim()) next.validThrough = f.validThrough != null ? String(f.validThrough) : (next.validThrough || "");
                              var hoIn = f.hiringOrganization;
                              if (hoIn && typeof hoIn === "object" && (replace || !(ho.name || "").trim())) next.hiringOrganization = Object.assign({}, next.hiringOrganization || {}, { name: hoIn.name != null ? String(hoIn.name) : "" });
                              var jlIn = f.jobLocation;
                              if (jlIn && typeof jlIn === "object" && (replace || !(jobposting.jobLocation || {}).addressLocality)) { next.jobLocation = next.jobLocation || {}; next.jobLocation.addressLocality = jlIn.addressLocality != null ? String(jlIn.addressLocality) : (jlIn.city != null ? String(jlIn.city) : ""); }
                              var salIn = f.baseSalary;
                              if (salIn && typeof salIn === "object" && salIn.value != null && (replace || !(jobposting.salary || jobposting.baseSalary || ""))) { next.salary = String(salIn.value); next.baseSalary = next.salary; }
                              setSchemaMeta(KEYS.SCHEMA_JOBPOSTING, next);
                            }
                          } catch (e) {}
                        };
                        xhr.onerror = function () { schemaPrefillLoadingState[1](false); };
                        xhr.send(form);
                      } }, schemaPrefillLoadingState[0] ? (i18n.aiGenerating || "Generating…") : __("Pre-fill with AI", "meyvora-seo")),
                    ]),
                    el(TextControl, { key: "job-title", label: __("Job title", "meyvora-seo"), value: jobposting.title || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_JOBPOSTING, Object.assign({}, jobposting, { title: v || "" })); } }),
                    el(TextareaControl, { key: "job-desc", label: __("Description", "meyvora-seo"), value: jobposting.description || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_JOBPOSTING, Object.assign({}, jobposting, { description: v || "" })); } }),
                    el(TextControl, { key: "job-posted", label: __("Date posted (ISO)", "meyvora-seo"), value: jobposting.datePosted || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_JOBPOSTING, Object.assign({}, jobposting, { datePosted: v || "" })); } }),
                    el(TextControl, { key: "job-valid", label: __("Valid through (ISO)", "meyvora-seo"), value: jobposting.validThrough || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_JOBPOSTING, Object.assign({}, jobposting, { validThrough: v || "" })); } }),
                    el(TextControl, { key: "job-org", label: __("Hiring organization name", "meyvora-seo"), value: ho.name || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_JOBPOSTING, Object.assign({}, jobposting, { hiringOrganization: Object.assign({}, ho, { name: v || "" }) })); } }),
                    el(TextControl, { key: "job-salary", label: __("Salary", "meyvora-seo"), value: jobposting.salary || jobposting.baseSalary || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_JOBPOSTING, Object.assign({}, jobposting, { salary: v || "", baseSalary: v || "" })); } })
                  );
                }
                if (schemaType === "Course") {
                  schemaFormParts.push(
                    el(TextControl, { key: "course-name", label: __("Course name", "meyvora-seo"), value: course.name || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_COURSE, Object.assign({}, course, { name: v || "" })); } }),
                    el(TextareaControl, { key: "course-desc", label: __("Description", "meyvora-seo"), value: course.description || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_COURSE, Object.assign({}, course, { description: v || "" })); } }),
                    el(TextControl, { key: "course-provider", label: __("Provider", "meyvora-seo"), value: course.provider || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_COURSE, Object.assign({}, course, { provider: v || "" })); } }),
                    el(TextControl, { key: "course-url", label: __("URL", "meyvora-seo"), type: "url", value: course.url || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_COURSE, Object.assign({}, course, { url: v || "" })); } })
                  );
                }
                if (schemaType === "Product") {
                  var productCurrencyOptions = [
                    { value: "USD", label: "USD" }, { value: "EUR", label: "EUR" }, { value: "GBP", label: "GBP" },
                    { value: "JPY", label: "JPY" }, { value: "CAD", label: "CAD" }, { value: "AUD", label: "AUD" },
                    { value: "CHF", label: "CHF" }, { value: "INR", label: "INR" }, { value: "CNY", label: "CNY" },
                    { value: "MXN", label: "MXN" }, { value: "BRL", label: "BRL" },
                  ];
                  schemaFormParts.push(
                    el(TextControl, { key: "product-price", label: __("Price", "meyvora-seo"), value: product.price != null && product.price !== "" ? String(product.price) : "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_PRODUCT, Object.assign({}, product, { price: v !== "" && !isNaN(Number(v)) ? Number(v) : "" })); } }),
                    el(SelectControl, { key: "product-currency", label: __("Currency", "meyvora-seo"), value: product.currency || "USD", options: productCurrencyOptions, onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_PRODUCT, Object.assign({}, product, { currency: v || "USD" })); } }),
                    el(TextControl, { key: "product-brand", label: __("Brand", "meyvora-seo"), value: product.brand || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_PRODUCT, Object.assign({}, product, { brand: v || "" })); } }),
                    el(TextControl, { key: "product-gtin", label: __("GTIN", "meyvora-seo"), value: product.gtin || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_PRODUCT, Object.assign({}, product, { gtin: v || "" })); } })
                  );
                }
                if (schemaType === "Review") {
                  var ir = review.itemReviewed || {};
                  schemaFormParts.push(
                    el(TextControl, { key: "rev-item-name", label: __("Item reviewed name", "meyvora-seo"), value: ir.name || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_REVIEW, Object.assign({}, review, { itemReviewed: Object.assign({}, ir, { name: v || "" }) })); } }),
                    el(TextControl, { key: "rev-item-type", label: __("Item reviewed type", "meyvora-seo"), value: ir.type || "Thing", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_REVIEW, Object.assign({}, review, { itemReviewed: Object.assign({}, ir, { type: v || "Thing" }) })); } }),
                    el(SelectControl, { key: "rev-rating", label: __("Rating (1–5 stars)", "meyvora-seo"), value: review.ratingValue != null ? String(review.ratingValue) : "", options: [{ value: "", label: "—" }, { value: "1", label: "1" }, { value: "2", label: "2" }, { value: "3", label: "3" }, { value: "4", label: "4" }, { value: "5", label: "5" }], onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_REVIEW, Object.assign({}, review, { ratingValue: v ? Number(v) : null, reviewRating: v ? { ratingValue: Number(v), bestRating: 5, worstRating: 1 } : null })); } }),
                    el(TextControl, { key: "rev-author", label: __("Author", "meyvora-seo"), value: review.author || "", onChange: function (v) { setSchemaMeta(KEYS.SCHEMA_REVIEW, Object.assign({}, review, { author: v || "" })); } })
                  );
                }
                if (schemaType === "LocalBusiness") {
                  var localSeoUrl = (config.settingsUrl || (typeof meyvoraSeoBlock !== "undefined" && meyvoraSeoBlock.settingsUrl) || "").replace(/#.*$/, "") + "#tab-local-seo";
                  schemaFormParts.push(
                    el("div", { key: "local-note", className: "meyvora-schema-note" }, [
                      __("LocalBusiness schema is configured in Settings → Local SEO.", "meyvora-seo"),
                      " ",
                      el("a", { href: localSeoUrl, target: "_blank", rel: "noopener noreferrer" }, __("Open Settings", "meyvora-seo")),
                    ])
                  );
                }
                return el(Fragment, { key: "adv" }, [
                  el(SelectControl, {
                    key: "schema",
                    label: __("Schema Type", "meyvora-seo"),
                    value: schemaType,
                    options: schemaTypeOptions,
                    onChange: function (v) {
                      setMeta(KEYS.SCHEMA_TYPE, v || "");
                    },
                  }),
                  schemaFormParts.length ? el("div", { key: "schema-form", className: "meyvora-schema-form-wrap" }, schemaFormParts) : null,
                  el("div", { key: "schema-preview-wrap", className: "meyvora-schema-preview-wrap" }, [
                    el("button", {
                      key: "schema-preview-toggle",
                      type: "button",
                      className: "meyvora-schema-preview-toggle",
                      "aria-expanded": schemaPreviewOpen,
                      onClick: function () { setSchemaPreviewOpen(!schemaPreviewOpen); },
                    }, (schemaPreviewOpen ? "▼ " : "▶ ") + __("JSON-LD preview", "meyvora-seo")),
                    schemaPreviewOpen ? [
                      el("pre", { key: "schema-preview", className: "meyvora-schema-preview-code meyvora-schema-json-preview" }, JSON.stringify(jsonLdPreview, null, 2)),
                      el(Button, {
                        key: "copy-jsonld",
                        variant: "secondary",
                        isSmall: true,
                        style: { marginTop: "8px" },
                        onClick: function () {
                          var el = document.querySelector(".meyvora-schema-json-preview");
                          if (!el) return;
                          var text = el.textContent || el.innerText || "";
                          if (navigator.clipboard) {
                            navigator.clipboard.writeText(text).then(function () {
                              copyJsonldLabel[1](__("Copied!", "meyvora-seo"));
                              setTimeout(function () {
                                copyJsonldLabel[1](__("Copy JSON-LD", "meyvora-seo"));
                              }, 1500);
                            });
                          }
                        },
                      }, copyJsonldLabel[0]),
                    ] : null,
                  ]),
                  el(TextControl, {
                    key: "canonical",
                    label: __("Canonical URL", "meyvora-seo"),
                    type: "url",
                    value: canonical,
                    onChange: function (v) {
                      setMeta(KEYS.CANONICAL, v || "");
                    },
                  }),
                  el(TextControl, {
                    key: "breadcrumb-title",
                    label: __("Breadcrumb title", "meyvora-seo"),
                    help: __("Custom label for breadcrumb navigation. Leave empty to use the post title.", "meyvora-seo"),
                    value: meta[KEYS.BREADCRUMB_TITLE] || "",
                    onChange: function (v) {
                      setMeta(KEYS.BREADCRUMB_TITLE, v || "");
                    },
                  }),
                  el(ToggleControl, {
                    key: "noindex",
                    label: __("Exclude from search engines", "meyvora-seo"),
                    checked: noindex,
                    onChange: function (v) {
                      setMeta(KEYS.NOINDEX, v ? "1" : "");
                    },
                  }),
                  el(ToggleControl, {
                    key: "nofollow",
                    label: __("Nofollow all links", "meyvora-seo"),
                    checked: nofollow,
                    onChange: function (v) {
                      setMeta(KEYS.NOFOLLOW, v ? "1" : "");
                    },
                  }),
                  el(ToggleControl, {
                    key: "cornerstone",
                    label: __("Cornerstone content", "meyvora-seo"),
                    help: __("Mark this as important cornerstone content. Doubles internal link score weight and requires 1500+ words.", "meyvora-seo"),
                    checked: meta[KEYS.CORNERSTONE] === "1",
                    onChange: function (v) {
                      setMeta(KEYS.CORNERSTONE, v ? "1" : "");
                    },
                  }),
                  el(ToggleControl, {
                    key: "noodp",
                    label: __("Noodp", "meyvora-seo"),
                    help: __("Prevent search engines from using ODP/DMOZ descriptions.", "meyvora-seo"),
                    checked: noodp,
                    onChange: function (v) {
                      setMeta(KEYS.NOODP, v ? "1" : "");
                    },
                  }),
                  el(ToggleControl, {
                    key: "noarchive",
                    label: __("Noarchive", "meyvora-seo"),
                    help: __("Prevent Google from showing a cached copy.", "meyvora-seo"),
                    checked: noarchive,
                    onChange: function (v) {
                      setMeta(KEYS.NOARCHIVE, v ? "1" : "");
                    },
                  }),
                  el(ToggleControl, {
                    key: "nosnippet",
                    label: __("Nosnippet", "meyvora-seo"),
                    help: __("Prevent Google from showing a text snippet in results.", "meyvora-seo"),
                    checked: nosnippet,
                    onChange: function (v) {
                      setMeta(KEYS.NOSNIPPET, v ? "1" : "");
                    },
                  }),
                  el(TextControl, {
                    key: "max-snippet",
                    label: __("Max snippet (chars)", "meyvora-seo"),
                    help: __("Max characters in snippet (-1 = no limit, 0 = no snippet). Only applies when Nosnippet is off.", "meyvora-seo"),
                    type: "number",
                    min: -1,
                    value: maxSnippet >= -1 ? String(maxSnippet) : "",
                    disabled: nosnippet,
                    onChange: function (v) {
                      var n = parseInt(v, 10);
                      setMeta(KEYS.MAX_SNIPPET, !isNaN(n) && n >= -1 ? n : -1);
                    },
                  }),
                  el(SelectControl, {
                    key: "max-image-preview",
                    label: __("Max image preview", "meyvora-seo"),
                    value: meta[KEYS.MAX_IMAGE_PREVIEW] || "",
                    options: [
                      { value: "", label: __("Default", "meyvora-seo") },
                      { value: "none", label: __("None", "meyvora-seo") },
                      { value: "standard", label: __("Standard", "meyvora-seo") },
                      { value: "large", label: __("Large", "meyvora-seo") },
                    ],
                    onChange: function (v) {
                      setMeta(KEYS.MAX_IMAGE_PREVIEW, v);
                    },
                  }),
                  el(TextControl, {
                    key: "max-video-preview",
                    label: __("Max video preview (seconds, -1 = no limit)", "meyvora-seo"),
                    type: "number",
                    value: meta[KEYS.MAX_VIDEO_PREVIEW] !== undefined && meta[KEYS.MAX_VIDEO_PREVIEW] !== "" ? String(meta[KEYS.MAX_VIDEO_PREVIEW]) : "",
                    onChange: function (v) {
                      setMeta(KEYS.MAX_VIDEO_PREVIEW, v !== "" ? parseInt(v, 10) : "");
                    },
                  }),
                  el(
                    "div",
                    { className: "meyvora-faq-moved-notice" },
                    el("p", null, "💡 Add FAQ content using the Meyvora FAQ Block — search for \"Meyvora FAQ\" in the block inserter.")
                  ),
                ]);
              }
              if (tab.name === "eeat") {
                var eeatData = config.eeatChecklistData || {};
                var items = [
                  { key: "author_has_expertise_area", label: i18n.eeatAuthorExpertise || "Author has expertise area" },
                  { key: "author_has_credentials", label: i18n.eeatAuthorCredentials || "Author has credentials" },
                  { key: "author_has_organization_affiliation", label: i18n.eeatAuthorOrg || "Author has organization affiliation" },
                  { key: "author_has_years_experience", label: i18n.eeatAuthorYears || "Author has years of experience" },
                  { key: "post_has_date_modified", label: i18n.eeatDateModified || "Post has date modified" },
                  { key: "post_has_citations_block", label: i18n.eeatCitationsBlock || "Post has Citations block" },
                  { key: "post_has_byline_speakable", label: i18n.eeatBylineSpeakable || "Byline speakable in schema" },
                ];
                var presentLabel = i18n.eeatSignalPresent || "Present";
                var missingLabel = i18n.eeatSignalMissing || "Missing";
                return el("div", { key: "eeat", className: "meyvora-eeat-tab" }, [
                  el("p", { key: "intro", className: "meyvora-eeat-intro", style: { marginBottom: "12px", fontSize: "12px", color: "#50575e" } },
                    "Signals Google looks for. Fill author profile (Users → profile) and add a Citations block for references."
                  ),
                  el("ul", { key: "list", className: "meyvora-eeat-checklist", style: { listStyle: "none", margin: 0, padding: 0 } },
                    items.map(function (item) {
                      var ok = !!eeatData[item.key];
                      return el("li", { key: item.key, style: { display: "flex", alignItems: "center", justifyContent: "space-between", padding: "8px 0", borderBottom: "1px solid #e5e7eb" } }, [
                        el("span", { key: "label" }, item.label),
                        el("span", { key: "badge", style: { fontSize: "11px", fontWeight: 500, padding: "2px 8px", borderRadius: "4px", background: ok ? "#d1fae5" : "#fee2e2", color: ok ? "#065f46" : "#991b1b" } }, ok ? presentLabel : missingLabel),
                      ]);
                    })
                  ),
                ]);
              }
              return null;
            }
          ),

          el(
            "div",
            { key: "footer", className: "meyvora-panel-footer" },
            (function () {
              var lastAnalyzedAt = lastAnalyzed[0];
              var secondsAgo = lastAnalyzedAt
                ? Math.floor((Date.now() - lastAnalyzedAt.getTime()) / 1000)
                : null;
              var currentHash = inputsHash(
                content,
                seoTitle,
                metaDesc,
                focusKeywordStorage
              );
              var contentChanged =
                contentHashAtLastAnalysis[0] !== "" &&
                currentHash !== contentHashAtLastAnalysis[0];
              var parts = [];
              if (isAnalyzing[0]) {
                parts.push(
                  el(
                    "div",
                    { key: "analyzing", className: "meyvora-analyzing" },
                    el(Spinner),
                    " ",
                    i18n.analyzing || "Analyzing…"
                  )
                );
              } else {
                var statusLine = "";
                if (lastAnalyzedAt != null) {
                  statusLine =
                    __("Last analyzed:", "meyvora-seo") +
                    " " +
                    (secondsAgo !== null
                      ? secondsAgo +
                        " " +
                        (secondsAgo === 1
                          ? __("second", "meyvora-seo")
                          : __("seconds", "meyvora-seo")) +
                        " " +
                        __("ago", "meyvora-seo")
                      : "—");
                }
                if (statusLine) {
                  parts.push(
                    el(
                      "div",
                      { key: "status", className: "meyvora-footer-status" },
                      statusLine
                    )
                  );
                }
              }
              if (contentChanged && !isAnalyzing[0]) {
                parts.push(
                  el(
                    "div",
                    { key: "changed", className: "meyvora-footer-changed" },
                    __(
                      "Content changed — analysis will update shortly.",
                      "meyvora-seo"
                    )
                  )
                );
              }
              if (effectivePostId !== 0 && !isAnalyzing[0]) {
                parts.push(
                  el(
                    "a",
                    {
                      key: "reanalyze",
                      href: "#",
                      className: "meyvora-reanalyze-link",
                      onClick: function (e) {
                        e.preventDefault();
                        debouncedAnalyze.cancel();
                        setIsAnalyzing(true);
                        runAnalysis(
                          {
                            content: content,
                            title: seoTitle,
                            description: metaDesc,
                            focus_keyword: focusKeywordStorage,
                          },
                          onAnalysisDone,
                          effectivePostId
                        );
                      },
                    },
                    __("Re-analyze now", "meyvora-seo")
                  )
                );
              }
              return parts;
            })()
          ),
          el(
            "div",
            { key: "chat-panel", style: { marginTop: "16px" } },
            el("button", {
              type: "button",
              onClick: function () {
                setChatOpen(!chatOpen);
              },
              style: {
                width: "100%",
                textAlign: "left",
                background: "none",
                border: "1px solid #e5e7eb",
                borderRadius: "4px",
                padding: "8px 12px",
                cursor: "pointer",
                fontWeight: "600",
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
              },
            }, __("💬 SEO Chat", "meyvora-seo"), el("span", {}, chatOpen ? "▲" : "▼")),
            chatOpen &&
              el(
                "div",
                {
                  style: {
                    border: "1px solid #e5e7eb",
                    borderTop: "none",
                    borderRadius: "0 0 4px 4px",
                    padding: "8px",
                  },
                },
                el(
                  "div",
                  {
                    style: {
                      maxHeight: "240px",
                      overflowY: "auto",
                      marginBottom: "8px",
                      display: "flex",
                      flexDirection: "column",
                      gap: "6px",
                    },
                  },
                  chatHistory.length === 0
                    ? el(
                        "p",
                        { style: { color: "#9ca3af", fontSize: "12px", margin: 0 } },
                        __("Ask anything about this post's SEO — why it's not ranking,", "meyvora-seo"),
                        el("br", {}),
                        __("how to improve the title, what schema to add...", "meyvora-seo")
                      )
                    : chatHistory.map(function (msg, i) {
                        return el(
                          "div",
                          {
                            key: String(i),
                            style: {
                              background: msg.role === "user" ? "#e0e7ff" : "#f3f4f6",
                              borderRadius: "6px",
                              padding: "6px 10px",
                              fontSize: "12px",
                              alignSelf: msg.role === "user" ? "flex-end" : "flex-start",
                              maxWidth: "90%",
                              whiteSpace: "pre-wrap",
                            },
                          },
                          msg.content
                        );
                      })
                ),
                chatHistory.length > 0
                  ? el("button", {
                      type: "button",
                      onClick: function () {
                        setChatHistory([]);
                        setChatInput("");
                      },
                      style: {
                        fontSize: "11px",
                        color: "#6b7280",
                        background: "none",
                        border: "none",
                        cursor: "pointer",
                        padding: "2px 0",
                        marginBottom: "4px",
                        textDecoration: "underline",
                      },
                    }, __("New chat", "meyvora-seo"))
                  : null,
                chatLoading &&
                  el(
                    "div",
                    { style: { fontSize: "11px", color: "#6b7280", marginBottom: "6px" } },
                    __("Thinking...", "meyvora-seo")
                  ),
                el(
                  "div",
                  { style: { display: "flex", gap: "4px" } },
                  el("input", {
                    type: "text",
                    value: chatInput,
                    placeholder: __("Ask about this post's SEO...", "meyvora-seo"),
                    style: { flex: 1, fontSize: "12px", padding: "4px 8px" },
                    onChange: function (e) {
                      setChatInput(e.target.value);
                    },
                    onKeyDown: function (e) {
                      if (e.key === "Enter" && !e.shiftKey) {
                        e.preventDefault();
                        sendChatMessage();
                      }
                    },
                  }),
                  el("button", {
                    type: "button",
                    disabled: chatLoading || chatInput.trim() === "",
                    onClick: sendChatMessage,
                    className: "components-button is-primary",
                    style: { fontSize: "12px" },
                  }, __("Send", "meyvora-seo"))
                ),
                el(
                  "p",
                  { style: { fontSize: "11px", color: "#9ca3af", margin: "4px 0 0" } },
                  (wp.i18n && wp.i18n.sprintf
                    ? wp.i18n.sprintf
                    : function (f, n) {
                        return String(f).replace("%d", String(n));
                      })(
                    __("%d AI calls remaining today", "meyvora-seo"),
                    config.remaining != null ? config.remaining : 0
                  )
                )
              )
          ),
        ]
      );
    }

    wp.plugins.registerPlugin("meyvora-seo-sidebar", {
      render: function () {
        return el(MeyvoraSEOPanel, null);
      },
      icon: null,
    });
  }

  // Run when DOM is ready; wp.domReady inside registerPlugin handles late wp.editPost.
  function runRegisterPlugin() {
    try {
      registerPlugin();
    } catch (err) {
      if (typeof console !== "undefined" && console.error) {
        console.error("Meyvora SEO block editor failed to register:", err);
      }
    }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", runRegisterPlugin);
  } else {
    runRegisterPlugin();
  }
})();
