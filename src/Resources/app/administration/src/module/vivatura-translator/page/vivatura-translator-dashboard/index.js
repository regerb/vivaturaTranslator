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

            // Bulk snippet translation
            bulkSourceIso: 'de-DE',
            bulkTargetIso: 'fr-FR',

            // Snippet files
            snippetFiles: [],
            filteredSnippetFiles: [],
            snippetFilesSourceLang: 'de-DE',
            snippetFilesTargetLang: 'fr-FR',

            // Translation
            isTranslating: false,
            translationProgress: 0,
            translationResults: null,
            activeJobIds: [],
            pollingInterval: null,

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
        },

        canTranslateAllSnippetSets() {
            return this.bulkSourceIso && this.bulkTargetIso && this.bulkSourceIso !== this.bulkTargetIso;
        }
    },

    watch: {
    },

    created() {
        this.loadInitialData();
        this.loadAllSnippetFiles(); // Load snippet files on init
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

        async loadAllSnippetFiles() {
            try {
                const response = await this.httpClient.get('/_action/vivatura-translator/snippet-files', {
                    headers: this.authHeaders
                });
                this.snippetFiles = response.data.snippetFiles || [];
            } catch (error) {
                console.error('Failed to load snippet files:', error);
            }
        },

        async loadSnippetFiles() {
            try {
                const response = await this.httpClient.get(
                    `/_action/vivatura-translator/snippet-files?language=${this.snippetFilesSourceLang}`,
                    { headers: this.authHeaders }
                );
                this.filteredSnippetFiles = response.data.snippetFiles || [];

                if (this.filteredSnippetFiles.length === 0) {
                    this.createNotificationWarning({
                        title: this.$tc('vivatura-translator.notification.warningTitle'),
                        message: this.$tc('vivatura-translator.dashboard.noSnippetFilesFound')
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.message
                });
            }
        },

        async translateSnippetFile(file) {
            if (!confirm(`Translate ${file.filename} from ${file.language} to ${this.snippetFilesTargetLang}?`)) {
                return;
            }

            this.isTranslating = true;

            try {
                const response = await this.httpClient.post('/_action/vivatura-translator/translate-snippet-file', {
                    sourceFilePath: file.fullPath,
                    targetLanguage: this.snippetFilesTargetLang
                }, { headers: this.authHeaders });

                this.isTranslating = false;

                if (response.data.success) {
                    this.createNotificationSuccess({
                        title: this.$tc('vivatura-translator.notification.successTitle'),
                        message: `Translated ${response.data.translated}/${response.data.total} snippets to ${response.data.targetFile}`
                    });
                }
            } catch (error) {
                this.isTranslating = false;
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
            }
        },

        async translateAllSnippetFiles() {
            const totalFiles = this.filteredSnippetFiles.reduce((sum, source) => sum + source.files.length, 0);

            if (!confirm(`Translate all ${totalFiles} snippet files from ${this.snippetFilesSourceLang} to ${this.snippetFilesTargetLang}?`)) {
                return;
            }

            this.isTranslating = true;
            let translated = 0;
            let failed = 0;

            for (const source of this.filteredSnippetFiles) {
                for (const file of source.files) {
                    try {
                        await this.httpClient.post('/_action/vivatura-translator/translate-snippet-file', {
                            sourceFilePath: file.fullPath,
                            targetLanguage: this.snippetFilesTargetLang
                        }, { headers: this.authHeaders });
                        translated++;
                    } catch (error) {
                        console.error(`Failed to translate ${file.filename}:`, error);
                        failed++;
                    }
                }
            }

            this.isTranslating = false;

            this.createNotificationSuccess({
                title: this.$tc('vivatura-translator.notification.successTitle'),
                message: `Translated ${translated} files successfully (${failed} failed)`
            });
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

        onProductSearch(term) {
            this.productSearch = term;
            this.productPage = 1;
            this.loadProducts();
        },

        onProductPageChange(page) {
            this.productPage = typeof page === 'object' ? page.page : page;
            this.loadProducts();
        },

        onProductSelectionChange(selection) {
            this.selectedProducts = Object.keys(selection);
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

                if (response.data.async) {
                    // Async mode - start polling for job status
                    this.activeJobIds = Object.values(response.data.jobIds).filter(id => typeof id === 'string');
                    this.createNotificationInfo({
                        title: this.$tc('vivatura-translator.notification.infoTitle'),
                        message: response.data.message
                    });
                    this.startPolling();
                } else {
                    // Sync mode fallback
                    this.translationResults = response.data;
                    this.translationProgress = 100;
                    this.onTranslationComplete(response.data);
                }

                this.selectedProducts = [];
            } catch (error) {
                this.isTranslating = false;
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
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

        onCmsSearch(term) {
            this.cmsSearch = term;
            this.cmsPage = 1;
            this.loadCmsPages();
        },

        onCmsPageChange(page) {
            this.cmsPage = typeof page === 'object' ? page.page : page;
            this.loadCmsPages();
        },

        onCmsSelectionChange(selection) {
            this.selectedCmsPages = Object.keys(selection);
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

                if (response.data.async) {
                    // Async mode - start polling for job status
                    this.activeJobIds = Object.values(response.data.jobIds).filter(id => typeof id === 'string');
                    this.createNotificationInfo({
                        title: this.$tc('vivatura-translator.notification.infoTitle'),
                        message: response.data.message
                    });
                    this.startPolling();
                } else {
                    // Sync mode fallback
                    this.translationResults = response.data;
                    this.translationProgress = 100;
                    this.onTranslationComplete(response.data);
                }

                this.selectedCmsPages = [];
            } catch (error) {
                this.isTranslating = false;
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
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
            // Ensure we use the selected value immediately
            this.sourceSnippetSet = value;

            if (this.sourceSnippetSet) {
                await this.loadSnippets();
            } else {
                this.snippets = [];
            }
        },

        onTargetSnippetSetChange(value) {
            this.targetSnippetSet = value;
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

        onSnippetSearch(term) {
            this.snippetSearch = term;
            this.snippetPage = 1;
            this.loadSnippets();
        },

        onSnippetPageChange(page) {
            this.snippetPage = typeof page === 'object' ? page.page : page;
            this.loadSnippets();
        },

        onSnippetSelectionChange(selection) {
            this.selectedSnippets = Object.keys(selection);
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

                if (response.data.async) {
                    // Async mode - start polling for job status
                    this.activeJobIds = [response.data.jobId];
                    this.createNotificationInfo({
                        title: this.$tc('vivatura-translator.notification.infoTitle'),
                        message: response.data.message
                    });
                    this.startPolling();
                } else {
                    // Sync mode fallback
                    this.translationResults = response.data;
                    this.translationProgress = 100;
                    this.onTranslationComplete(response.data);
                }

                this.selectedSnippets = [];
            } catch (error) {
                this.isTranslating = false;
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
            }
        },

        async translateAllSnippetSets() {
            if (!this.canTranslateAllSnippetSets) return;

            this.isTranslating = true;
            this.translationProgress = 0;

            try {
                const response = await this.httpClient.post('/_action/vivatura-translator/translate-all-snippet-sets', {
                    sourceIso: this.bulkSourceIso,
                    targetIso: this.bulkTargetIso
                }, { headers: this.authHeaders });

                if (response.data.async) {
                    // Async mode - start polling for job status
                    this.activeJobIds = Object.values(response.data.jobIds).filter(id => typeof id === 'string');

                    const message = response.data.message ||
                        `Queued ${response.data.matched} snippet set translations (${response.data.skipped} skipped)`;

                    this.createNotificationInfo({
                        title: this.$tc('vivatura-translator.notification.infoTitle'),
                        message: message
                    });
                    this.startPolling();
                } else {
                    // Sync mode fallback
                    this.translationResults = response.data;
                    this.translationProgress = 100;
                    this.onTranslationComplete(response.data);
                }
            } catch (error) {
                this.isTranslating = false;
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: error.response?.data?.error || error.message
                });
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
        },

        // ========================================
        // JOB POLLING
        // ========================================

        startPolling() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
            }

            this.pollingInterval = setInterval(() => {
                this.checkJobStatus();
            }, 2000);
        },

        stopPolling() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
            }
        },

        async checkJobStatus() {
            if (this.activeJobIds.length === 0) {
                this.stopPolling();
                return;
            }

            try {
                const response = await this.httpClient.post('/_action/vivatura-translator/jobs-status', {
                    jobIds: this.activeJobIds
                }, { headers: this.authHeaders });

                const jobs = response.data.jobs || {};
                const jobStatuses = Object.values(jobs);

                // Calculate progress
                const completedCount = jobStatuses.filter(j => j.status === 'completed' || j.status === 'failed').length;
                const totalCount = this.activeJobIds.length;
                this.translationProgress = Math.round((completedCount / totalCount) * 100);

                // Check if all jobs are done
                const allDone = jobStatuses.every(j => j.status === 'completed' || j.status === 'failed');

                if (allDone) {
                    this.stopPolling();

                    // Compile results
                    const successCount = jobStatuses.filter(j => j.status === 'completed').length;
                    const errorCount = jobStatuses.filter(j => j.status === 'failed').length;

                    this.translationResults = {
                        summary: {
                            total: totalCount,
                            success: successCount,
                            errors: errorCount
                        },
                        jobs: jobs
                    };

                    this.isTranslating = false;
                    this.activeJobIds = [];

                    if (errorCount === 0) {
                        this.createNotificationSuccess({
                            title: this.$tc('vivatura-translator.notification.successTitle'),
                            message: this.$tc('vivatura-translator.notification.translationCompleted', successCount, { count: successCount })
                        });
                    } else {
                        this.createNotificationWarning({
                            title: this.$tc('vivatura-translator.notification.warningTitle'),
                            message: this.$tc('vivatura-translator.notification.translationPartial', successCount, { success: successCount, errors: errorCount })
                        });
                    }

                    // Reload current data
                    if (this.activeTab === 'products') {
                        await this.loadProducts();
                    } else if (this.activeTab === 'cms') {
                        await this.loadCmsPages();
                    }
                }
            } catch (error) {
                console.error('Failed to check job status:', error);
            }
        },

        onTranslationComplete(data) {
            this.isTranslating = false;

            const summary = data.summary || { success: 1, errors: 0 };
            if (summary.errors === 0) {
                this.createNotificationSuccess({
                    title: this.$tc('vivatura-translator.notification.successTitle'),
                    message: this.$tc('vivatura-translator.notification.translationCompleted', summary.success, { count: summary.success })
                });
            } else {
                this.createNotificationWarning({
                    title: this.$tc('vivatura-translator.notification.warningTitle'),
                    message: this.$tc('vivatura-translator.notification.translationPartial', summary.success, { success: summary.success, errors: summary.errors })
                });
            }

            // Reload current data
            if (this.activeTab === 'products') {
                this.loadProducts();
            } else if (this.activeTab === 'cms') {
                this.loadCmsPages();
            }
        }
    }
});
