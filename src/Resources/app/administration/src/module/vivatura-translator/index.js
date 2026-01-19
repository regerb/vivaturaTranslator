import './page/vivatura-translator-settings';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('vivatura-translator', {
    type: 'plugin',
    name: 'VivaturaTranslator',
    title: 'vivatura-translator.general.mainMenuItemGeneral',
    description: 'vivatura-translator.general.descriptionTextModule',
    color: '#ff68b4',
    icon: 'regular-language',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routes: {
        settings: {
            component: 'vivatura-translator-settings',
            path: 'settings',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },

    settingsItem: {
        name: 'vivatura-translator-settings',
        to: 'vivatura.translator.settings',
        label: 'vivatura-translator.general.mainMenuItemGeneral',
        group: 'plugins',
        icon: 'regular-language'
    }
});

