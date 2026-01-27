<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Service;

use Symfony\Component\Finder\Finder;

class SnippetFileScanner
{
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    /**
     * Find all snippet JSON files in the project
     *
     * @return array Array of snippet file info grouped by source
     */
    public function findSnippetFiles(): array
    {
        $finder = new Finder();
        $finder->files()
            ->in([
                $this->projectDir . '/vendor',
                $this->projectDir . '/custom'
            ])
            ->path('/snippet/')
            ->name('*.json')
            ->ignoreUnreadableDirs();

        $snippetFiles = [];

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $fullPath = $file->getRealPath();

            // Extract info from path
            $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);

            // Determine source (plugin/app name)
            $source = $this->extractSource($fullPath);

            // Extract language from filename (e.g., "storefront.de-DE.json" -> "de-DE")
            $filename = $file->getFilename();
            preg_match('/\.([a-z]{2}-[A-Z]{2})\.json$/', $filename, $matches);
            $language = $matches[1] ?? 'unknown';

            // Parse JSON to count snippets
            $content = json_decode(file_get_contents($fullPath), true);
            $snippetCount = $this->countSnippets($content);

            if (!isset($snippetFiles[$source])) {
                $snippetFiles[$source] = [
                    'name' => $source,
                    'files' => []
                ];
            }

            $snippetFiles[$source]['files'][] = [
                'path' => str_replace($this->projectDir . DIRECTORY_SEPARATOR, '', $fullPath),
                'fullPath' => $fullPath,
                'filename' => $filename,
                'language' => $language,
                'snippetCount' => $snippetCount,
                'size' => $file->getSize()
            ];
        }

        // Sort files within each source by language
        foreach ($snippetFiles as &$sourceData) {
            usort($sourceData['files'], function($a, $b) {
                return strcmp($a['language'], $b['language']);
            });
        }

        return array_values($snippetFiles);
    }

    /**
     * Find snippet files for a specific language
     */
    public function findByLanguage(string $iso): array
    {
        $allFiles = $this->findSnippetFiles();
        $filtered = [];

        foreach ($allFiles as $source) {
            $matchingFiles = array_filter($source['files'], function($file) use ($iso) {
                return $file['language'] === $iso;
            });

            if (!empty($matchingFiles)) {
                $filtered[] = [
                    'name' => $source['name'],
                    'files' => array_values($matchingFiles)
                ];
            }
        }

        return $filtered;
    }

    /**
     * Extract source name from file path
     */
    private function extractSource(string $path): string
    {
        // Try to extract plugin/app name from path
        // Example: vendor/swag/swag-analytics/... -> SwagAnalytics
        // Example: custom/apps/SwagAnalytics/... -> SwagAnalytics

        if (preg_match('#/(vendor|custom)/(apps|plugins)/([^/]+)#', $path, $matches)) {
            return $matches[3];
        }

        if (preg_match('#/vendor/([^/]+)/([^/]+)/#', $path, $matches)) {
            // Convert composer package name to readable name
            // e.g., "swag/swag-analytics" -> "SwagAnalytics"
            return $this->packageNameToReadable($matches[1] . '/' . $matches[2]);
        }

        return 'Unknown';
    }

    /**
     * Convert composer package name to readable format
     */
    private function packageNameToReadable(string $package): string
    {
        // Remove vendor prefix if it's part of the name
        // swag/swag-analytics -> swag-analytics
        $parts = explode('/', $package);
        $name = end($parts);

        // Convert kebab-case to PascalCase
        // swag-analytics -> SwagAnalytics
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
    }

    /**
     * Count snippets recursively in nested arrays
     */
    private function countSnippets(array $data): int
    {
        $count = 0;

        foreach ($data as $value) {
            if (is_array($value)) {
                $count += $this->countSnippets($value);
            } else {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Read and flatten snippet file
     */
    public function readSnippetFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Snippet file not found: $filePath");
        }

        $content = json_decode(file_get_contents($filePath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in file: $filePath - " . json_last_error_msg());
        }

        return $this->flattenArray($content);
    }

    /**
     * Flatten nested array to dot notation
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Unflatten dot notation back to nested array
     */
    public function unflattenArray(array $flat): array
    {
        $result = [];

        foreach ($flat as $key => $value) {
            $keys = explode('.', $key);
            $current = &$result;

            foreach ($keys as $i => $k) {
                if ($i === count($keys) - 1) {
                    $current[$k] = $value;
                } else {
                    if (!isset($current[$k])) {
                        $current[$k] = [];
                    }
                    $current = &$current[$k];
                }
            }
        }

        return $result;
    }

    /**
     * Write snippets to JSON file
     */
    public function writeSnippetFile(string $filePath, array $snippets): void
    {
        $unflattened = $this->unflattenArray($snippets);
        $json = json_encode($unflattened, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to encode JSON: " . json_last_error_msg());
        }

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $json);
    }
}
