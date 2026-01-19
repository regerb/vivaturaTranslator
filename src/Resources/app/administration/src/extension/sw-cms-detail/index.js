import template from './sw-cms-detail.html.twig';

const { Component } = Shopware;

Component.override('sw-cms-detail', {
    template,

    computed: {
        page() {
            return this.cmsPage;
        }
    }
});
