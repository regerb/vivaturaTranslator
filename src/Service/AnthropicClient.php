<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * Translate text using Claude API
     *
     * @param string $text The text to translate
     * @param string $targetLanguage The target language code (e.g., 'en-GB', 'fr-FR')
     * @param string $systemPrompt Custom system prompt for translation context
     * @return string The translated text
     * @throws \RuntimeException If API call fails
     */
    public function translate(string $text, string $targetLanguage, string $systemPrompt): string
    {
        $apiKey = $this->systemConfigService->get('VivaturaTranslator.config.anthropicApiKey');
        $model = $this->systemConfigService->get('VivaturaTranslator.config.claudeModel') ?? 'claude-3-haiku-20240307';

        if (empty($apiKey)) {
            throw new \RuntimeException('Anthropic API key not configured. Please set it in plugin settings.');
        }

        // Convert ISO code to full language name for better prompt understanding
        $languageName = $this->getLanguageName($targetLanguage);
        $userPrompt = $this->buildUserPrompt($text, $languageName);
        $maxTokens = $this->getMaxTokens($model);

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt
                ]
            ]
        ];

        $response = $this->makeRequest($payload, $apiKey);

        return $this->extractTranslation($response);
    }

    /**
     * Translate multiple texts in a single API call for efficiency
     *
     * @param array<string, string> $texts Associative array of field => text
     * @param string $targetLanguage Target language code
     * @param string $systemPrompt System prompt
     * @return array<string, string> Associative array of field => translated text
     */
    public function translateBatch(array $texts, string $targetLanguage, string $systemPrompt): array
    {
        $apiKey = $this->systemConfigService->get('VivaturaTranslator.config.anthropicApiKey');
        $model = $this->systemConfigService->get('VivaturaTranslator.config.claudeModel') ?? 'claude-3-haiku-20240307';

        if (empty($apiKey)) {
            throw new \RuntimeException('Anthropic API key not configured. Please set it in plugin settings.');
        }

        // Build batch request with JSON structure for easy parsing
        $jsonInput = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Convert ISO code to full language name
        $languageName = $this->getLanguageName($targetLanguage);

        $userPrompt = <<<PROMPT
Translate the following JSON object values to {$languageName} ({$targetLanguage}).
Keep the JSON keys unchanged, only translate the values.
Return ONLY the translated JSON object, no additional text.

IMPORTANT RULES FOR HTML AND TWIG:
- The values may contain HTML tags (like <div>, <p>, <span>) and Twig syntax (like {{ variable }}, {% block %}).
- ONLY translate the human-readable text content inside the HTML tags.
- Do NOT translate HTML tag names, attributes (class, id, style, etc.), or values within attributes.
- Do NOT translate any Twig variable names or block definitions.
- KEEP all HTML structure and Twig syntax exactly as it is.

IMPORTANT RULES FOR PLURALIZATION:
- If a source text contains plural forms separated by a pipe (|), you MUST adapt the number of forms to the target language rules.
- Polish (pl) requires 3 forms (one | few | many/other). Example: "1 plik | %count% pliki | %count% plików".
- German (de) and English (en) use 2 forms (one | other).
- Ensure you generate the correct number of pipe-separated sections for the target language.

```json
{$jsonInput}
```
PROMPT;

        $maxTokens = $this->getMaxTokens($model);

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt
                ]
            ]
        ];

        $response = $this->makeRequest($payload, $apiKey);
        $translatedJson = $this->extractTranslation($response);

        // Clean up the response - remove markdown code fences, triple quotes, and extra whitespace
        $translatedJson = trim($translatedJson);

        // Remove markdown code fences (```json and ```)
        $translatedJson = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $translatedJson);

        // Remove triple quotes that Claude sometimes adds
        $translatedJson = preg_replace('/^"""\s*|\s*"""$/s', '', $translatedJson);

        // Trim again after cleanup
        $translatedJson = trim($translatedJson);

        // Log what we're trying to parse (first 500 chars for debugging)
        $this->logger->debug('AnthropicClient: Attempting to parse JSON', [
            'preview' => substr($translatedJson, 0, 500),
            'length' => strlen($translatedJson)
        ]);

        $translated = json_decode($translatedJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to parse Claude translation response as JSON', [
                'response' => $translatedJson,
                'error' => json_last_error_msg(),
                'firstChars' => substr($translatedJson, 0, 100),
                'lastChars' => substr($translatedJson, -100)
            ]);
            throw new \RuntimeException('Failed to parse translation response: ' . json_last_error_msg());
        }

        return $translated;
    }

    public function getAvailableModels(): array
    {
        $apiKey = $this->systemConfigService->get('VivaturaTranslator.config.anthropicApiKey');
        if (empty($apiKey)) {
            throw new \RuntimeException('Anthropic API key not configured.');
        }

        $ch = curl_init('https://api.anthropic.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Failed to fetch models: ' . $error);
        }

        $data = json_decode($response, true);
        return $data['data'] ?? [];
    }

    private function getMaxTokens(string $model): int
    {
        // Claude 3.5 and 4.5 models support 8192 tokens
        if (str_contains($model, 'claude-3-5') || str_contains($model, 'claude-4') || str_contains($model, '4-5')) {
            return 8192;
        }

        // Older models (Claude 3 Opus, Sonnet, Haiku) default to 4096
        return 4096;
    }

    private function buildUserPrompt(string $text, string $targetLanguage): string
    {
        return <<<PROMPT
Translate the following text to {$targetLanguage}.
Return ONLY the translated text, no explanations or additional content.

IMPORTANT RULES FOR HTML AND TWIG:
- The text may contain HTML tags (like <div>, <p>, <span>) and Twig syntax (like {{ variable }}, {% block %}).
- ONLY translate the human-readable text content inside the HTML tags.
- Do NOT translate HTML tag names, attributes (class, id, style, etc.), or values within attributes.
- Do NOT translate any Twig variable names or block definitions.
- KEEP all HTML structure and Twig syntax exactly as it is.

Text to translate:
{$text}
PROMPT;
    }

    private function makeRequest(array $payload, string $apiKey): array
    {
        $ch = curl_init(self::API_URL);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error('Anthropic API curl error', ['error' => $error]);
            throw new \RuntimeException('Failed to connect to Anthropic API: ' . $error);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMessage = $data['error']['message'] ?? 'Unknown error';
            $this->logger->error('Anthropic API error', [
                'status' => $httpCode,
                'error' => $errorMessage,
                'response' => $response
            ]);
            throw new \RuntimeException('Anthropic API error: ' . $errorMessage);
        }

        return $data;
    }

    private function extractTranslation(array $response): string
    {
        if (!isset($response['content'][0]['text'])) {
            throw new \RuntimeException('Invalid response structure from Anthropic API');
        }

        return trim($response['content'][0]['text']);
    }

    private function getLanguageName(string $isoCode): string
    {
        $commonLanguages = [
            'de-DE' => 'German',
            'en-GB' => 'English (UK)',
            'en-US' => 'English (US)',
            'fr-FR' => 'French',
            'es-ES' => 'Spanish',
            'it-IT' => 'Italian',
            'nl-NL' => 'Dutch',
            'pl-PL' => 'Polish',
            'pt-PT' => 'Portuguese',
            'ru-RU' => 'Russian',
            'zh-CN' => 'Chinese (Simplified)',
            'ja-JP' => 'Japanese',
            'tr-TR' => 'Turkish',
            'sv-SE' => 'Swedish',
            'da-DK' => 'Danish',
            'no-NO' => 'Norwegian',
            'fi-FI' => 'Finnish',
            'cs-CZ' => 'Czech',
            'hu-HU' => 'Hungarian',
            'ro-RO' => 'Romanian'
        ];

        return $commonLanguages[$isoCode] ?? $isoCode;
    }
}
