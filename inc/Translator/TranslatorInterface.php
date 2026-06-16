<?php
namespace SAT\Translator;

interface TranslatorInterface {
    public function translate(string $text, string $lang, string $customPrompt = ''): string;
    public function translateBatch(array $texts, string $lang): array;
    public function generateAltText(string $imageUrl, string $lang): string;
    public function generateMetaDescription(string $title, string $content, string $lang): string;
    public function generateCaption(string $imageUrl, string $lang): string;
    public function estimateCost(string $text, string $model = ''): float;
    public function getCostPerToken(string $model = ''): float;
    public function getRemainingCredits(): array;
    public function getModels(): array;
    public function getLanguages(): array;
    public function supportsVision(): bool;
    public function supportsBatch(): bool;
    public function getName(): string;
}
