<?php
namespace SAT\SEO;

class RankMath implements SeoInterface {

    public function getMetaDescription(int $postId): string {
        return (string) get_post_meta($postId, 'rank_math_description', true);
    }

    public function updateMetaDescription(int $postId, string $desc): void {
        update_post_meta($postId, 'rank_math_description', $desc);
    }

    public function getSeoTitle(int $postId): string {
        return (string) get_post_meta($postId, 'rank_math_title', true);
    }

    public function updateSeoTitle(int $postId, string $title): void {
        update_post_meta($postId, 'rank_math_title', $title);
    }

    public function getOgTitle(int $postId): string {
        return (string) get_post_meta($postId, 'rank_math_facebook_title', true);
    }

    public function updateOgTitle(int $postId, string $title): void {
        update_post_meta($postId, 'rank_math_facebook_title', $title);
        update_post_meta($postId, 'rank_math_twitter_title', $title);
    }
}
