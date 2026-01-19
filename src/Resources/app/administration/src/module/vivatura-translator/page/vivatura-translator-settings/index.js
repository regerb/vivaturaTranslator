import template from './vivatura-translator-settings.html.twig';
import './vivatura-translator-settings.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('vivatura-translator-settings', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            languages: [],
            languagePrompts: {},
            isSaving: false
        };
    },

    computed: {
        languageRepository() {
            return this.repositoryFactory.create('language');
        },

        languagePromptRepository() {
            return this.repositoryFactory.create('viv_translator_language_prompt');
        }
    },

    created() {
        this.loadData();
    },

    methods: {
        async loadData() {
            this.isLoading = true;

            try {
                // Load all languages
                const criteria = new Criteria();
                criteria.addAssociation('locale');

                const languages = await this.languageRepository.search(criteria, Shopware.Context.api);
                this.languages = languages;

                // Load language prompts
                const promptCriteria = new Criteria();
                const prompts = await this.languagePromptRepository.search(promptCriteria, Shopware.Context.api);

                // Map prompts by language ID
                this.languagePrompts = {};
                prompts.forEach(prompt => {
                    this.languagePrompts[prompt.languageId] = prompt;
                });

            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.message
                });
            } finally {
                this.isLoading = false;
            }
        },

        getPromptForLanguage(languageId) {
            return this.languagePrompts[languageId]?.systemPrompt || '';
        },

        setPromptForLanguage(languageId, value) {
            if (!this.languagePrompts[languageId]) {
                // Create new prompt entity
                const newPrompt = this.languagePromptRepository.create(Shopware.Context.api);
                newPrompt.languageId = languageId;
                newPrompt.systemPrompt = value;
                this.languagePrompts[languageId] = newPrompt;
            } else {
                this.languagePrompts[languageId].systemPrompt = value;
            }
        },

        async savePrompts() {
            this.isSaving = true;

            try {
                const savePromises = [];

                for (const languageId in this.languagePrompts) {
                    const prompt = this.languagePrompts[languageId];
                    if (prompt.systemPrompt) {
                        savePromises.push(
                            this.languagePromptRepository.save(prompt, Shopware.Context.api)
                        );
                    }
                }

                await Promise.all(savePromises);

                this.createNotificationSuccess({
                    title: this.$tc('vivatura-translator.notification.successTitle'),
                    message: this.$tc('vivatura-translator.settings.promptsSaved')
                });

            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.message
                });
            } finally {
                this.isSaving = false;
            }
        },

        getLanguageDisplayName(language) {
            const locale = language.locale?.code || '';
            return `${language.name} (${locale})`;
        }
    }
});
