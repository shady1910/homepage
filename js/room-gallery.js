(function () {
    'use strict';

    var lightbox = document.getElementById('room-lightbox');
    if (!lightbox) {
        return;
    }

    var lightboxImage = lightbox.querySelector('.room-lightbox__image');
    var closeButton = lightbox.querySelector('.room-lightbox__close');
    var previousButton = lightbox.querySelector('.room-lightbox__previous');
    var nextButton = lightbox.querySelector('.room-lightbox__next');
    var galleryItems = Array.prototype.slice.call(document.querySelectorAll('.room-feature-lightbox, .room-gallery__item'));
    var currentIndex = 0;
    var lastTrigger = null;

    function getImageData(item) {
        var image = item.querySelector('img');

        return {
            source: item.dataset.full || item.getAttribute('href'),
            alt: item.dataset.alt || (image ? image.alt : '')
        };
    }

    function showImage(index) {
        var total = galleryItems.length;
        currentIndex = (index + total) % total;

        var imageData = getImageData(galleryItems[currentIndex]);
        lightboxImage.src = imageData.source;
        lightboxImage.alt = imageData.alt;
    }

    function openLightbox(item, index) {
        lastTrigger = item;
        showImage(index);
        document.body.classList.add('room-lightbox-open');
        lightbox.showModal();
        closeButton.focus();
    }

    function closeLightbox() {
        lightbox.close();
    }

    galleryItems.forEach(function (item, index) {
        item.addEventListener('click', function (event) {
            event.preventDefault();
            openLightbox(item, index);
        });
    });

    closeButton.addEventListener('click', closeLightbox);
    previousButton.addEventListener('click', function () {
        showImage(currentIndex - 1);
    });
    nextButton.addEventListener('click', function () {
        showImage(currentIndex + 1);
    });

    lightbox.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowLeft') {
            showImage(currentIndex - 1);
        }
        if (event.key === 'ArrowRight') {
            showImage(currentIndex + 1);
        }
    });

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
