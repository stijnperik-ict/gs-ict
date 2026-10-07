(function () {
    'use strict';

    function render() {
        if (typeof window.QRCode === 'undefined') {
            return;
        }

        document.querySelectorAll('.gs-ict-qrcode[data-otpauth]').forEach(function (element) {
            if (element.dataset.rendered === '1') {
                return;
            }
            element.dataset.rendered = '1';
            element.innerHTML = '';
            new window.QRCode(element, {
                text: element.getAttribute('data-otpauth'),
                width: 200,
                height: 200,
                correctLevel: window.QRCode.CorrectLevel.M
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
}());
