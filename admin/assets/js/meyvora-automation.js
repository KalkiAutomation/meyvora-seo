/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

/**
 * Meyvora SEO Automation: condition builder, rule list, sync to hidden textarea, Apply to all.
 */
(function ($) {
  "use strict";

  var rulesArray = [];
  var $textarea = $("#meyvora_automation_rules");
  var $rulesList = $("#mev-rules-list");
  var $noRules = $("#mev-no-rules");
  var $conditionTpl = $("#mev-condition-row-tpl");
  var actionsWithValue = [
    "auto_title_template",
    "auto_schema_type",
    "auto_canonical_pattern",
    "auto_set_status",
  ];
  var actionsWithOverwrite = [
    "auto_ai_generate_description",
    "auto_ai_generate_title",
  ];

  function getRulesFromTextarea() {
    var raw = $textarea.val();
    if (!raw) return [];
    try {
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function syncTextarea() {
    $textarea.val(JSON.stringify(rulesArray));
  }

  function buildRuleFromForm() {
    var logicRadio = document.querySelector('input[name="mev-rule-logic"]:checked');
    var logic = logicRadio ? logicRadio.value : "AND";
    var conditions = [];
    $("#mev-conditions-container .mev-condition-row").each(function () {
      var $row = $(this);
      var field = $row.find(".mev-condition-field").val();
      var operator = $row.find(".mev-condition-operator").val();
      var value = $row.find(".mev-condition-value").val() || "";
      if (field) {
        conditions.push({ field: field, operator: operator, value: value });
      }
    });
    var actions = [];
    $("#mev-actions-container .mev-action-card").each(function () {
      var $row = $(this);
      var $cb = $row.find(".mev-action-check");
        if ($cb.length && $cb.prop("checked")) {
        var action = $cb.val();
        var item = { action: action };
        if (actionsWithValue.indexOf(action) !== -1) {
          var val = $row.find(".mev-action-value").val() || "";
          item.value = val;
        }
        if (actionsWithOverwrite.indexOf(action) !== -1) {
          item.overwrite = $row.find(".mev-action-overwrite").prop("checked") || false;
        }
        actions.push(item);
      }
    });
    return {
      id: "r-" + Date.now() + "-" + Math.random().toString(36).slice(2, 9),
      enabled: true,
      logic: logic,
      conditions: conditions,
      actions: actions,
    };
  }

  function ruleToSummary(rule) {
    var c = (rule.conditions || [])
      .map(function (c) {
        return (
          c.field + " " + c.operator + (c.value ? ' "' + c.value + '"' : "")
        );
      })
      .join(" AND ");
    var a = (rule.actions || [])
      .map(function (a) {
        return (a.action || a).replace("auto_", "");
      })
      .join(", ");
    return "IF " + (c || "no conditions") + " → THEN " + (a || "no actions");
  }

  function addRuleRow(rule) {
    var condCount = (rule.conditions || []).length;
    var actionCount = (rule.actions || []).length;
    var summary = ruleToSummary(rule);
    var cardClass = rule.enabled
      ? "mev-rule-card mev-rule-active"
      : "mev-rule-card mev-rule-inactive";
    var checked = rule.enabled ? " checked" : "";
    var i18nCond =
      meyvoraAutomation && meyvoraAutomation.i18n && meyvoraAutomation.i18n.cond
        ? meyvoraAutomation.i18n.cond
        : "cond.";
    var i18nActions =
      meyvoraAutomation &&
      meyvoraAutomation.i18n &&
      meyvoraAutomation.i18n.actions
        ? meyvoraAutomation.i18n.actions
        : "actions";
    var i18nDelete =
      meyvoraAutomation &&
      meyvoraAutomation.i18n &&
      meyvoraAutomation.i18n.remove
        ? meyvoraAutomation.i18n.remove
        : "Delete";
    var ruleJson = typeof rule === "string" ? rule : JSON.stringify(rule);
    var ruleAttr = ruleJson
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
    var idAttr = (rule.id || "")
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
    var html =
      '<div class="' +
      cardClass +
      '" data-rule="' +
      ruleAttr +
      '" data-id="' +
      idAttr +
      '">' +
      '<div class="mev-rule-card-top">' +
      '<label class="mev-toggle-pill" title="Enable/disable">' +
      '<input type="checkbox" class="mev-rule-enabled"' +
      checked +
      ' aria-label="Enable rule"/>' +
      '<span class="mev-toggle-pill-track"></span></label>' +
      '<div class="mev-rule-meta">' +
      '<span class="mev-rule-badge">' +
      condCount +
      " " +
      i18nCond +
      "</span>" +
      '<span class="mev-rule-badge mev-rule-badge--action">' +
      actionCount +
      " " +
      i18nActions +
      "</span>" +
      '<span class="mev-rule-summary-text">' +
      $("<div/>").text(summary).html() +
      "</span>" +
      "</div>" +
      '<button type="button" class="mev-rule-delete mev-btn mev-btn--danger mev-btn--sm">' +
      i18nDelete +
      "</button>" +
      "</div></div>";
    var $row = $(html);
    $noRules.remove();
    $rulesList.append($row);
    $row.find(".mev-rule-enabled").on("change", function () {
      var r = rulesArray.find(function (x) {
        return x.id === rule.id;
      });
      if (r) {
        r.enabled = $(this).prop("checked");
        $row
          .toggleClass("mev-rule-active", r.enabled)
          .toggleClass("mev-rule-inactive", !r.enabled);
      }
      syncTextarea();
    });
    $row.find(".mev-rule-delete").on("click", function () {
      rulesArray = rulesArray.filter(function (x) {
        return x.id !== rule.id;
      });
      $row.remove();
      if (rulesArray.length === 0) {
        $rulesList.append(
          $('<p class="mev-text-muted" id="mev-no-rules"/>').text(
            meyvoraAutomation && meyvoraAutomation.i18n
              ? "No rules yet. Add one below."
              : "No rules yet. Add one below."
          )
        );
      }
      syncTextarea();
    });
  }

  function clearAddForm() {
    var $container = $("#mev-conditions-container");
    $container.find(".mev-condition-row").not(":first").remove();
    $container
      .find(".mev-condition-row")
      .first()
      .find(".mev-condition-field")
      .val("post_type");
    $container
      .find(".mev-condition-row")
      .first()
      .find(".mev-condition-operator")
      .val("equals");
    $container
      .find(".mev-condition-row")
      .first()
      .find(".mev-condition-value")
      .val("");
    $container
      .find(".mev-condition-row")
      .first()
      .find(".mev-condition-remove")
      .hide();
    $("#mev-actions-container .mev-action-check").prop("checked", false);
    $("#mev-actions-container .mev-action-value").val("");
    $("#mev-actions-container .mev-action-overwrite").prop("checked", false);
    var andRadio = document.querySelector('input[name="mev-rule-logic"][value="AND"]');
    if (andRadio) {
      andRadio.checked = true;
      document.querySelectorAll(".mev-logic-btn").forEach(function (b) {
        b.classList.remove("mev-logic-btn--active");
        if (b.querySelector('input[value="AND"]')) b.classList.add("mev-logic-btn--active");
      });
    }
  }

  function init() {
    rulesArray = getRulesFromTextarea();
    document.querySelectorAll(".mev-logic-btn").forEach(function (label) {
      var radio = label.querySelector('input[name="mev-rule-logic"]');
      if (radio) {
        label.addEventListener("click", function () {
          radio.checked = true;
          document.querySelectorAll(".mev-logic-btn").forEach(function (b) {
            b.classList.remove("mev-logic-btn--active");
          });
          label.classList.add("mev-logic-btn--active");
        });
        radio.addEventListener("change", function () {
          document.querySelectorAll(".mev-logic-btn").forEach(function (b) {
            b.classList.remove("mev-logic-btn--active");
          });
          if (radio.checked) label.classList.add("mev-logic-btn--active");
        });
      }
    });
    $rulesList.find(".mev-rule-card").each(function () {
      var $row = $(this);
      var id = $row.attr("data-id");
      var ruleJson = $row.attr("data-rule");
      var rule = null;
      try {
        rule = ruleJson ? JSON.parse(ruleJson) : null;
      } catch (e) {}
      if (rule && id) {
        $row.find(".mev-rule-enabled").on("change", function () {
          var r = rulesArray.find(function (x) {
            return x.id === id;
          });
          if (r) r.enabled = $(this).prop("checked");
          syncTextarea();
        });
        $row.find(".mev-rule-delete").on("click", function () {
          rulesArray = rulesArray.filter(function (x) {
            return x.id !== id;
          });
          $row.remove();
          if (rulesArray.length === 0) {
            $rulesList.append(
              $('<p class="mev-text-muted" id="mev-no-rules"/>').text(
                "No rules yet. Add one below."
              )
            );
          }
          syncTextarea();
        });
      }
    });

    $("#mev-add-condition").on("click", function () {
      var html = $conditionTpl.length ? $conditionTpl.html() : "";
      if (html) {
        $("#mev-conditions-container").append(html);
      } else {
        var $first = $("#mev-conditions-container .mev-condition-row").first();
        var $new = $first.clone();
        $new.find(".mev-condition-value").val("");
        $new.find(".mev-condition-remove").show();
        $new.appendTo("#mev-conditions-container");
      }
      $("#mev-conditions-container .mev-condition-row")
        .last()
        .find(".mev-condition-remove")
        .on("click", function () {
          if ($("#mev-conditions-container .mev-condition-row").length > 1)
            $(this).closest(".mev-condition-row").remove();
        });
    });

    $("#mev-add-rule").on("click", function () {
      var rule = buildRuleFromForm();
      rulesArray.push(rule);
      addRuleRow(rule);
      clearAddForm();
      syncTextarea();
    });

    $("#mev-automation-apply-all").on("click", function () {
      var $btn = $(this);
      var originalText = $btn.text();
      $btn
        .prop("disabled", true)
        .text(
          meyvoraAutomation &&
            meyvoraAutomation.i18n &&
            meyvoraAutomation.i18n.applying
            ? meyvoraAutomation.i18n.applying
            : "Applying…"
        );
      $.post(
        meyvoraAutomation && meyvoraAutomation.ajaxUrl
          ? meyvoraAutomation.ajaxUrl
          : ajaxurl,
        {
          action: "meyvora_seo_automation_apply_all",
          nonce:
            meyvoraAutomation && meyvoraAutomation.nonce
              ? meyvoraAutomation.nonce
              : "",
        }
      )
        .done(function (res) {
          if (res.success && res.data && res.data.message) {
            alert(res.data.message);
          }
        })
        .fail(function () {
          alert(
            meyvoraAutomation &&
              meyvoraAutomation.i18n &&
              meyvoraAutomation.i18n.error
              ? meyvoraAutomation.i18n.error
              : "Error"
          );
        })
        .always(function () {
          $btn.prop("disabled", false).text(originalText);
        });
    });
  }

  $(function () {
    init();
  });
})(jQuery);
