<?php

declare(strict_types=1);

namespace Lou\Content;

/**
 * One page in one language (site/content/pages/<id>/<lang>.json).
 */
final class Page
{
    /** Reserved ids. */
    public const HOME = 'home';
    public const NOT_FOUND = 'not-found';

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $data   the full decoded JSON
     */
    public function __construct(
        public readonly string $id,
        public readonly string $lang,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $menuLabel,
        public readonly string $description,
        public readonly string $template,
        public readonly ?string $parent,
        public readonly array $fields,
        public readonly array $data,
    ) {
    }

    /** Pages that are built but not listed in the sitemap or used as redirect sources. */
    public function isSpecial(): bool
    {
        return $this->id === self::NOT_FOUND;
    }
}
