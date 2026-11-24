(function () {
    'use strict';

    var residence = document.getElementById('residence-content');
    if (!residence || typeof window.lightGallery !== 'function') {
        return;
    }

    window.lightGallery(residence, {
        selector: '.room-feature-lightbox, .room-gallery__item',
        plugins: [window.lgThumbnail, window.lgZoom],
        ariaLabelledby: 'room-gallery-title',
        download: false,
        hideScrollbar: true,
        showZoomInOutIcons: true,
        mobileSettings: {
            controls: true,
            showCloseIcon: true,
            download: false,
            showGestureButtons: true
        },
        strings: {
            closeGallery: 'Galerie schließen',
            previousSlide: 'Vorheriges Bild',
            nextSlide: 'Nächstes Bild',
            mediaLoadingFailed: 'Das Bild konnte nicht geladen werden.',
            moreOptions: 'Weitere Optionen',
            slideAnnouncement: 'Bild {index} von {total}',
            toggleThumbnails: 'Vorschaubilder ein- oder ausblenden',
            zoomIn: 'Vergrößern',
            zoomOut: 'Verkleinern',
            viewActualSize: 'Originalgröße anzeigen'
        }
    });
})();
