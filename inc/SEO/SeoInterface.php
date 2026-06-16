<?php
namespace SAT\SEO;

interface SeoInterface {
    public function getMetaDescription(int $postId): string;
    public function updateMetaDescription(int $postId, string $desc): void;
    public function getSeoTitle(int $postId): string;
    public function updateSeoTitle(int $postId, string $title): void;
    public function getOgTitle(int $postId): string;
    public function updateOgTitle(int $postId, string $title): void;
}
