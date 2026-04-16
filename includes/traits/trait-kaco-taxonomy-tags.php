<?php

trait KACO_Taxonomy_Tags_Trait {
    private function build_taxonomy_health_snapshot() {
        $cleanup_plan = $this->get_hierarchy_cleanup_plan();
        $cleanup_rows = !empty($cleanup_plan['rows']) && is_array($cleanup_plan['rows']) ? $cleanup_plan['rows'] : array();
        $duplicate_like_tags = $this->find_duplicate_like_tags();
        $duplicate_like_categories = $this->find_duplicate_like_categories();
        $category_overlap_tags = $this->find_category_overlap_tags();
        $over_tagged_posts = $this->find_over_tagged_posts((int) get_option('kaco_tag_max_per_post', 12));
        $category_description_gaps = $this->count_category_description_gaps((int) get_option('kaco_category_desc_min_chars', 120));

        return array(
            'hierarchy_rows' => count($cleanup_rows),
            'category_description_gaps' => $category_description_gaps,
            'duplicate_category_groups' => count($duplicate_like_categories),
            'duplicate_tag_groups' => count(array_filter($duplicate_like_tags, function($terms) {
                return count((array) $terms) > 1;
            })),
            'category_overlap_tags' => count($category_overlap_tags),
            'over_tagged_posts' => count($over_tagged_posts),
        );
    }

