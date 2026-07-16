(function () {
    'use strict';

    var lightbox = document.getElementById('room-lightbox');
    if (!lightbox) {
        return;
    }

    var lightboxImage = lightbox.querySelector('.room-lightbox__image');
    var closeButton = lightbox.querySelector('.room-lightbox__close');
    var galleryItems = document.querySelectorAll('.room-gallery__item');
    var lastTrigger = null;

    function openLightbox(item) {
        lastTrigger = item;
        lightboxImage.src = item.dataset.full;
        lightboxImage.alt = item.dataset.alt;
        document.body.classList.add('room-lightbox-open');
        lightbox.showModal();
        closeButton.focus();
    }

    function closeLightbox() {
        lightbox.close();
    }

    galleryItems.forEach(function (item) {
        item.addEventListener('click', function () {
            openLightbox(item);
        });
    });

    closeButton.addEventListener('click', closeLightbox);

    lightbox.addEventListener('click', function (event) {
        if (event.target === lightbox) {
            closeLightbox();
        }
    });

    lightbox.addEventListener('close', function () {
        document.body.classList.remove('room-lightbox-open');
        lightboxImage.removeAttribute('src');
        lightboxImage.alt = '';

        if (lastTrigger) {
            lastTrigger.focus();
        }
    });
}());
