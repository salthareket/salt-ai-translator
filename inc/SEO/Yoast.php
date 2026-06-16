<?php
namespace SAT\SEO;

class Yoast implements SeoInterface {

    public function getMetaDescription(int $postId): string {
        return (string) get_post_meta($postId, '_yoast_wpseo_metadesc', true);
    }

    public function updateMetaDescription(int $postId, string $desc): void {
        update_post_meta($postId, '_yoast_wpseo_metadesc', $desc);
    }

    public function getSeoTitle(int $postId): string {
        return (string) get_post_meta($postId, '_yoast_wpseo_title', true);
    }

    public function updateSeoTitle(int $postId, string $title): void {
        update_post_meta($postId, '_yoast_wpseo_title', $title);
    }

    public function getOgTitle(int $postId): string {
        return (string) get_post_meta($postId, '_yoast_wpseo_opengraph-title', true);
    }

    public function updateOgTitle(int $postId, string $title): void {
        update_post_meta($postId, '_yoast_wpseo_opengraph-title', $title);
        update_post_meta($postId, '_yoast_wpseo_twitter-title', $title);
    }
}
