/** Receipt page: Print button, and auto-print when opened by the POS (?autoprint=1). */
(function () {
    'use strict';

    const btn = document.getElementById('printBtn');
    if (btn) btn.addEventListener('click', () => window.print());

    if (document.body.dataset.autoprint === '1') {
        window.addEventListener('load', () => window.print());
    }
})();
