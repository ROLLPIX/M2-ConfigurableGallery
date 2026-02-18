<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Model;

/**
 * Shared slug generation for SEO-friendly color URLs.
 * Used by Router (PHP) and ViewModel (to build slug map for JS).
 *
 * The slugify logic must produce identical output to the JS _slugify()
 * in gallery-switcher.js to ensure URL consistency.
 */
class SlugGenerator
{
    /**
     * Convert a label to a URL-safe slug.
     * "Marrón" → "marron", "Azul Marino" → "azul-marino"
     */
    public function slugify(string $label): string
    {
        // Lowercase
        $slug = mb_strtolower($label, 'UTF-8');

        // Transliterate accented characters
        if (function_exists('transliterator_transliterate')) {
            $slug = transliterator_transliterate('Any-Latin; Latin-ASCII', $slug);
        } else {
            // Fallback: NFD normalize + strip combining marks
            $slug = \Normalizer::normalize($slug, \Normalizer::FORM_D);
            $slug = preg_replace('/[\x{0300}-\x{036f}]/u', '', $slug);
        }

        // Replace non-alphanumeric with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Collapse multiple hyphens and trim
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Resolve a slug to an option_id by matching against slugified option labels.
     *
     * @param string $slug The URL slug to resolve
     * @param array<int, string> $options Map of option_id => label
     * @return int|null Matched option_id or null
     */
    public function resolveOptionId(string $slug, array $options): ?int
    {
        foreach ($options as $optionId => $label) {
            if ($this->slugify($label) === $slug) {
                return (int) $optionId;
            }
        }

        return null;
    }

    /**
     * Build a slug map for all options.
     *
     * @param array<int, string> $options Map of option_id => label
     * @return array<int, string> Map of option_id => slug
     */
    public function buildSlugMap(array $options): array
    {
        $map = [];
        foreach ($options as $optionId => $label) {
            $map[(int) $optionId] = $this->slugify($label);
        }
        return $map;
    }
}
