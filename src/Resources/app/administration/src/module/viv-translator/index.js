import './page/viv-translator-settings';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('viv-translator', {
    type: 'plugin',
    name: 'VivTranslator',
    title: 'viv-translator.general.mainMenuItemGeneral',
    description: 'viv-translator.general.descriptionTextModule',
    color: '#ff68b4',
    icon: 'regular-language',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routes: {
        settings: {
            component: 'viv-translator-settings',
            path: 'settings',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },

    settingsItem: [
        {
            name: 'viv-translator-settings',
            to: 'viv.translator.settings',
            label: 'viv-translator.general.mainMenuItemGeneral',
            group: 'plugins',
            icon: 'regular-language'
        }
    ]
});

