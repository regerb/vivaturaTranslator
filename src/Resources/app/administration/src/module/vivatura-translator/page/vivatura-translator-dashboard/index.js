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
            snippetLoadRequestId: 0,

            // Bulk snippet translation
            bulkSourceIso: null,
            bulkTargetIso: null,

            // Snippet files
            snippetFiles: [],
            filteredSnippetFiles: [],
            snippetFilesSourceLang: null,
            snippetFilesTargetLang: null,

            // Translation
            isTranslating: false,
            translationProgress: 0,
            translationResults: null,
            activeJobIds: [],
            pollingInterval: null,
            overwriteExisting: false,

            // Settings
            languagePrompts: {},

            // Pagination
            paginationSteps: [10, 25, 50, 100, 250, 500]
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
        },

        languageOptions() {
            return this.availableLanguages.map(lang => ({
                id: lang.id,
                value: lang.locale,
                label: `${lang.name} (${lang.locale})`
            }));
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

        async translateSnippetFile(file, batchSize = null) {
            console.log('[SnippetFileTranslation] Starting translation for:', file.filename, {
                snippetCount: file.snippetCount,
                batchSize,
                overwriteExisting: this.overwriteExisting
            });

            // Automatically use batching for large files (>50 snippets)
            const shouldBatch = !batchSize && file.snippetCount > 50;

            if (shouldBatch) {
                console.log('[SnippetFileTranslation] Large file detected, using batch translation');
                return await this.translateSnippetFileInBatches(file);
            }

            if (!confirm(`Translate ${file.filename} (${file.snippetCount} snippets) from ${file.language} to ${this.snippetFilesTargetLang}?`)) {
                return;
            }

            this.isTranslating = true;
            const startTime = Date.now();

            try {
                console.log('[SnippetFileTranslation] Sending request...', {
                    sourceFilePath: file.fullPath,
                    targetLanguage: this.snippetFilesTargetLang,
                    snippetCount: file.snippetCount,
                    overwriteExisting: this.overwriteExisting
                });

                const response = await this.httpClient.post('/_action/vivatura-translator/translate-snippet-file', {
                    sourceFilePath: file.fullPath,
                    targetLanguage: this.snippetFilesTargetLang,
                    batchSize: batchSize || null,
                    overwriteExisting: this.overwriteExisting
                }, {
                    headers: this.authHeaders,
                    timeout: 300000 // 5 minutes timeout
                });

                const duration = ((Date.now() - startTime) / 1000).toFixed(1);
                console.log(`[SnippetFileTranslation] Completed in ${duration}s`, response.data);

                this.isTranslating = false;

                if (response.data.success) {
                    const msg = response.data.message || `✓ ${file.filename}: ${response.data.translated}/${response.data.total} snippets translated (${duration}s)`;
                    this.createNotificationSuccess({
                        title: this.$tc('vivatura-translator.notification.successTitle'),
                        message: msg
                    });
                }
            } catch (error) {
                const duration = ((Date.now() - startTime) / 1000).toFixed(1);
                console.error(`[SnippetFileTranslation] Failed after ${duration}s:`, error);

                this.isTranslating = false;
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: `✗ ${file.filename}: ${error.response?.data?.error || error.message}`
                });
            }
        },

        async translateSnippetFileInBatches(file) {
            console.log('[SnippetFileTranslation] Starting batch-mode translation for large file:', file.filename);
            console.log('[SnippetFileTranslation] Overwrite existing translations:', this.overwriteExisting);

            if (!confirm(`Translate ${file.filename} (${file.snippetCount} snippets) from ${file.language} to ${this.snippetFilesTargetLang}?\n\nThis is a large file and will be processed in batches.`)) {
                return;
            }

            this.isTranslating = true;
            const startTime = Date.now();

            try {
                // Read the file first to get all snippets
                console.log('[SnippetFileTranslation] Reading source file...');
                const readResponse = await this.httpClient.post('/_action/vivatura-translator/read-snippet-file', {
                    filePath: file.fullPath
                }, { headers: this.authHeaders });

                const allSnippets = readResponse.data.snippets || {};
                const snippetKeys = Object.keys(allSnippets);
                const totalSnippets = snippetKeys.length;
                console.log(`[SnippetFileTranslation] Read ${totalSnippets} snippets from source file`);

                // Construct target file path assumption
                const targetFilePath = file.fullPath.replace(
                    new RegExp(`\\.${file.language}\\.json$`),
                    `.${this.snippetFilesTargetLang}.json`
                );

                let existingTargetSnippets = {};
                try {
                    console.log('[SnippetFileTranslation] Checking for existing target file:', targetFilePath);
                    const targetResponse = await this.httpClient.post('/_action/vivatura-translator/read-snippet-file', {
                        filePath: targetFilePath
                    }, { headers: this.authHeaders });
                    existingTargetSnippets = targetResponse.data.snippets || {};
                    console.log(`[SnippetFileTranslation] Found existing target file with ${Object.keys(existingTargetSnippets).length} snippets`);
                } catch (e) {
                    console.log('[SnippetFileTranslation] No existing target file found or error reading it (expected for new translations)');
                }

                // Filter keys to process
                const keysToProcess = snippetKeys.filter(key => {
                    if (this.overwriteExisting) return true;
                    // If not overwriting, only process if key doesn't exist in target
                    const exists = Object.prototype.hasOwnProperty.call(existingTargetSnippets, key);
                    return !exists;
                });

                const skippedCount = totalSnippets - keysToProcess.length;
                console.log(`[SnippetFileTranslation] Snippets to translate: ${keysToProcess.length} (Skipped: ${skippedCount})`);

                if (keysToProcess.length === 0) {
                     console.log('[SnippetFileTranslation] Nothing to translate (all exist and overwrite is off)');
                     this.isTranslating = false;
                     this.createNotificationInfo({
                        title: this.$tc('vivatura-translator.notification.infoTitle'),
                        message: `All ${totalSnippets} snippets already exist in target file.`
                     });
                     return;
                }

                // Split into batches of 20 snippets
                const batchSize = 20;
                let batches = [];

                for (let i = 0; i < keysToProcess.length; i += batchSize) {
                    const batchKeys = keysToProcess.slice(i, i + batchSize);
                    const batchSnippets = {};
                    batchKeys.forEach(key => {
                        batchSnippets[key] = allSnippets[key];
                    });
                    batches.push(batchSnippets);
                }

                console.log(`[SnippetFileTranslation] Split into ${batches.length} batches of ~${batchSize} snippets`);

                // Initialize allTranslated depending on overwrite mode
                // If overwrite: Start empty (or copy existing only as backup for failures) - logic handled in backend mostly,
                // but for frontend counting we can just accumulate new translations.
                // Actually, backend now does the smart merge/replace. Frontend just needs to send all chunks.
                // However, we still want to track progress.

                let allTranslated = {};
                let totalTranslated = 0;
                let totalErrors = 0;

                for (let i = 0; i < batches.length; i++) {
                    const batchNum = i + 1;
                    const progress = Math.round((batchNum / batches.length) * 100);

                    console.log(`[SnippetFileTranslation] Processing batch ${batchNum}/${batches.length} (${progress}%)`);
                    this.translationProgress = progress;

                    try {
                        const batchResponse = await this.httpClient.post('/_action/vivatura-translator/translate-snippet-batch', {
                            snippets: batches[i],
                            targetLanguage: this.snippetFilesTargetLang
                        }, {
                            headers: this.authHeaders,
                            timeout: 180000 // 3 minutes per batch
                        });

                        if (batchResponse.data.success) {
                            allTranslated = { ...allTranslated, ...batchResponse.data.translated };
                            totalTranslated += batchResponse.data.translatedCount || 0;
                            totalErrors += batchResponse.data.errors || 0;
                            console.log(`[SnippetFileTranslation] Batch ${batchNum} success: ${batchResponse.data.translatedCount} translated`);
                        }

                        // Small delay between batches
                        if (i < batches.length - 1) {
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                    } catch (error) {
                        console.error(`[SnippetFileTranslation] Batch ${batchNum} failed:`, error);
                        totalErrors += Object.keys(batches[i]).length;
                    }
                }

                // Final write step
                // In overwrite mode, we want the backend to perform the smart replacement using the 'allTranslated' set we just built.
                // In non-overwrite mode, we just want to merge.
                // The backend endpoint `writeSnippetFile` is too simple (just writes what we give it).
                // So we should reuse `translateSnippetFile` endpoint logic? No, that one translates.
                // We need to implement the smart logic here before writing, OR call a new endpoint.
                // BUT: We already translated everything. We just need to save.

                // Let's replicate the Smart Replace logic here for the final write payload if overwrite is on
                let finalSnippetsToWrite = allTranslated;

                if (this.overwriteExisting && Object.keys(existingTargetSnippets).length > 0) {
                    console.log('[SnippetFileTranslation] Smart Replace: Merging with existing for fallback...');
                    const smartMerged = {};
                    // Iterate over SOURCE keys to ensure we clean up obsolete ones
                    // snippetKeys = all keys from source file
                    snippetKeys.forEach(key => {
                        if (Object.prototype.hasOwnProperty.call(allTranslated, key)) {
                            smartMerged[key] = allTranslated[key];
                        } else if (Object.prototype.hasOwnProperty.call(existingTargetSnippets, key)) {
                            // Fallback to existing if translation missing/failed
                            smartMerged[key] = existingTargetSnippets[key];
                        }
                    });
                    finalSnippetsToWrite = smartMerged;
                } else if (!this.overwriteExisting) {
                    // Merge new with existing
                    finalSnippetsToWrite = { ...existingTargetSnippets, ...allTranslated };
                }

                console.log(`[SnippetFileTranslation] Writing ${Object.keys(finalSnippetsToWrite).length} snippets to ${targetFilePath}`);

                await this.httpClient.post('/_action/vivatura-translator/write-snippet-file', {
                    filePath: targetFilePath,
                    snippets: finalSnippetsToWrite
                }, { headers: this.authHeaders });

                const duration = ((Date.now() - startTime) / 1000).toFixed(1);
                console.log(`[SnippetFileTranslation] Batch translation completed in ${duration}s`, {
                    totalSnippets,
                    translated: totalTranslated,
                    skipped: skippedCount,
                    errors: totalErrors
                });

                this.isTranslating = false;
                this.translationProgress = 100;

                this.createNotificationSuccess({
                    title: this.$tc('vivatura-translator.notification.successTitle'),
                    message: `✓ ${file.filename}: ${totalTranslated} translated, ${skippedCount} skipped in ${duration}s (${totalErrors} errors)`
                });

            } catch (error) {
                const duration = ((Date.now() - startTime) / 1000).toFixed(1);
                console.error(`[SnippetFileTranslation] Batch translation failed after ${duration}s:`, error);

                this.isTranslating = false;
                this.createNotificationError({
                    title: this.$tc('vivatura-translator.notification.errorTitle'),
                    message: `✗ ${file.filename}: ${error.response?.data?.error || error.message}`
                });
            }
        },

        async translateAllSnippetFiles() {
            const allFiles = this.filteredSnippetFiles.reduce((acc, source) => {
                return acc.concat(source.files.map(f => ({ ...f, source: source.name })));
            }, []);
            const totalFiles = allFiles.length;

            console.log('[SnippetFileTranslation] Starting batch translation for ALL files:', {
                totalFiles,
                sourceLanguage: this.snippetFilesSourceLang,
                targetLanguage: this.snippetFilesTargetLang,
                overwriteExisting: this.overwriteExisting
            });

            if (!confirm(`Translate all ${totalFiles} snippet files from ${this.snippetFilesSourceLang} to ${this.snippetFilesTargetLang}?\n\nThis may take several minutes.`)) {
                return;
            }

            this.isTranslating = true;
            this.translationProgress = 0;

            let translatedCount = 0;
            let failedCount = 0;
            const startTime = Date.now();

            for (let i = 0; i < allFiles.length; i++) {
                const file = allFiles[i];
                const progress = Math.round(((i + 1) / totalFiles) * 100);

                console.log(`[SnippetFileTranslation] Progress: ${i + 1}/${totalFiles} (${progress}%) - ${file.filename}`);

                this.translationProgress = progress;

                try {
                    const fileStartTime = Date.now();

                    // Check if we should even process this file based on overwrite setting?
                    // The backend handles the overwrite logic now, but for large files we might want to know.
                    // However, translateAllSnippetFiles calls the endpoint which we updated to handle overwrite.
                    // So we just pass the flag.

                    const response = await this.httpClient.post('/_action/vivatura-translator/translate-snippet-file', {
                        sourceFilePath: file.fullPath,
                        targetLanguage: this.snippetFilesTargetLang,
                        overwriteExisting: this.overwriteExisting
                    }, {
                        headers: this.authHeaders,
                        timeout: 300000 // 5 minutes per file
                    });

                    const fileDuration = ((Date.now() - fileStartTime) / 1000).toFixed(1);

                    if (response.data.success) {
                        translatedCount++;
                        console.log(`[SnippetFileTranslation] ✓ ${file.filename} (${fileDuration}s):`, response.data);
                    } else {
                         // Even if success is false (e.g. backend error caught and returned as json), log it
                         console.warn(`[SnippetFileTranslation] ! ${file.filename} returned success=false`, response.data);
                         if (response.data.error) failedCount++;
                    }

                    // Small delay between files to avoid overwhelming the server
                    if (i < allFiles.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }
                } catch (error) {
                    failedCount++;
                    console.error(`[SnippetFileTranslation] ✗ ${file.filename}:`, error);

                    // Don't stop on error, continue with next file
                }
            }

            const totalDuration = ((Date.now() - startTime) / 1000).toFixed(1);
            console.log('[SnippetFileTranslation] Batch translation for ALL files completed:', {
                totalFiles,
                translated: translatedCount,
                failed: failedCount,
                duration: totalDuration + 's'
            });

            this.isTranslating = false;
            this.translationProgress = 100;

            if (failedCount === 0) {
                this.createNotificationSuccess({
                    title: this.$tc('vivatura-translator.notification.successTitle'),
                    message: `✓ Successfully processed all ${translatedCount} files in ${totalDuration}s`
                });
            } else {
                this.createNotificationWarning({
                    title: this.$tc('vivatura-translator.notification.warningTitle'),
                    message: `⚠ Processed ${translatedCount} files, ${failedCount} failed (${totalDuration}s)`
                });
            }
        },

        async loadStatus() {
            const response = await this.httpClient.get('/_action/vivatura-translator/translation-status', { headers: this.authHeaders });
            this.status = response.data;
        },

        async loadLanguages() {
            const response = await this.httpClient.get('/_action/vivatura-translator/languages', { headers: this.authHeaders });
            this.availableLanguages = response.data.languages || [];

            // Set default values based on available languages
            if (this.availableLanguages.length > 0) {
                // Try to find German as default source, otherwise use first language
                const germanLang = this.availableLanguages.find(l => l.locale === 'de-DE');
                const defaultSource = germanLang ? germanLang.locale : this.availableLanguages[0].locale;

                // Try to find a different language as default target
                const otherLang = this.availableLanguages.find(l => l.locale !== defaultSource);
                const defaultTarget = otherLang ? otherLang.locale : defaultSource;

                // Set defaults if not already set
                if (!this.bulkSourceIso) {
                    this.bulkSourceIso = defaultSource;
                }
                if (!this.bulkTargetIso) {
                    this.bulkTargetIso = defaultTarget;
                }
                if (!this.snippetFilesSourceLang) {
                    this.snippetFilesSourceLang = defaultSource;
                }
                if (!this.snippetFilesTargetLang) {
                    this.snippetFilesTargetLang = defaultTarget;
                }
            }
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
                this.productPage = Number(response.data.page) || this.productPage;
                this.productLimit = Number(response.data.limit) || this.productLimit;

                const maxPage = Math.max(1, Math.ceil(this.productTotal / this.productLimit));
                if (this.productPage > maxPage) {
                    this.productPage = maxPage;
                    await this.loadProducts();
                    return;
                }
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
            if (typeof page === 'object' && page !== null) {
                this.productPage = Number(page.page) || 1;
                if (page.limit) {
                    this.productLimit = Number(page.limit) || this.productLimit;
                }
            } else {
                this.productPage = Number(page) || 1;
            }

            this.selectedProducts = [];
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
                    targetLanguageIds: this.selectedLanguages,
                    overwriteExisting: this.overwriteExisting
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
                this.cmsPage = Number(response.data.page) || this.cmsPage;
                this.cmsLimit = Number(response.data.limit) || this.cmsLimit;

                const maxPage = Math.max(1, Math.ceil(this.cmsTotal / this.cmsLimit));
                if (this.cmsPage > maxPage) {
                    this.cmsPage = maxPage;
                    await this.loadCmsPages();
                    return;
                }
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
            if (typeof page === 'object' && page !== null) {
                this.cmsPage = Number(page.page) || 1;
                if (page.limit) {
                    this.cmsLimit = Number(page.limit) || this.cmsLimit;
                }
            } else {
                this.cmsPage = Number(page) || 1;
            }

            this.selectedCmsPages = [];
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
                    targetLanguageIds: this.selectedLanguages,
                    overwriteExisting: this.overwriteExisting
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
            this.snippetSearch = '';
            this.snippetPage = 1;
            this.selectedSnippets = [];

            if (this.sourceSnippetSet) {
                await this.loadSnippets();
            } else {
                this.snippets = [];
                this.snippetTotal = 0;
            }
        },

        onTargetSnippetSetChange(value) {
            this.targetSnippetSet = value;
        },

        async loadSnippets() {
            if (!this.sourceSnippetSet) {
                this.snippets = [];
                this.snippetTotal = 0;
                return;
            }

            this.isLoadingSnippets = true;
            const requestId = ++this.snippetLoadRequestId;
            const requestedSetId = this.sourceSnippetSet;
            const requestedPage = this.snippetPage;
            const requestedLimit = this.snippetLimit;
            const requestedSearch = this.snippetSearch || '';
            try {
                const params = new URLSearchParams({
                    setId: requestedSetId,
                    page: requestedPage,
                    limit: requestedLimit,
                    search: requestedSearch
                });
                const response = await this.httpClient.get(`/_action/vivatura-translator/snippets?${params}`, { headers: this.authHeaders });

                // Ignore stale responses from previous requests.
                if (requestId !== this.snippetLoadRequestId || requestedSetId !== this.sourceSnippetSet) {
                    return;
                }

                this.snippets = response.data.snippets || [];
                this.snippetTotal = response.data.total || 0;
                this.snippetPage = Number(response.data.page) || requestedPage;
                this.snippetLimit = Number(response.data.limit) || requestedLimit;

                const maxPage = Math.max(1, Math.ceil(this.snippetTotal / this.snippetLimit));
                if (this.snippetPage > maxPage) {
                    this.snippetPage = maxPage;
                    await this.loadSnippets();
                    return;
                }
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
            this.selectedSnippets = [];
            this.loadSnippets();
        },

        onSnippetPageChange(page) {
            if (typeof page === 'object' && page !== null) {
                this.snippetPage = Number(page.page) || 1;
                if (page.limit) {
                    this.snippetLimit = Number(page.limit) || this.snippetLimit;
                }
            } else {
                this.snippetPage = Number(page) || 1;
            }

            this.selectedSnippets = [];
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
                    targetSetId: this.targetSnippetSet,
                    overwriteExisting: this.overwriteExisting
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
                    targetIso: this.bulkTargetIso,
                    overwriteExisting: this.overwriteExisting
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
