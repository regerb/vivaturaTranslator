// VivaturaTranslator Administration Entry Point
import './module/vivatura-translator';
import './extension/sw-product-detail';
import './extension/sw-cms-detail';
import './component/vivatura-translator-button';

// Import and register snippets globally
import deDE from './module/vivatura-translator/snippet/de-DE.json';
import enGB from './module/vivatura-translator/snippet/en-GB.json';

// Register snippets globally for Shopware 6.6
const { Locale } = Shopware;

if (Locale && Locale.extend) {
    Locale.extend('de-DE', deDE);
    Locale.extend('en-GB', enGB);
}
