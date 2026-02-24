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

        $technicalRules = $this->getTechnicalRulesPrompt();

        $userPrompt = <<<PROMPT
Translate the following JSON object values to {$languageName} ({$targetLanguage}).
Keep the JSON keys unchanged, only translate the values.
Return ONLY the translated JSON object, no additional text.
The response must be valid RFC8259 JSON.
- Escape all double quotes inside values as \\"
- Do not include literal tab/newline characters inside values; use \\t and \\n escapes instead.

{$technicalRules}

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

        return $this->decodeBatchTranslationJson($translatedJson);
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
        $technicalRules = $this->getTechnicalRulesPrompt();

        return <<<PROMPT
Translate the following text to {$targetLanguage}.
Return ONLY the translated text, no explanations or additional content.

{$technicalRules}

Text to translate:
{$text}
PROMPT;
    }

    private function getTechnicalRulesPrompt(): string
    {
        $prompt = $this->systemConfigService->get('VivaturaTranslator.config.technicalRulesPrompt');

        if (!empty($prompt)) {
            return $prompt;
        }

        // Fallback default rules
        return <<<RULES
IMPORTANT RULES FOR HTML AND TWIG:
- The values may contain HTML tags (like <div>, <p>, <span>) and Twig syntax (like {{ variable }}, {% block %}).
- YOU MUST TRANSLATE ALL human-readable text content inside ALL HTML tags. Do not skip any text.
- Example: "<p>Hello</p><h1>World</h1>" -> "<p>Hallo</p><h1>Welt</h1>" (Translate BOTH parts).
- Do NOT translate HTML tag names, attributes (class, id, style, etc.), or values within attributes.
- Do NOT translate any Twig variable names or block definitions.
- KEEP all HTML structure and Twig syntax exactly as it is.

IMPORTANT RULES FOR PLURALIZATION:
- If a source text contains plural forms separated by a pipe (|), you MUST adapt the number of forms to the target language rules.
- Polish (pl) requires 3 forms (one | few | many/other). Example: "1 plik | %count% pliki | %count% plików".
- German (de) and English (en) use 2 forms (one | other).
- Ensure you generate the correct number of pipe-separated sections for the target language.
RULES;
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

    /**
     * Decode translated JSON with recovery for common LLM formatting issues.
     *
     * @return array<string, string>
     */
    private function decodeBatchTranslationJson(string $translatedJson): array
    {
        $lastError = 'Unknown parsing error';

        try {
            return $this->decodeJsonAssoc($translatedJson);
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            $this->logger->warning('AnthropicClient: Raw JSON parse failed, trying sanitized parse', [
                'error' => $lastError
            ]);
        }

        $sanitizedJson = $this->sanitizeJsonForDecode($translatedJson);

        try {
            return $this->decodeJsonAssoc($sanitizedJson);
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
        }

        $repairedJson = $this->repairLikelyUnescapedQuotesInJsonValues($sanitizedJson);

        try {
            return $this->decodeJsonAssoc($repairedJson);
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
        }

        $lenientMap = $this->decodeStringMapLenient($repairedJson);
        if (!empty($lenientMap)) {
            $this->logger->warning('AnthropicClient: Parsed translation response with lenient key/value fallback', [
                'pairCount' => count($lenientMap)
            ]);

            return $lenientMap;
        }

        $this->logger->error('Failed to parse Claude translation response as JSON', [
            'response' => $translatedJson,
            'sanitizedResponse' => $sanitizedJson,
            'repairedResponse' => $repairedJson,
            'error' => $lastError,
            'firstChars' => substr($translatedJson, 0, 100),
            'lastChars' => substr($translatedJson, -100)
        ]);

        throw new \RuntimeException('Failed to parse translation response: ' . $lastError);
    }

    /**
     * @return array<string, string>
     */
    private function decodeJsonAssoc(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Decoded response is not a JSON object.');
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }

    private function sanitizeJsonForDecode(string $json): string
    {
        $clean = trim($json);
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $clean) ?? $clean;

        $extracted = $this->extractFirstJsonObject($clean);
        if ($extracted !== null) {
            $clean = $extracted;
        }

        // Remove invalid UTF-8 bytes if present.
        if (!preg_match('//u', $clean) && function_exists('iconv')) {
            $converted = iconv('UTF-8', 'UTF-8//IGNORE', $clean);
            if ($converted !== false) {
                $clean = $converted;
            }
        }

        // Remove illegal control characters (but keep CR/LF/TAB for dedicated escaping).
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean) ?? $clean;
        $clean = $this->escapeControlCharsInsideJsonStrings($clean);

        // Remove trailing commas before object/array endings.
        $clean = preg_replace('/,\s*([}\]])/', '$1', $clean) ?? $clean;

        return trim($clean);
    }

    private function extractFirstJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $inString = false;
        $escaped = false;
        $depth = 0;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function escapeControlCharsInsideJsonStrings(string $json): string
    {
        $length = strlen($json);
        $result = '';
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                if ($escaped) {
                    $result .= $char;
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $result .= $char;
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                    $result .= $char;
                    continue;
                }

                if ($char === "\n") {
                    $result .= '\\n';
                    continue;
                }

                if ($char === "\r") {
                    $result .= '\\r';
                    continue;
                }

                if ($char === "\t") {
                    $result .= '\\t';
                    continue;
                }

                $result .= $char;
                continue;
            }

            if ($char === '"') {
                $inString = true;
            }

            $result .= $char;
        }

        return $result;
    }

    /**
     * Repair likely unescaped double quotes inside JSON string values.
     * A quote inside a value is treated as content when it is not followed by ',' or '}'.
     */
    private function repairLikelyUnescapedQuotesInJsonValues(string $json): string
    {
        $length = strlen($json);
        $result = '';
        $inString = false;
        $escaped = false;
        $stringRole = null; // key|value|null
        $lastSignificant = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                if ($escaped) {
                    $result .= $char;
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $result .= $char;
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    if ($stringRole === 'value' && !$this->isQuoteLikelyStringTerminator($json, $i + 1)) {
                        $result .= '\\"';
                        continue;
                    }

                    $inString = false;
                    $stringRole = null;
                    $result .= $char;
                    continue;
                }

                $result .= $char;
                continue;
            }

            if ($char === '"') {
                $inString = true;
                $stringRole = $lastSignificant === ':' ? 'value' : 'key';
                $result .= $char;
                continue;
            }

            if (!ctype_space($char)) {
                $lastSignificant = $char;
            }

            $result .= $char;
        }

        return $result;
    }

    private function isQuoteLikelyStringTerminator(string $json, int $index): bool
    {
        $length = strlen($json);
        for ($i = $index; $i < $length; $i++) {
            $char = $json[$i];

            if (ctype_space($char)) {
                continue;
            }

            return $char === ',' || $char === '}';
        }

        return true;
    }

    /**
     * Best-effort parser for object-style `"key":"value"` pairs.
     *
     * @return array<string, string>
     */
    private function decodeStringMapLenient(string $json): array
    {
        $pairs = [];
        $matchCount = preg_match_all('/"((?:\\\\.|[^"\\\\])*)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/su', $json, $matches, PREG_SET_ORDER);

        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $key = $this->decodeJsonStringToken($match[1]);
            if ($key === '') {
                continue;
            }

            $pairs[$key] = $this->decodeJsonStringToken($match[2]);
        }

        return $pairs;
    }

    private function decodeJsonStringToken(string $token): string
    {
        $decoded = json_decode('"' . $token . '"', true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($decoded)) {
            return $decoded;
        }

        return stripcslashes($token);
    }
}