    private function count_category_description_gaps($min_chars) {
        $terms = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'fields' => 'all',
        ));
        if (is_wp_error($terms) || empty($terms)) {
            return 0;
        }

        $count = 0;
        foreach ((array) $terms as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            if (strlen(trim((string) $term->description)) < (int) $min_chars) {
                $count++;
            }
        }
        return $count;
    }

    private function find_duplicate_like_categories() {
        if (!taxonomy_exists('category')) {
            return array();
        }

        $targets = $this->font_category_parent_targets();
        $groups = array();
        foreach (array(
            'designer' => $targets['designer'],
            'foundry' => $targets['foundry'],
            'font_style' => $targets['font_style'],
            'font_mood' => $targets['font_mood'],
            'font_use_case' => $targets['font_use_case'],
        ) as $branch_key => $parent_name) {
            $parent_term = $this->find_category_by_name($parent_name);
            if (!$parent_term) {
                continue;
            }

            $children = get_terms(array(
                'taxonomy' => 'category',
                'hide_empty' => false,
                'parent' => (int) $parent_term->term_id,
            ));
            if (is_wp_error($children) || empty($children)) {
                continue;
            }

            $normalized_groups = array();
            foreach ((array) $children as $child) {
                if (!$child || is_wp_error($child)) {
                    continue;
                }
                $normalized = $this->normalize_term_name((string) $child->name);
                if ($normalized === '') {
                    continue;
                }
                if (!isset($normalized_groups[$normalized])) {
                    $normalized_groups[$normalized] = array();
                }
                $normalized_groups[$normalized][] = $child;
            }

            foreach ($normalized_groups as $normalized => $terms) {
                if (count($terms) < 2) {
                    continue;
                }
                usort($terms, function($a, $b) {
                    return (int) $b->count <=> (int) $a->count;
                });
                $keep = $terms[0];
                $merge = array_slice($terms, 1);
                $groups[] = array(
                    'branch_key' => $branch_key,
                    'branch_label' => ucwords(str_replace('_', ' ', $branch_key)),
                    'parent_term_id' => (int) $parent_term->term_id,
                    'parent_name' => (string) $parent_term->name,
                    'normalized' => $normalized,
                    'keep' => $keep,
                    'merge' => $merge,
                );
            }
        }

        return $groups;
    }

    private function build_tag_merge_plan_from_request() {
        $keep_term_id = (int) ($_POST['keep_term_id'] ?? 0);
        $merge_term_ids = !empty($_POST['merge_term_ids']) && is_array($_POST['merge_term_ids']) ? array_values(array_unique(array_map('intval', (array) $_POST['merge_term_ids']))) : array();
        $keep_term = get_term($keep_term_id, 'post_tag');
        if (!$keep_term || is_wp_error($keep_term) || empty($merge_term_ids)) {
            return false;
        }

        $merged_names = array();
        $posts = array();
        foreach ($merge_term_ids as $merge_term_id) {
            $merge_term = get_term($merge_term_id, 'post_tag');
            if (!$merge_term || is_wp_error($merge_term)) {
                continue;
            }
            $merged_names[] = (string) $merge_term->name;
            $related_posts = get_objects_in_term($merge_term_id, 'post_tag');
            foreach ((array) $related_posts as $post_id) {
                if (!isset($posts[$post_id])) {
                    $posts[$post_id] = array();
                }
                $posts[$post_id][] = (string) $merge_term->name;
            }
        }

        return array(
            'keep_term_id' => $keep_term_id,
            'keep_name' => (string) $keep_term->name,
            'merge_term_ids' => $merge_term_ids,
            'merged_names' => $merged_names,
            'posts' => $posts,
        );
    }

    private function find_tag_ids_by_names($names) {
        $ids = array();
        foreach ((array) $names as $name) {
            $term = get_term_by('name', (string) $name, 'post_tag');
            if ($term && !is_wp_error($term)) {
                $ids[] = (int) $term->term_id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function get_parent_category_warnings() {
        $warnings = array();
        foreach ($this->font_category_parent_targets() as $key => $name) {
            $term = $this->find_category_by_name($name);
            if (!$term) {
                $warnings[] = ucfirst($key) . ' category "' . $name . '" not found';
            }
        }
        return $warnings;
    }

    private function find_over_tagged_posts($max_tags) {
        $query = new WP_Query(array(
            'post_type' => $this->automation_post_type(),
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => 200,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ));

        $results = array();
        foreach ((array) $query->posts as $post_id) {
            if (!$this->is_fonts_post((int) $post_id)) {
                continue;
            }
            $tags = wp_get_post_terms((int) $post_id, 'post_tag', array('fields' => 'ids'));
            if (!is_wp_error($tags) && count($tags) > (int) $max_tags) {
                $results[] = array(
                    'post_id' => (int) $post_id,
                    'tag_count' => count($tags),
                );
            }
        }
        return $results;
    }

    private function find_thin_tags($min_posts) {
        $terms = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
        ));
        $results = array();
        foreach ((array) $terms as $term) {
            if ($term && !is_wp_error($term) && (int) $term->count < (int) $min_posts) {
                $results[] = $term;
            }
        }
        return $results;
    }

    private function find_duplicate_like_tags() {
        $terms = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
        ));
        $groups = array();
        foreach ((array) $terms as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $normalized = $this->normalize_term_name((string) $term->name);
            if ($normalized === '') {
                continue;
            }
            if (!isset($groups[$normalized])) {
                $groups[$normalized] = array();
            }
            $groups[$normalized][] = $term;
        }
        return $groups;
    }

    private function find_category_overlap_tags() {
        $tags = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
        ));
        $categories = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
        ));

        $category_map = array();
        foreach ((array) $categories as $category) {
            if (!$category || is_wp_error($category)) {
                continue;
            }
            $category_map[$this->normalize_term_name((string) $category->name)] = $category;
        }

        $overlaps = array();
        foreach ((array) $tags as $tag) {
            if (!$tag || is_wp_error($tag)) {
                continue;
            }
            $normalized = $this->normalize_term_name((string) $tag->name);
            if ($normalized !== '' && isset($category_map[$normalized])) {
                $overlaps[$normalized] = array(
                    'tag' => $tag,
                    'category' => $category_map[$normalized],
                );
            }
        }
        return $overlaps;
    }

    private function build_tag_merge_recommendations($duplicate_like_tags) {
        $results = array();
        foreach ((array) $duplicate_like_tags as $normalized => $terms) {
            if (count((array) $terms) < 2) {
                continue;
            }
            usort($terms, function ($a, $b) {
                return (int) $b->count <=> (int) $a->count;
            });
            $keep = array_shift($terms);
            $merge = array();
            foreach ($terms as $term) {
                $merge[] = (string) $term->name;
            }
            $results[] = array(
                'keep' => (string) $keep->name,
                'merge' => $merge,
            );
        }
        return $results;
    }

    private function suggest_tag_keep_remove($post_id, $tag_limit, $category_overlap_tags, $duplicate_like_tags) {
        $terms = wp_get_post_terms((int) $post_id, 'post_tag');
        if (is_wp_error($terms) || empty($terms)) {
            return array('keep' => array(), 'remove' => array());
        }

        usort($terms, function ($a, $b) {
            return (int) $b->count <=> (int) $a->count;
        });

        $keep = array();
        $remove = array();
        foreach ($terms as $index => $term) {
            $normalized = $this->normalize_term_name((string) $term->name);
            $is_overlap = isset($category_overlap_tags[$normalized]);
            $is_duplicate_like = isset($duplicate_like_tags[$normalized]) && count($duplicate_like_tags[$normalized]) > 1;

            if ($is_overlap || $is_duplicate_like || $index >= (int) $tag_limit) {
                $remove[] = (string) $term->name;
            } else {
                $keep[] = (string) $term->name;
            }
        }

        return array(
            'keep' => array_slice($keep, 0, $tag_limit),
            'remove' => $remove,
        );
    }

    private function title_regenerator_enabled() {
        return get_option('kaco_enable_title_regenerator', '1') === '1';
    }

    private function sanitize_regenerated_title($title, $original_title) {
        $title = sanitize_text_field((string) $title);
        $original_title = trim((string) $original_title);
        if ($title === '' || $original_title === '') {
            return '';
        }

        if (stripos($title, $original_title . ' - ') !== 0) {
            return '';
        }

        $suffix = trim(substr($title, strlen($original_title . ' - ')));
        if ($suffix === '') {
            return '';
        }

        $words = preg_split('/\s+/', $suffix);
        $words = array_values(array_filter($words, 'strlen'));
        if (count($words) !== 4) {
            return '';
        }

        return $original_title . ' - ' . implode(' ', $words);
    }

    private function is_fonts_post($post_id) {
        $hierarchy = $this->analyze_font_category_hierarchy((int) $post_id);
        return !empty($hierarchy['is_fonts_post']);
    }

    private function passes_ai_confidence($ai) {
        $threshold = (float) get_option('kaco_ai_confidence_threshold', 0.65);
        $confidence = isset($ai['confidence']) ? (float) $ai['confidence'] : 0;
        return $confidence >= $threshold;
    }

    private function summarize_ai_evidence($evidence) {
        $parts = array();
        foreach ((array) $evidence as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $key . ': ' . $value;
            }
        }
        return !empty($parts) ? implode(' | ', $parts) : '-';
    }

    private function find_or_create_child_category($name, $parent_id) {
        $name = sanitize_text_field((string) $name);
        $parent_id = (int) $parent_id;
        if ($name === '' || $parent_id <= 0 || !taxonomy_exists('category')) {
            return false;
        }

        $normalized_name = $this->normalize_term_name($name);

        $existing = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'name' => $name,
            'parent' => $parent_id,
            'number' => 1,
        ));
        if (!is_wp_error($existing) && !empty($existing[0])) {
            return $existing[0];
        }

        $siblings = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'parent' => $parent_id,
        ));
        if (!is_wp_error($siblings) && !empty($siblings)) {
            foreach ($siblings as $sibling) {
                if ($this->normalize_term_name((string) $sibling->name) === $normalized_name) {
                    return $sibling;
                }
            }
        }

        $created = wp_insert_term($name, 'category', array(
            'parent' => $parent_id,
            'slug' => sanitize_title($name),
        ));
        if (is_wp_error($created) || empty($created['term_id'])) {
            $fallback = get_term_by('slug', sanitize_title($name), 'category');
            if ($fallback && !is_wp_error($fallback) && (int) $fallback->parent === $parent_id) {
                return $fallback;
            }
            return false;
        }

        $term = get_term((int) $created['term_id'], 'category');
        return ($term && !is_wp_error($term)) ? $term : false;
    }

    private function find_or_create_child_category_details($name, $parent_id) {
        $name = sanitize_text_field((string) $name);
        $parent_id = (int) $parent_id;
        if ($name === '' || $parent_id <= 0 || !taxonomy_exists('category')) {
            return array('term' => false, 'created' => false);
        }

        $normalized_name = $this->normalize_term_name($name);
        $existing = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'name' => $name,
            'parent' => $parent_id,
            'number' => 1,
        ));
        if (!is_wp_error($existing) && !empty($existing[0])) {
            return array('term' => $existing[0], 'created' => false);
        }

        $siblings = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'parent' => $parent_id,
        ));
        if (!is_wp_error($siblings) && !empty($siblings)) {
            foreach ($siblings as $sibling) {
                if ($this->normalize_term_name((string) $sibling->name) === $normalized_name) {
                    return array('term' => $sibling, 'created' => false);
                }
            }
        }

        $term = $this->find_or_create_child_category($name, $parent_id);
        return array('term' => $term, 'created' => (bool) $term);
    }

    private function queue_category_description_drafts_for_terms($terms) {
        if (empty($terms)) {
            return;
        }

        $suggestions = $this->get_term_suggestions();
        foreach ((array) $terms as $term) {
            if (!$term || is_wp_error($term) || (int) $term->term_id <= 0) {
                continue;
            }
            if (!empty($suggestions[(int) $term->term_id])) {
                continue;
            }
            $draft = $this->request_category_description_ai($term);
            if (!$draft) {
                continue;
            }
            $suggestions[(int) $term->term_id] = array(
                'taxonomy' => 'category',
                'term_id' => (int) $term->term_id,
                'term_name' => (string) $term->name,
                'original_description' => (string) $term->description,
                'description' => (string) $draft,
                'updated_at' => current_time('mysql', true),
            );
        }
        update_option('kaco_term_suggestions', $suggestions, false);
    }

    private function normalize_term_name($name) {
        $name = strtolower((string) $name);
        $name = preg_replace('/[^a-z0-9]+/', '', $name);
        return (string) $name;
    }

    private function fixed_font_styles() {
        return array(
            'Serif',
            'Sans Serif',
            'Script',
            'Display',
            'Slab Serif',
            'Monospace',
            'Blackletter',
            'Symbol & Dingbats',
            'Variable',
        );
    }

    private function fixed_font_moods() {
        return array(
            'Modern',
            'Vintage',
            'Elegant',
            'Minimal',
            'Luxury',
            'Futuristic',
            'Retro',
            'Playful',
            'Bold',
            'Cute',
        );
    }

    private function fixed_font_use_cases() {
        return array(
            'Logo',
            'Branding',
            'Wedding',
            'Editorial',
            'Social Media',
            'Packaging',
            'Poster',
            'Web',
            'App UI',
        );
    }

    private function canonical_font_style_name($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        $normalized = $this->normalize_term_name($value);
        $map = array(
            'serif' => 'Serif',
            'sansserif' => 'Sans Serif',
            'sans' => 'Sans Serif',
            'geometricsansserif' => 'Sans Serif',
            'grotesk' => 'Sans Serif',
            'grotesque' => 'Sans Serif',
            'script' => 'Script',
            'handwritten' => 'Script',
            'brushscript' => 'Script',
            'calligraphy' => 'Script',
            'display' => 'Display',
            'decorative' => 'Display',
            'slabserif' => 'Slab Serif',
            'slab' => 'Slab Serif',
            'monospace' => 'Monospace',
            'mono' => 'Monospace',
            'blackletter' => 'Blackletter',
            'gothic' => 'Blackletter',
            'symboldingbats' => 'Symbol & Dingbats',
            'dingbats' => 'Symbol & Dingbats',
            'symbol' => 'Symbol & Dingbats',
            'variable' => 'Variable',
            'variablefont' => 'Variable',
            'variablefonts' => 'Variable',
        );

        if (!empty($map[$normalized])) {
            return $map[$normalized];
        }

        foreach ($this->fixed_font_styles() as $style) {
            if ($this->normalize_term_name($style) === $normalized) {
                return $style;
            }
        }

        return '';
    }

    private function canonical_font_mood_name($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        $normalized = $this->normalize_term_name($value);
        $map = array(
            'modern' => 'Modern',
            'vintage' => 'Vintage',
            'elegant' => 'Elegant',
            'minimal' => 'Minimal',
            'luxury' => 'Luxury',
            'luxurious' => 'Luxury',
            'futuristic' => 'Futuristic',
            'future' => 'Futuristic',
            'retro' => 'Retro',
            'playful' => 'Playful',
            'fun' => 'Playful',
            'bold' => 'Bold',
            'cute' => 'Cute',
        );

        if (!empty($map[$normalized])) {
            return $map[$normalized];
        }

        foreach ($this->fixed_font_moods() as $mood) {
            if ($this->normalize_term_name($mood) === $normalized) {
                return $mood;
            }
        }

        return '';
    }

    private function canonical_font_use_case_name($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        $normalized = $this->normalize_term_name($value);
        $map = array(
            'logo' => 'Logo',
            'logos' => 'Logo',
            'branding' => 'Branding',
            'brandidentity' => 'Branding',
            'wedding' => 'Wedding',
            'weddings' => 'Wedding',
            'editorial' => 'Editorial',
            'magazine' => 'Editorial',
            'socialmedia' => 'Social Media',
            'social' => 'Social Media',
            'packaging' => 'Packaging',
            'package' => 'Packaging',
            'poster' => 'Poster',
            'posters' => 'Poster',
            'web' => 'Web',
            'website' => 'Web',
            'appui' => 'App UI',
            'ui' => 'App UI',
            'app' => 'App UI',
        );

        if (!empty($map[$normalized])) {
            return $map[$normalized];
        }

        foreach ($this->fixed_font_use_cases() as $use_case) {
            if ($this->normalize_term_name($use_case) === $normalized) {
                return $use_case;
            }
        }

        return '';
    }

    private function find_or_create_fixed_font_style_category($name, $parent_id) {
        $detail = $this->find_or_create_fixed_font_style_category_details($name, $parent_id);
        return !empty($detail['term']) ? $detail['term'] : false;
    }

    private function find_or_create_fixed_font_style_category_details($name, $parent_id) {
        $canonical = $this->canonical_font_style_name($name);
        if ($canonical === '') {
            return array('term' => false, 'created' => false);
        }
        return $this->find_or_create_child_category_details($canonical, (int) $parent_id);
    }

    private function find_or_create_fixed_font_mood_category($name, $parent_id) {
        $detail = $this->find_or_create_fixed_font_mood_category_details($name, $parent_id);
        return !empty($detail['term']) ? $detail['term'] : false;
    }

    private function find_or_create_fixed_font_mood_category_details($name, $parent_id) {
        $canonical = $this->canonical_font_mood_name($name);
        if ($canonical === '') {
            return array('term' => false, 'created' => false);
        }
        return $this->find_or_create_child_category_details($canonical, (int) $parent_id);
    }

    private function find_or_create_fixed_font_use_case_category($name, $parent_id) {
        $detail = $this->find_or_create_fixed_font_use_case_category_details($name, $parent_id);
        return !empty($detail['term']) ? $detail['term'] : false;
    }

    private function find_or_create_fixed_font_use_case_category_details($name, $parent_id) {
        $canonical = $this->canonical_font_use_case_name($name);
        if ($canonical === '') {
            return array('term' => false, 'created' => false);
        }
        return $this->find_or_create_child_category_details($canonical, (int) $parent_id);
    }

    private function sanitize_rewrite_mode($mode) {
        $mode = sanitize_key((string) $mode);
        if (!in_array($mode, array('append', 'replace_body', 'full_rebuild'), true)) {
            return 'replace_body';
        }
        return $mode;
    }
}
