<?php

namespace App\Services;

class CategoryNormalizer
{
    private static $commonDelimiters = [' > ', ' | ', '>', '|', '/', '->', '-'];

    /**
     * Normalize a category string by trying the user-supplied delimiter first,
     * then falling back to common delimiters.
     *
     * @param string $categoryString The raw category string from the feed.
     * @param string|null $userDelim The delimiter configured by the user.
     * @param array $categoryMap The mapping of source categories to destination IDs.
     * @return int|null The mapped category ID, or null if no mapping is found.
     */
    public static function normalize(string $categoryString, ?string $userDelim, array $categoryMap): ?int
    {
        $categoryString = trim($categoryString);
        
        // If category string is empty, return null early
        if (empty($categoryString)) {
            return null;
        }

        // Debug logging for troubleshooting
        \Log::debug("CategoryNormalizer::normalize called", [
            'category_string' => $categoryString,
            'user_delimiter' => $userDelim,
            'category_map_keys' => array_keys($categoryMap),
            'category_map_count' => count($categoryMap)
        ]);

        // 1. Try the user-configured delimiter first if it's provided.
        $delimiters = [];
        if (!empty($userDelim)) {
            $delimiters[] = $userDelim;
        }
        
        // Add common delimiters as fallbacks
        $delimiters = array_unique(array_merge($delimiters, self::$commonDelimiters));

        // First try to match using the standard hierarchy approach (leaf node)
        foreach ($delimiters as $delimiter) {
            $parts = explode($delimiter, $categoryString);
            $leaf = trim(end($parts));

            if (!empty($leaf) && isset($categoryMap[$leaf])) {
                \Log::debug("CategoryNormalizer: Found match using delimiter '{$delimiter}', leaf '{$leaf}' => {$categoryMap[$leaf]}");
                return $categoryMap[$leaf];
            }
        }

        // 2. Next try to match the entire category string
        if (isset($categoryMap[$categoryString])) {
            \Log::debug("CategoryNormalizer: Found match using full string '{$categoryString}' => {$categoryMap[$categoryString]}");
            return $categoryMap[$categoryString];
        }
        
        // 3. Finally, try with exact trimmed match
        $trimmedCategoryString = trim($categoryString);
        if ($trimmedCategoryString !== $categoryString && isset($categoryMap[$trimmedCategoryString])) {
            \Log::debug("CategoryNormalizer: Found match using trimmed string '{$trimmedCategoryString}' => {$categoryMap[$trimmedCategoryString]}");
            return $categoryMap[$trimmedCategoryString];
        }

        // No match found
        \Log::debug("CategoryNormalizer: No match found for '{$categoryString}'", [
            'tried_delimiters' => $delimiters,
            'available_categories' => array_keys($categoryMap)
        ]);
        return null;
    }

    /**
     * Extract the leaf node from the category key.
     *
     * @param string $categoryKey The raw category string
     * @param string|null $delimiter The configured delimiter (if any)
     * @return string The leaf category node (last item in hierarchy)
     */
    public static function extractLeaf(string $categoryKey, ?string $delimiter = '>'): string
    {
        if (empty($categoryKey)) {
            return '';
        }
        
        // If no delimiter is provided, use all common delimiters
        if (empty($delimiter)) {
            $pattern = '/\\>|\\||,|;|\\/|-/';
        } else {
            // Build a regex pattern for the specific delimiter plus common ones as fallback
            $pattern = '/'. preg_quote($delimiter, '/') . '|\\||,|;|\\/|-/';
        }

        // Split the category key using the pattern
        $parts = preg_split($pattern, $categoryKey);

        // Remove empty parts and trim whitespace
        $parts = array_filter(array_map('trim', $parts));
        
        if (empty($parts)) {
            return trim($categoryKey);
        }

        // Return the last part as the leaf node
        return end($parts);
    }
}
