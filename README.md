# vivaturaTranslator - Shopware 6 AI Translation Plugin

KI-gestützte Übersetzungen für Produkte, CMS-Seiten und Snippets mit Anthropic Claude.

## Features

- 🤖 **Anthropic Claude AI** für hochwertige Übersetzungen
- 🌍 **Dynamische Sprachunterstützung** - alle in Shopware konfigurierten Sprachen
- ⚙️ **Anpassbare System Prompts** - global und pro Sprache für kulturellen Kontext
- 🛒 **Produkt-Übersetzung** - Name, Beschreibung, Meta-Tags, Custom Fields
- 📄 **CMS-Seiten Übersetzung** - alle Textblöcke und Headlines

## Installation

### Via Composer (empfohlen)

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/regerb/vivaturaTranslator.git"
        }
    ]
}
```

```bash
composer require vivatura/vivatura-translator:^2.4
bin/console plugin:refresh
bin/console plugin:install VivaturaTranslator --activate
```

### Manuell

1. Plugin nach `custom/plugins/VivaturaTranslator/` kopieren
2. `bin/console plugin:refresh`
3. `bin/console plugin:install VivaturaTranslator --activate`

## Konfiguration

1. **Erweiterungen > Meine Erweiterungen > VivaturaTranslator > Konfigurieren**
2. Anthropic API Key eintragen (von console.anthropic.com)
3. Claude Modell wählen (Haiku/Sonnet/Opus)
4. Optional: Globalen System Prompt anpassen

### Sprachspezifische Prompts

Unter **Einstellungen > Vivatura Translator** können für jede Sprache individuelle Übersetzungsanweisungen hinterlegt werden.

## Verwendung

In der Produkt- oder CMS-Seiten-Bearbeitung den Button **"Mit KI übersetzen"** klicken, Zielsprachen wählen und übersetzen.

## Anforderungen

- Shopware 6.6+
- PHP 8.1+
- Anthropic API Key

## Releases (automatisch)

Releases werden über GitHub Actions mit `release-please` erstellt.

- Push auf `main` erzeugt/aktualisiert automatisch einen Release-PR
- Nach Merge des Release-PR wird automatisch ein Git-Tag (`vX.Y.Z`) und GitHub Release erstellt
- Basis sind Conventional Commits:
  - `fix:` -> Patch Release
  - `feat:` -> Minor Release
  - `feat!:` oder `BREAKING CHANGE:` -> Major Release

## Lizenz

Proprietary - Vivatura
