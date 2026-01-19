import template from './viv-translator-button.html.twig';
import './viv-translator-button.scss';

const { Component, Mixin } = Shopware;

Component.register('viv-translator-button', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        entityType: {
            type: String,
            required: true,
            validator(value) {
                return ['product', 'cms-page', 'snippet'].includes(value);
            }
        },
        entityId: {
            type: String,
            required: true
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false
        }
    },

    data() {
        return {
            isLoading: false,
            showModal: false,
            availableLanguages: [],
            selectedLanguages: [],
            translationProgress: 0,
            translationResults: null
        };
    },

    computed: {
        httpClient() {
            return Shopware.Application.getContainer('service').httpClient;
        },

        buttonLabel() {
            return this.$tc('viv-translator.button.translate');
        },

        modalTitle() {
            return this.$tc('viv-translator.modal.title');
        }
    },

    methods: {
        async openModal() {
            this.showModal = true;
            this.selectedLanguages = [];
            this.translationResults = null;
            await this.loadLanguages();
        },

        closeModal() {
            this.showModal = false;
            this.translationProgress = 0;
        },

        async loadLanguages() {
            this.isLoading = true;
            try {
                const response = await this.httpClient.get('/api/viv-translator/languages');
                this.availableLanguages = response.data.languages || [];
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('viv-translator.notification.errorTitle'),
                    message: error.message || this.$tc('viv-translator.notification.loadLanguagesError')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async startTranslation() {
            if (this.selectedLanguages.length === 0) {
                this.createNotificationWarning({
                    title: this.$tc('viv-translator.notification.warningTitle'),
                    message: this.$tc('viv-translator.notification.noLanguagesSelected')
                });
                return;
            }

            this.isLoading = true;
            this.translationProgress = 0;

            try {
                const endpoint = this.getEndpoint();
                const payload = {
                    targetLanguageIds: this.selectedLanguages
                };

                const response = await this.httpClient.post(endpoint, payload);

                this.translationResults = response.data.results;
                this.translationProgress = 100;

                this.createNotificationSuccess({
                    title: this.$tc('viv-translator.notification.successTitle'),
                    message: this.$tc('viv-translator.notification.translationComplete')
                });

                // Emit event to refresh parent component
                this.$emit('translation-complete', this.translationResults);

            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('viv-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
            } finally {
                this.isLoading = false;
            }
        },

        getEndpoint() {
            switch (this.entityType) {
                case 'product':
                    return `/api/viv-translator/translate-product/${this.entityId}`;
                case 'cms-page':
                    return `/api/viv-translator/translate-cms-page/${this.entityId}`;
                case 'snippet':
                    return `/api/viv-translator/translate-snippet/${this.entityId}`;
                default:
                    throw new Error('Unknown entity type');
            }
        },

        toggleLanguage(languageId) {
            const index = this.selectedLanguages.indexOf(languageId);
            if (index === -1) {
                this.selectedLanguages.push(languageId);
            } else {
                this.selectedLanguages.splice(index, 1);
            }
        },

        selectAllLanguages() {
            this.selectedLanguages = this.availableLanguages.map(lang => lang.id);
        },

        deselectAllLanguages() {
            this.selectedLanguages = [];
        },

        isLanguageSelected(languageId) {
            return this.selectedLanguages.includes(languageId);
        },

        getResultStatus(languageCode) {
            if (!this.translationResults || !this.translationResults[languageCode]) {
                return null;
            }
            return this.translationResults[languageCode].success ? 'success' : 'error';
        }
    }
});
