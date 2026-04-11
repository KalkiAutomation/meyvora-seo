/**
 * Meyvora FAQ – Frontend accordion JS
 * Vanilla JS, no dependencies. Uses dynamic max-height from content for smooth open/close.
 */
(function () {
    'use strict';

    /**
     * Get the natural height of the answer panel (content height) without flashing.
     */
    function getAnswerHeight(answer) {
        var inner = answer.querySelector('.meyvora-faq-answer-inner');
        if (inner) {
            var style = answer.style.cssText;
            answer.style.maxHeight = 'none';
            answer.style.visibility = 'hidden';
            answer.style.display = 'block';
            var h = answer.scrollHeight;
            answer.style.cssText = style;
            return h;
        }
        return answer.scrollHeight;
    }

    function setOpen(answer, open) {
        if (open) {
            var h = getAnswerHeight(answer);
            answer.classList.remove('is-open');
            answer.style.maxHeight = '0';
            answer.offsetHeight; /* force reflow so 0 is painted */
            requestAnimationFrame(function () {
                answer.classList.add('is-open');
                answer.style.maxHeight = h + 'px';
            });
        } else {
            var currentH = answer.scrollHeight;
            answer.style.maxHeight = currentH + 'px';
            answer.offsetHeight; /* reflow */
            answer.classList.remove('is-open');
            answer.style.maxHeight = '0';
            var onEnd = function () {
                answer.removeEventListener('transitionend', onEnd);
                answer.style.maxHeight = '';
            };
            answer.addEventListener('transitionend', onEnd);
        }
    }

    function closeAnswer(answer) {
        if (!answer.classList.contains('is-open')) return;
        var currentH = answer.scrollHeight;
        answer.style.maxHeight = currentH + 'px';
        answer.offsetHeight;
        answer.classList.remove('is-open');
        answer.style.maxHeight = '0';
        var onEnd = function () {
            answer.removeEventListener('transitionend', onEnd);
            answer.style.maxHeight = '';
        };
        answer.addEventListener('transitionend', onEnd);
    }

    function initFaqList(list) {
        if (list.classList.contains('meyvora-faq-list--show-all')) {
            return;
        }

        var items     = list.querySelectorAll('.meyvora-faq-item');
        var openFirst = list.dataset.openFirst !== 'false';
        var multiple  = list.dataset.multiple === 'true';

        items.forEach(function (item, idx) {
            var btn    = item.querySelector('.meyvora-faq-question');
            var answer = item.querySelector('.meyvora-faq-answer');
            if (!btn || !answer) return;

            var startOpen = openFirst && idx === 0;
            btn.setAttribute('aria-expanded', startOpen ? 'true' : 'false');
            if (startOpen) {
                answer.classList.add('is-open');
                answer.style.maxHeight = 'none';
                requestAnimationFrame(function () {
                    var h = answer.scrollHeight;
                    answer.style.maxHeight = h + 'px';
                });
            }

            btn.addEventListener('click', function () {
                var isExpanded = btn.getAttribute('aria-expanded') === 'true';

                if (!multiple && !isExpanded) {
                    items.forEach(function (other) {
                        if (other === item) return;
                        var ob = other.querySelector('.meyvora-faq-question');
                        var oa = other.querySelector('.meyvora-faq-answer');
                        if (ob) ob.setAttribute('aria-expanded', 'false');
                        if (oa) closeAnswer(oa);
                    });
                }

                var next = !isExpanded;
                btn.setAttribute('aria-expanded', String(next));
                setOpen(answer, next);
            });
        });
    }

    function init() {
        document.querySelectorAll('.meyvora-faq-list').forEach(initFaqList);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
