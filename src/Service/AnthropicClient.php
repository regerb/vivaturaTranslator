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

        $userPrompt = $this->buildUserPrompt($text, $targetLanguage);
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
        $jsonInput = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $userPrompt = <<<PROMPT
Translate the following JSON object values from German to {$targetLanguage}.
Keep the JSON keys unchanged, only translate the values.
Return ONLY the translated JSON object, no additional text.

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

        // Parse the JSON response
        $translatedJson = preg_replace('/^```json\s*|\s*```$/s', '', trim($translatedJson));
        $translated = json_decode($translatedJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to parse Claude translation response as JSON', [
                'response' => $translatedJson,
                'error' => json_last_error_msg()
            ]);
            throw new \RuntimeException('Failed to parse translation response');
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
        // Claude 3.5 models support 8192 tokens
        if (str_contains($model, 'claude-3-5')) {
            return 8192;
        }

        // Older models (Claude 3 Opus, Sonnet, Haiku) default to 4096
        return 4096;
    }

    private function buildUserPrompt(string $text, string $targetLanguage): string
    {
        return <<<PROMPT
Translate the following text from German to {$targetLanguage}. 
Return ONLY the translated text, no explanations or additional content.

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
}
