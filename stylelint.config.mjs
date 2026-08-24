import recommended from 'stylelint-config-recommended';

export default {
    ignoreFiles: [
        'css/bootstrap.min.css',
        'css/flex_slider.css',
        'css/style.css',
        'css/vendors.min.css',
        'css/bs-icon-font/**/*.css',
        'css/custom-icons/**/*.css'
    ],
    rules: {
        ...recommended.rules,
        'no-descending-specificity': null
    }
};
