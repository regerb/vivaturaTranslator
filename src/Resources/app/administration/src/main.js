// VivaturaTranslator Administration Entry Point
import './module/vivatura-translator';
import './extension/sw-product-detail';
import './extension/sw-cms-detail';
import './component/vivatura-translator-button';

// Import snippets globally so they're available to all components
import deDE from './module/vivatura-translator/snippet/de-DE.json';
import enGB from './module/vivatura-translator/snippet/en-GB.json';

// Register snippets globally
const { Application } = Shopware;
Application.addInitializerDecorator('locale', (localeFactory) => {
    localeFactory.extend('de-DE', deDE);
    localeFactory.extend('en-GB', enGB);
    return localeFactory;
});
