/* AumViso Frontend JS */
(function () {
    'use strict';

    // ---- FAQ Accordion ----
    document.querySelectorAll('.aum-faq-list.accordion .aum-faq-question').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var item = this.closest('.aum-faq-item');
            var isOpen = item.classList.contains('open');

            // Close all
            item.closest('.aum-faq-list').querySelectorAll('.aum-faq-item.open').forEach(function (el) {
                el.classList.remove('open');
                el.querySelector('.aum-faq-answer').style.display = 'none';
            });

            // Open clicked
            if (!isOpen) {
                item.classList.add('open');
                item.querySelector('.aum-faq-answer').style.display = 'block';
            }
        });
    });

})();
