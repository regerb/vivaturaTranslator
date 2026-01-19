# Deployment-Anleitung für VivaturaTranslator

## Vorgenommene Änderungen

### 1. Snippet-Registrierung (main.js)
- **Problem:** Snippets waren nur im Modul registriert, nicht global verfügbar
- **Lösung:** Snippets werden jetzt auch in der main.js global registriert, damit die `vivatura-translator-button` Komponente darauf zugreifen kann

### 2. Modul-Registrierung (module/vivatura-translator/index.js)
- **Problem:** `settingsItem` war als Array definiert, sollte aber ein Objekt sein
- **Lösung:** Geändert von Array zu Objekt

### 3. Component Extensions
- **Product Detail (extension/sw-product-detail/index.js):**
  - Computed Property `product` hinzugefügt für Zugriff auf Produktdaten

- **CMS Detail (extension/sw-cms-detail/index.js):**
  - Computed Property `page` hinzugefügt für Zugriff auf CMS-Seitendaten

### 4. Template-Blöcke
- **Product Detail Template:** Verwendet `sw_product_detail_content_language_info` Block
- **CMS Detail Template:** Verwendet `sw_cms_detail_content_language_switch` Block

## Nach dem Deployment erforderliche Schritte

### 1. Plugin installieren/aktualisieren
```bash
cd /pfad/zu/shopware
bin/console plugin:refresh
bin/console plugin:install --activate VivaturaTranslator
# oder bei Update:
bin/console plugin:update VivaturaTranslator
```

### 2. Administration neu bauen
```bash
bin/console bundle:dump
bin/build-administration.sh
# oder auf Windows:
.\bin\build-administration.bat
```

### 3. Cache leeren
```bash
bin/console cache:clear
```

### 4. API-Schlüssel konfigurieren
- Im Shopware Admin zu Einstellungen → Erweiterungen → Vivatura Translator gehen
- Anthropic API-Schlüssel eintragen
- Claude-Modell auswählen (Standard: Haiku)
- Optional: System Prompt anpassen

## Testen

### 1. Produkt-Übersetzung testen
1. Im Admin zu Kataloge → Produkte gehen
2. Ein Produkt öffnen
3. Button "Mit KI übersetzen" sollte sichtbar sein
4. Button klicken und Zielsprachen auswählen
5. Übersetzung starten

### 2. CMS-Seiten-Übersetzung testen
1. Im Admin zu Inhalte → Erlebnisse gehen
2. Eine Erlebnisseite öffnen
3. Button "Mit KI übersetzen" sollte sichtbar sein
4. Funktionsweise wie bei Produkten

## Troubleshooting

### Buttons werden nicht angezeigt

**Mögliche Ursache 1: Administration nicht gebaut**
- Lösung: Schritte 2 und 3 von oben durchführen

**Mögliche Ursache 2: Template-Block existiert nicht in Shopware-Version**
- Prüfen: Browser-Konsole auf Fehler überprüfen
- Lösung: Template-Block-Namen anpassen

Um die richtigen Block-Namen zu finden:
1. In Shopware-Core nachschauen: `vendor/shopware/administration/Resources/app/administration/src/module/sw-product/page/sw-product-detail/sw-product-detail.html.twig`
2. Verfügbare Blocks identifizieren
3. Template-Datei entsprechend anpassen

**Mögliche Ursache 3: JavaScript-Fehler**
- Browser-Konsole öffnen (F12)
- Auf Fehler prüfen
- Häufig: Fehlende Dependencies oder falsche Importpfade

### Snippets werden nicht übersetzt

**Mögliche Ursache:** Locale-Factory nicht richtig initialisiert
- Prüfen: `src/Resources/app/administration/src/main.js`
- Sicherstellen dass `Application.addInitializerDecorator` korrekt ist

### API-Aufrufe schlagen fehl

1. Netzwerk-Tab im Browser öffnen
2. API-Anfragen beobachten
3. Fehler analysieren:
   - 401/403: API-Schlüssel falsch oder nicht konfiguriert
   - 404: Route nicht registriert (bin/console debug:router prüfen)
   - 500: Server-Fehler (Shopware-Logs prüfen)

## Alternative Template-Blöcke (falls aktuelle nicht funktionieren)

### Für Produkte:
```twig
{% block sw_product_detail_base %}
{% block sw_product_detail_content %}
{% block sw_product_detail_content_tabs %}
```

### Für CMS:
```twig
{% block sw_cms_detail %}
{% block sw_cms_detail_content %}
{% block sw_cms_detail_sidebar %}
```

## Logs

Shopware-Logs befinden sich in:
- `var/log/shopware.log`
- `var/log/dev.log` (Development-Umgebung)

Bei Problemen zuerst die Logs prüfen.
