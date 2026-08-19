(function () {
    'use strict';

    var widgetSource = 'https://userlike-cdn-widgets.s3-eu-west-1.amazonaws.com/9fd53d84a2164a57be439fbebbe8285a6ae05bbf510c48fab8c6b372c6d89889.js';

    function loadChatWidget() {
        if (document.querySelector('script[src="' + widgetSource + '"]')) {
            return;
        }

        var widgetScript = document.createElement('script');
        widgetScript.async = true;
        widgetScript.src = widgetSource;
        document.head.appendChild(widgetScript);
    }

    function scheduleChatWidget() {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(loadChatWidget, { timeout: 2000 });
        } else {
            window.setTimeout(loadChatWidget, 1000);
        }
    }

    if (document.readyState === 'complete') {
        scheduleChatWidget();
    } else {
        window.addEventListener('load', scheduleChatWidget, { once: true });
    }
}());
