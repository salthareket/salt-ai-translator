<?php
namespace SAT\Integration;

interface IntegrationInterface {
    public function getLanguages(): array;
    public function getDefaultLanguage(): string;
    public function getCurrentLanguage(): string;
    public function isTranslatablePostType(string $postType): bool;
    public function isTranslatableTaxonomy(string $taxonomy): bool;
    public function getPostTranslation(int $postId, string $lang): ?int;
    public function getTermTranslation(int $termId, string $lang): ?int;
    public function setPostLanguage(int $postId, string $lang): void;
    public function setTermLanguage(int $termId, string $lang): void;
    public function savePostTranslations(int $sourceId, int $translatedId, string $lang): void;
    public function saveTermTranslations(int $sourceId, int $translatedId, string $lang): void;
    public function translatePost(int $postId, string $lang): int;
    public function translateTerm(int $termId, string $taxonomy, string $lang): int;
    public function getUntranslatedPostIds(string $lang, array $postTypes = [], int $limit = -1): array;
    public function getUntranslatedTermIds(string $lang, array $taxonomies = [], int $limit = -1): array;
    public function getLanguageLabel(string $code): string;
    public function isMediaTranslationEnabled(): bool;
}
