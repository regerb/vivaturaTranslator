import template from './vivatura-translator-dashboard.html.twig';
import './vivatura-translator-dashboard.scss';

const { Component, Mixin } = Shopware;

Component.register('vivatura-translator-dashboard', {
    template,

    inject: ['repositoryFactory', 'loginService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            isLoadingProducts: false,
            isLoadingCms: false,
            isLoadingSnippets: false,
            activeTab: 'products',

            // Status
            status: {
                languages: 0,
                products: 0,
                cmsPages: 0,
                snippetSets: 0
            },

            // Languages
            availableLanguages: [],
            selectedLanguages: [],

            // Products
            products: [],
            selectedProducts: [],
            productSearch: '',
            productPage: 1,
            productLimit: 25,
            productTotal: 0,

            // CMS Pages
            cmsPages: [],
            selectedCmsPages: [],
            cmsSearch: '',
            cmsPage: 1,
            cmsLimit: 25,
            cmsTotal: 0,

            // Snippets
            snippetSets: [],
            sourceSnippetSet: null,
            targetSnippetSet: null,
            snippets: [],
            selectedSnippets: [],
            snippetSearch: '',
            snippetPage: 1,
            snippetLimit: 100,
            snippetTotal: 0,

            // Translation
            isTranslating: false,
            translationProgress: 0,
            translationResults: null,

            // Settings
            languagePrompts: {}
        };
    },

    computed: {
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        authHeaders() {
            return {
                Authorization: `Bearer ${this.loginService.getToken()}`,
                'Content-Type': 'application/json'
            };
        },

        isAnyLoading() {
            return this.isLoading || this.isLoadingProducts || this.isLoadingCms || this.isLoadingSnippets;
        },

        languageRepository() {
            return this.repositoryFactory.create('language');
        },

        languagePromptRepository() {
            return this.repositoryFactory.create('viv_translator_language_prompt');
        },

        allProductsSelected() {
            return this.products.length > 0 && this.selectedProducts.length === this.products.length;
        },

        allCmsPagesSelected() {
            return this.cmsPages.length > 0 && this.selectedCmsPages.length === this.cmsPages.length;
        },

        allSnippetsSelected() {
            return this.snippets.length > 0 && this.selectedSnippets.length === this.snippets.length;
        },

        canTranslateProducts() {
            return this.selectedProducts.length > 0 && this.selectedLanguages.length > 0;
        },

        canTranslateCmsPages() {
            return this.selectedCmsPages.length > 0 && this.selectedLanguages.length > 0;
        },

        canTranslateSnippets() {
            return this.sourceSnippetSet && this.targetSnippetSet && this.sourceSnippetSet !== this.targetSnippetSet;
        }
    },

    watch: {
        sourceSnippetSet(newValue) {
            if (newValue) {
                this.loadSnippets();
            } else {
                this.snippets = [];
            }
        }
    },

    created() {
        this.loadInitialData();
    },

    methods: {
        async loadInitialData() {
            this.isLoading = true;
            try {
                await Promise.all([
                    this.loadStatus(),
                    this.loadLanguages(),
                    this.loadProducts(),
                    this.loadCmsPages(),
                    this.loadSnippetSets()
                ]);
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.message
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadStatus() {
            const response = await this.httpClient.get('/_action/vivatura-translator/translation-status', { headers: this.authHeaders });
            this.status = response.data;
        },

        async loadLanguages() {
            const response = await this.httpClient.get('/_action/vivatura-translator/languages', { headers: this.authHeaders });
            this.availableLanguages = response.data.languages || [];
        },

        // ========================================
        // PRODUCTS
        // ========================================

        async loadProducts() {
            this.isLoadingProducts = true;
            try {
                const params = new URLSearchParams({
                    page: this.productPage,
                    limit: this.productLimit,
                    search: this.productSearch
                });
                const response = await this.httpClient.get(`/_action/vivatura-translator/products?${params}`, { headers: this.authHeaders });
                this.products = response.data.products || [];
                this.productTotal = response.data.total || 0;
            } catch (error) {
                console.error('Failed to load products:', error);
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.message
                });
            } finally {
                this.isLoadingProducts = false;
            }
        },

        onProductSearch() {
            this.productPage = 1;
            this.loadProducts();
        },

        onProductPageChange(page) {
            this.productPage = page;
            this.loadProducts();
        },

        toggleProduct(productId) {
            if (this.selectedProducts.includes(productId)) {
                this.selectedProducts = this.selectedProducts.filter(id => id !== productId);
            } else {
                this.selectedProducts = [...this.selectedProducts, productId];
            }
        },

        toggleAllProducts() {
            if (this.allProductsSelected) {
                this.selectedProducts = [];
            } else {
                this.selectedProducts = this.products.map(p => p.id);
            }
        },

        async translateSelectedProducts() {
            if (!this.canTranslateProducts) return;

            this.isTranslating = true;
            this.translationProgress = 0;

            try {
                const response = await this.httpClient.post('/_action/vivatura-translator/translate-products', {
                    productIds: this.selectedProducts,
                    targetLanguageIds: this.selectedLanguages
                }, { headers: this.authHeaders });

                this.translationResults = response.data;
                this.translationProgress = 100;

                const summary = response.data.summary;
                this.createNotificationSuccess({
                    title: this.$tc('vivatura-translator.notification.successTitle'),
                    message: this.$tc('vivatura-translator.notification.productsTranslated', summary.success, { count: summary.success, errors: summary.errors })
                });

                this.selectedProducts = [];
                await this.loadProducts();
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
            } finally {
                this.isTranslating = false;
            }
        },

        // ========================================
        // CMS PAGES
        // ========================================

        async loadCmsPages() {
            this.isLoadingCms = true;
            try {
                const params = new URLSearchParams({
                    page: this.cmsPage,
                    limit: this.cmsLimit,
                    search: this.cmsSearch
                });
                const response = await this.httpClient.get(`/_action/vivatura-translator/cms-pages?${params}`, { headers: this.authHeaders });
                this.cmsPages = response.data.pages || [];
                this.cmsTotal = response.data.total || 0;
            } catch (error) {
                console.error('Failed to load CMS pages:', error);
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.message
                });
            } finally {
                this.isLoadingCms = false;
            }
        },

        onCmsSearch() {
            this.cmsPage = 1;
            this.loadCmsPages();
        },

        onCmsPageChange(page) {
            this.cmsPage = page;
            this.loadCmsPages();
        },

        toggleCmsPage(pageId) {
            if (this.selectedCmsPages.includes(pageId)) {
                this.selectedCmsPages = this.selectedCmsPages.filter(id => id !== pageId);
            } else {
                this.selectedCmsPages = [...this.selectedCmsPages, pageId];
            }
        },

        toggleAllCmsPages() {
            if (this.allCmsPagesSelected) {
                this.selectedCmsPages = [];
            } else {
                this.selectedCmsPages = this.cmsPages.map(p => p.id);
            }
        },

        async translateSelectedCmsPages() {
            if (!this.canTranslateCmsPages) return;

            this.isTranslating = true;
            this.translationProgress = 0;

            try {
                const response = await this.httpClient.post('/_action/vivatura-translator/translate-cms-pages', {
                    pageIds: this.selectedCmsPages,
                    targetLanguageIds: this.selectedLanguages
                }, { headers: this.authHeaders });

                this.translationResults = response.data;
                this.translationProgress = 100;

                const summary = response.data.summary;
                this.createNotificationSuccess({
                    title: this.$tc('vivatura-translator.notification.successTitle'),
                    message: this.$tc('vivatura-translator.notification.cmsPagesTranslated', summary.success, { count: summary.success, errors: summary.errors })
                });

                this.selectedCmsPages = [];
                await this.loadCmsPages();
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
            } finally {
                this.isTranslating = false;
            }
        },

        // ========================================
        // SNIPPETS
        // ========================================

        async loadSnippetSets() {
            const response = await this.httpClient.get('/_action/vivatura-translator/snippet-sets', { headers: this.authHeaders });
            this.snippetSets = response.data.snippetSets || [];
        },

        async onSourceSnippetSetChange(value) {
            // Value might be passed from event or use the current sourceSnippetSet
            const setId = value || this.sourceSnippetSet;
            if (setId) {
                await this.loadSnippets();
            } else {
                this.snippets = [];
            }
        },

        async loadSnippets() {
            if (!this.sourceSnippetSet) return;

            this.isLoadingSnippets = true;
            try {
                const params = new URLSearchParams({
                    setId: this.sourceSnippetSet,
                    page: this.snippetPage,
                    limit: this.snippetLimit,
                    search: this.snippetSearch
                });
                const response = await this.httpClient.get(`/_action/vivatura-translator/snippets?${params}`, { headers: this.authHeaders });
                this.snippets = response.data.snippets || [];
                this.snippetTotal = response.data.total || 0;
            } catch (error) {
                console.error('Failed to load snippets:', error);
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.message
                });
            } finally {
                this.isLoadingSnippets = false;
            }
        },

        onSnippetSearch() {
            this.snippetPage = 1;
            this.loadSnippets();
        },

        onSnippetPageChange(page) {
            this.snippetPage = page;
            this.loadSnippets();
        },

        toggleSnippet(snippetId) {
            if (this.selectedSnippets.includes(snippetId)) {
                this.selectedSnippets = this.selectedSnippets.filter(id => id !== snippetId);
            } else {
                this.selectedSnippets = [...this.selectedSnippets, snippetId];
            }
        },

        toggleAllSnippets() {
            if (this.allSnippetsSelected) {
                this.selectedSnippets = [];
            } else {
                this.selectedSnippets = this.snippets.map(s => s.id);
            }
        },

        async translateSnippetSet() {
            if (!this.canTranslateSnippets) return;

            this.isTranslating = true;
            this.translationProgress = 0;

            try {
                const payload = {
                    sourceSetId: this.sourceSnippetSet,
                    targetSetId: this.targetSnippetSet
                };

                // If specific snippets selected, include them
                if (this.selectedSnippets.length > 0) {
                    payload.snippetIds = this.selectedSnippets;
                }

                const response = await this.httpClient.post('/_action/vivatura-translator/translate-snippet-set', payload, { headers: this.authHeaders });

                this.translationResults = response.data;
                this.translationProgress = 100;

                const result = response.data.results;
                this.createNotificationSuccess({
                    title: this.$tc('vivatura-translator.notification.successTitle'),
                    message: this.$tc('vivatura-translator.notification.snippetsTranslated', result.translated, { count: result.translated, errors: result.errors })
                });

                this.selectedSnippets = [];
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
            } finally {
                this.isTranslating = false;
            }
        },

        // ========================================
        // LANGUAGES
        // ========================================

        toggleLanguage(languageId) {
            if (this.selectedLanguages.includes(languageId)) {
                this.selectedLanguages = this.selectedLanguages.filter(id => id !== languageId);
            } else {
                this.selectedLanguages = [...this.selectedLanguages, languageId];
            }
        },

        selectAllLanguages() {
            this.selectedLanguages = this.availableLanguages.map(l => l.id);
        },

        deselectAllLanguages() {
            this.selectedLanguages = [];
        },

        isLanguageSelected(languageId) {
            return this.selectedLanguages.includes(languageId);
        },

        // ========================================
        // SETTINGS
        // ========================================

        async loadLanguagePrompts() {
            const criteria = new Shopware.Data.Criteria();
            const prompts = await this.languagePromptRepository.search(criteria, Shopware.Context.api);

            this.languagePrompts = {};
            prompts.forEach(prompt => {
                this.languagePrompts[prompt.languageId] = prompt;
            });
        },

        getPromptForLanguage(languageId) {
            return this.languagePrompts[languageId]?.systemPrompt || '';
        },

        setPromptForLanguage(languageId, value) {
            if (!this.languagePrompts[languageId]) {
                const newPrompt = this.languagePromptRepository.create(Shopware.Context.api);
                newPrompt.languageId = languageId;
                newPrompt.systemPrompt = value;
                this.languagePrompts[languageId] = newPrompt;
            } else {
                this.languagePrompts[languageId].systemPrompt = value;
            }
        },

        async savePrompts() {
            this.isLoading = true;
            try {
                const savePromises = Object.values(this.languagePrompts).map(prompt => {
                    if (prompt.id) {
                        return this.languagePromptRepository.save(prompt, Shopware.Context.api);
                    }
                    return this.languagePromptRepository.create(prompt, Shopware.Context.api);
                });

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
                this.isLoading = false;
            }
        },

        onTabChange(tabName) {
            // Handle both string and object from sw-tabs event
            const newTab = typeof tabName === 'string' ? tabName : tabName?.name || tabName;
            this.activeTab = newTab;

            // Reload data when switching tabs
            if (newTab === 'products') {
                this.loadProducts();
            } else if (newTab === 'cms') {
                this.loadCmsPages();
            } else if (newTab === 'snippets') {
                this.loadSnippetSets();
            } else if (newTab === 'settings') {
                this.loadLanguagePrompts();
            }
        }
    }
});
