<?php

trait KACO_Content_Trait {
    private function audit_post($post_id, $stale_months, $min_internal_links, $min_words, $audit_index) {
        $content = (string) get_post_field('post_content', $post_id);
        $internal_links = $this->count_internal_links($content);
        $word_count = str_word_count(wp_strip_all_tags($content));
        $thin_content = $word_count < $min_words;

        $modified = get_post_field('post_modified_gmt', $post_id);
        $stale_threshold = gmdate('Y-m-d H:i:s', strtotime('-' . $stale_months . ' months'));
        $stale = $modified < $stale_threshold;

        $duplicate_candidates = array();
        if (!empty($audit_index['title_duplicates'][$post_id])) {
            $duplicate_candidates = array_merge($duplicate_candidates, (array) $audit_index['title_duplicates'][$post_id]);
        }
        if (!empty($audit_index['content_duplicates'][$post_id])) {
            $duplicate_candidates = array_merge($duplicate_candidates, (array) $audit_index['content_duplicates'][$post_id]);
        }
        $duplicate_candidates = array_values(array_unique(array_map('intval', $duplicate_candidates)));

        $category_desc_gaps = !empty($audit_index['category_desc_gaps'][$post_id]) ? (array) $audit_index['category_desc_gaps'][$post_id] : array();
        $font_category_hierarchy = $this->analyze_font_category_hierarchy($post_id);
        $font_hierarchy_missing = !empty($font_category_hierarchy['missing']) ? (array) $font_category_hierarchy['missing'] : array();
        $suggested_internal_links = $this->suggest_related_links($post_id, $font_category_hierarchy);

        return array(
            'internal_links' => $internal_links,
            'word_count' => (int) $word_count,
            'thin_content' => $thin_content,
            'stale' => $stale,
            'duplicate_candidates' => $duplicate_candidates,
            'category_desc_gaps' => $category_desc_gaps,
            'font_category_hierarchy' => $font_category_hierarchy,
            'font_hierarchy_missing' => $font_hierarchy_missing,
            'suggested_internal_links' => $suggested_internal_links,
            'needs_update' => $internal_links < $min_internal_links || $stale || $thin_content || !empty($duplicate_candidates) || !empty($category_desc_gaps) || !empty($font_hierarchy_missing),
        );
    }

    private function build_suggested_appendix($content, $template, $post_id) {
        $content = trim((string) $content);
        $template = $this->render_template((string) $template, (int) $post_id, array());
        $template = trim((string) $template);

        if ($template === '') {
            return '';
        }

        if (strpos($content, trim(strtok($template, "\n"))) !== false) {
            return '';
        }

        return "\n\n" . $template;
    }

    private function render_template($template, $post_id, $context) {
        $post = get_post($post_id);
        $title = $post ? (string) $post->post_title : '';
        $permalink = $post ? (string) get_permalink($post_id) : '';

        $ai_intro = isset($context['ai_intro']) ? (string) $context['ai_intro'] : 'A fresh content pass can improve discoverability and conversion.';
        $visual_analysis = isset($context['visual_analysis']) ? (string) $context['visual_analysis'] : 'Concrete visual analysis was not generated.';
        $best_for = isset($context['best_for']) && is_array($context['best_for']) ? $this->bullet_list_html($context['best_for']) : '- Specific use cases were not generated.';
        $pairing_notes = isset($context['pairing_notes']) && is_array($context['pairing_notes']) ? $this->bullet_list_html($context['pairing_notes']) : '- Pairing recommendations were not generated.';
        $font_features = isset($context['font_features']) && is_array($context['font_features']) ? $this->bullet_list_html($context['font_features']) : '- Font features were not generated.';
        $whats_included = isset($context['whats_included']) && is_array($context['whats_included']) ? $this->bullet_list_html($context['whats_included']) : '- Included items were not generated.';
        $pricing_details = isset($context['pricing_details']) && is_array($context['pricing_details']) ? $this->bullet_list_html($context['pricing_details']) : '- Pricing details were not generated.';
        $verified_details = isset($context['verified_details']) && is_array($context['verified_details']) ? $this->bullet_list_html($context['verified_details']) : '- Verified details were not generated.';

        $map = array(
            '{{post_title}}' => $title,
            '{{post_url}}' => $permalink,
            '{{related_links}}' => '',
            '{{ai_intro}}' => $ai_intro,
            '{{visual_analysis}}' => $visual_analysis,
            '{{best_for}}' => $best_for,
            '{{pairing_notes}}' => $pairing_notes,
            '{{font_features}}' => $font_features,
            '{{whats_included}}' => $whats_included,
            '{{pricing_details}}' => $pricing_details,
            '{{verified_details}}' => $verified_details,
        );

        return $this->cleanup_rendered_template(strtr((string) $template, $map));
    }

    private function cleanup_rendered_template($template) {
        $template = (string) $template;
        $template = preg_replace('/\n## Related fonts\s*\n\s*/i', "\n", $template);
        $template = preg_replace("/\n{3,}/", "\n\n", $template);
        return trim((string) $template);
    }

    private function bullet_list_html($items) {
        $lines = array();
        foreach ((array) $items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $lines[] = '- ' . $item;
            }
        }
        return !empty($lines) ? implode("\n", $lines) : '-';
    }

    private function suggest_related_links($post_id, $font_category_hierarchy = array()) {
        $category_ids = array();
        if (!empty($font_category_hierarchy['assigned']) && is_array($font_category_hierarchy['assigned'])) {
            foreach (array('foundry', 'designer', 'font_style', 'font_mood', 'font_use_case') as $priority_key) {
                $assigned_items = $font_category_hierarchy['assigned'][$priority_key] ?? array();
                foreach ((array) $assigned_items as $assigned) {
                    if (!empty($assigned['term_id'])) {
                        $category_ids[] = (int) $assigned['term_id'];
                    }
                }
            }
        }

        $query_args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'post__not_in' => array($post_id),
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        );
        if (!empty($category_ids)) {
            $query_args['category__in'] = array_values(array_unique($category_ids));
        }

        $related = get_posts($query_args);
        if (empty($related) && !empty($category_ids)) {
            unset($query_args['category__in']);
            $related = get_posts($query_args);
        }

        $links = array();
        foreach ((array) $related as $id) {
            $links[] = array(
                'post_id' => (int) $id,
                'url' => get_permalink($id),
                'title' => get_the_title($id),
            );
        }

        return $links;
    }

    private function internal_links_markdown($links) {
        if (empty($links) || !is_array($links)) {
            return '- Explore our latest font releases.';
        }

        $lines = array();
        foreach ($links as $link) {
            if (is_array($link)) {
                $url = esc_url((string) ($link['url'] ?? ''));
                $title = sanitize_text_field((string) ($link['title'] ?? ($link['anchor'] ?? 'Related font')));
                if ($url !== '' && $title !== '') {
                    $lines[] = '- <a href="' . $url . '">' . esc_html($title) . '</a>';
                }
            }
        }

        if (empty($lines)) {
            return '- Explore our latest font releases.';
        }

        return implode("\n", $lines);
    }

    private function count_internal_links($content) {
        $site = home_url();
        $host = wp_parse_url($site, PHP_URL_HOST);

        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', (string) $content, $matches);
        if (empty($matches[1])) {
            return 0;
        }

        $count = 0;
        foreach ($matches[1] as $href) {
            if (strpos($href, '/') === 0) {
                $count++;
                continue;
            }
            $href_host = wp_parse_url($href, PHP_URL_HOST);
            if ($href_host && $host && strcasecmp($href_host, $host) === 0) {
                $count++;
            }
        }

        return $count;
    }

    private function build_audit_index($post_ids, $category_desc_min_chars) {
        $title_groups = array();
        $fingerprint_groups = array();
        $category_desc_gaps = array();

        foreach ((array) $post_ids as $post_id) {
            $post_id = (int) $post_id;
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $title_key = sanitize_title((string) $post->post_title);
            if ($title_key !== '') {
                if (!isset($title_groups[$title_key])) {
                    $title_groups[$title_key] = array();
                }
                $title_groups[$title_key][] = $post_id;
            }

            $fingerprint = $this->content_fingerprint((string) $post->post_content);
            if ($fingerprint !== '') {
                if (!isset($fingerprint_groups[$fingerprint])) {
                    $fingerprint_groups[$fingerprint] = array();
                }
                $fingerprint_groups[$fingerprint][] = $post_id;
            }

            $category_desc_gaps[$post_id] = $this->find_category_description_gaps($post_id, $category_desc_min_chars);
        }

        return array(
            'title_duplicates' => $this->expand_duplicate_groups($title_groups),
            'content_duplicates' => $this->expand_duplicate_groups($fingerprint_groups),
            'category_desc_gaps' => $category_desc_gaps,
        );
    }

    private function content_fingerprint($content) {
        $plain = strtolower((string) wp_strip_all_tags($content));
        $plain = preg_replace('/\s+/', ' ', $plain);
        $plain = trim((string) $plain);
        if (strlen($plain) < 280) {
            return '';
        }
        return md5(substr($plain, 0, 2400));
    }

    private function expand_duplicate_groups($groups) {
        $map = array();
        foreach ((array) $groups as $ids) {
            $ids = array_values(array_unique(array_map('intval', (array) $ids)));
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $others = array_values(array_diff($ids, array($id)));
                if (!isset($map[$id])) {
                    $map[$id] = array();
                }
                $map[$id] = array_values(array_unique(array_merge($map[$id], $others)));
            }
        }
        return $map;
    }

    private function find_category_description_gaps($post_id, $min_chars) {
        if (!taxonomy_exists('category')) {
            return array();
        }

        $terms = wp_get_post_terms((int) $post_id, 'category');
        if (is_wp_error($terms) || empty($terms)) {
            return array(array('term_id' => 0, 'term_name' => 'Uncategorized', 'reason' => 'missing_category'));
        }

        $gaps = array();
        foreach ($terms as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $desc = trim((string) $term->description);
            if (strlen($desc) < (int) $min_chars) {
                $gaps[] = array(
                    'term_id' => (int) $term->term_id,
                    'term_name' => (string) $term->name,
                    'reason' => $desc === '' ? 'empty_description' : 'short_description',
                );
            }
        }
        return $gaps;
    }

    private function analyze_font_category_hierarchy($post_id) {
        $result = array(
            'is_fonts_post' => false,
            'missing' => array(),
            'assigned' => array(),
            'parent_targets' => $this->font_category_parent_targets(),
        );

        if (!taxonomy_exists('category')) {
            return $result;
        }

        $targets = $result['parent_targets'];
        $fonts_parent = $this->find_category_by_name($targets['fonts']);
        if (!$fonts_parent) {
            return $result;
        }

        $post_categories = wp_get_post_terms((int) $post_id, 'category');
        if (is_wp_error($post_categories) || empty($post_categories)) {
            return $result;
        }

        $post_categories_by_id = array();
        foreach ($post_categories as $term) {
            $post_categories_by_id[(int) $term->term_id] = $term;
        }

        $is_fonts_post = isset($post_categories_by_id[(int) $fonts_parent->term_id]) || $this->post_has_category_descendant($post_categories, (int) $fonts_parent->term_id);
        if (!$is_fonts_post) {
            return $result;
        }

        $result['is_fonts_post'] = true;

        foreach (array(
            'designer' => $targets['designer'],
            'foundry' => $targets['foundry'],
            'font_style' => $targets['font_style'],
            'font_mood' => $targets['font_mood'],
            'font_use_case' => $targets['font_use_case'],
        ) as $key => $parent_name) {
            $parent_term = $this->find_category_by_name($parent_name);
            if (!$parent_term) {
                $result['missing'][] = $key . ' parent category missing';
                continue;
            }
            $children = $this->find_assigned_child_categories($post_categories, (int) $parent_term->term_id);
            $validated = $this->validate_assigned_hierarchy_terms($children, $key);
            if (!empty($validated['errors'])) {
                $result['missing'] = array_merge($result['missing'], $validated['errors']);
            }
            if (!empty($validated['assigned'])) {
                $result['assigned'][$key] = $validated['assigned'];
            }
        }

        return $result;
    }

    private function font_category_parent_targets() {
        return array(
            'fonts' => (string) get_option('kaco_fonts_category_name', 'Fonts'),
            'designer' => (string) get_option('kaco_designer_parent_category_name', 'Designer'),
            'foundry' => (string) get_option('kaco_foundry_parent_category_name', 'Foundry'),
            'font_style' => (string) get_option('kaco_font_style_parent_category_name', 'Font Style'),
            'font_mood' => (string) get_option('kaco_font_mood_parent_category_name', 'Font Mood'),
            'font_use_case' => (string) get_option('kaco_font_use_case_parent_category_name', 'Font Use Case'),
        );
    }

    private function find_category_by_name($name) {
        $name = trim((string) $name);
        if ($name === '' || !taxonomy_exists('category')) {
            return false;
        }

        $term = get_term_by('name', $name, 'category');
        if ($term && !is_wp_error($term)) {
            return $term;
        }

        $term = get_term_by('slug', sanitize_title($name), 'category');
        if ($term && !is_wp_error($term)) {
            return $term;
        }

        return false;
    }

    private function post_has_category_descendant($terms, $ancestor_id) {
        foreach ((array) $terms as $term) {
            if (in_array((int) $ancestor_id, array_map('intval', (array) get_ancestors((int) $term->term_id, 'category', 'taxonomy')), true)) {
                return true;
            }
        }
        return false;
    }

    private function find_assigned_child_categories($terms, $parent_id) {
        $matches = array();
        foreach ((array) $terms as $term) {
            if ((int) $term->parent === (int) $parent_id) {
                $matches[] = $term;
            }
        }
        return $matches;
    }

    private function capture_post_terms($post_id, $taxonomies) {
        $snapshot = array();
        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($terms)) {
                $snapshot[$taxonomy] = array_map('intval', $terms);
            }
        }
        return $snapshot;
    }

    private function restore_post_terms($post_id, $terms_by_taxonomy) {
        foreach ($terms_by_taxonomy as $taxonomy => $term_ids) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            wp_set_post_terms($post_id, array_map('intval', (array) $term_ids), $taxonomy, false);
        }
    }

    private function capture_term_descriptions_from_audit($audit) {
        $snapshot = array();
        $gaps = !empty($audit['category_desc_gaps']) && is_array($audit['category_desc_gaps']) ? $audit['category_desc_gaps'] : array();
        foreach ($gaps as $gap) {
            $term_id = (int) ($gap['term_id'] ?? 0);
            if ($term_id <= 0) {
                continue;
            }
            $term = get_term($term_id, 'category');
            if ($term && !is_wp_error($term)) {
                $snapshot['category'][$term_id] = (string) $term->description;
            }
        }
        return $snapshot;
    }

    private function restore_term_descriptions($snapshot) {
        foreach ((array) $snapshot as $taxonomy => $terms) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if (!taxonomy_exists($taxonomy) || !is_array($terms)) {
                continue;
            }
            foreach ($terms as $term_id => $description) {
                $term_id = (int) $term_id;
                if ($term_id <= 0) {
                    continue;
                }
                wp_update_term($term_id, $taxonomy, array(
                    'description' => wp_kses_post((string) $description),
                ));
            }
        }
    }

    private function apply_refreshed_intro($content, $intro) {
        $intro = trim((string) $intro);
        if ($intro === '') {
            return (string) $content;
        }

        $content = (string) $content;
        if (strpos($content, $intro) !== false) {
            return $content;
        }

        $parts = preg_split('/\n\s*\n/', trim($content), 2);
        $first = isset($parts[0]) ? trim((string) $parts[0]) : '';
        $rest = isset($parts[1]) ? (string) $parts[1] : '';

        if ($first !== '' && strlen(wp_strip_all_tags($first)) > 45) {
            return $intro . "\n\n" . $rest;
        }

        return $intro . "\n\n" . $content;
    }

    private function rewrite_existing_post_content($existing_content, $rendered_template, $append, $rewrite_mode) {
        $existing_content = (string) $existing_content;
        $rendered_template = trim((string) $rendered_template);
        $append = trim((string) $append);
        $rewrite_mode = $this->sanitize_rewrite_mode((string) $rewrite_mode);

        $replacement = $rendered_template !== '' ? $rendered_template : $append;
        if ($replacement === '') {
            return $existing_content;
        }

        if ($rewrite_mode === 'full_rebuild') {
            return $replacement;
        }

        if ($rewrite_mode === 'replace_body') {
            $preserved = $this->extract_preserved_font_post_blocks($existing_content);
            if ($preserved !== '') {
                return trim($preserved . "\n\n" . $replacement);
            }
            return $replacement;
        }

        if (strpos($existing_content, $replacement) !== false) {
            return $existing_content;
        }

        return trim($existing_content . "\n\n" . $replacement);
    }

    private function extract_preserved_font_post_blocks($content) {
        $content = trim((string) $content);
        if ($content === '') {
            return '';
        }

        $blocks = preg_split('/\n\s*\n/', $content);
        $preserved = array();
        foreach ((array) $blocks as $block) {
            $block = trim((string) $block);
            if ($block === '') {
                continue;
            }

            if ($this->is_preserved_font_block($block)) {
                $preserved[] = $block;
                continue;
            }

            break;
        }

        return trim(implode("\n\n", $preserved));
    }

    private function is_preserved_font_block($block) {
        $block = trim((string) $block);
        if ($block === '') {
            return false;
        }

        if (preg_match('/^<img\b/i', $block)) {
            return true;
        }
        if (preg_match('/class=["\'][^"\']*btn[^"\']*["\']/i', $block)) {
            return true;
        }
        if (stripos($block, 'Important Notice') !== false) {
            return true;
        }
        if (stripos($block, 'Discover free fonts') !== false || stripos($block, 'free fonts instead') !== false) {
            return true;
        }
        if (preg_match('/<p>\s*[^<]{0,160}\s*<\/p>$/i', $block) && strpos(wp_strip_all_tags($block), ' - ') !== false) {
            return true;
        }

        return false;
    }

    private function apply_term_description_updates($term_descriptions) {
        foreach ((array) $term_descriptions as $taxonomy => $items) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if (!taxonomy_exists($taxonomy) || !is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $term_name = sanitize_text_field((string) ($item['term'] ?? ''));
                $description = wp_kses_post((string) ($item['description'] ?? ''));
                if ($term_name === '' || $description === '') {
                    continue;
                }

                $term = get_term_by('name', $term_name, $taxonomy);
                if (!$term) {
                    $term = get_term_by('slug', sanitize_title($term_name), $taxonomy);
                }

                if ($term && !is_wp_error($term)) {
                    wp_update_term((int) $term->term_id, $taxonomy, array(
                        'description' => $description,
                    ));
                }
            }
        }
    }

    private function apply_font_category_hierarchy($post_id, $hierarchy) {
        if (!taxonomy_exists('category') || !is_array($hierarchy)) {
            return array();
        }

        $targets = $this->font_category_parent_targets();
        $all_terms = wp_get_post_terms((int) $post_id, 'category');
        $current_ids = !is_wp_error($all_terms) ? wp_list_pluck((array) $all_terms, 'term_id') : array();
        $current_ids = array_map('intval', (array) $current_ids);
        $linked_terms = array();

        $branch_map = array(
            'designer' => array('field' => 'designer_names', 'mode' => 'multi'),
            'foundry' => array('field' => 'foundry_name', 'mode' => 'single'),
            'font_style' => array('field' => 'font_style_name', 'mode' => 'single'),
            'font_mood' => array('field' => 'font_mood_names', 'mode' => 'multi'),
            'font_use_case' => array('field' => 'font_use_case_names', 'mode' => 'multi'),
        );

        foreach ($branch_map as $target_key => $config) {
            $parent_term = $this->find_category_by_name($targets[$target_key]);
            if (!$parent_term) {
                continue;
            }

            $values = $this->normalize_hierarchy_input_values($hierarchy[$config['field']] ?? array(), $target_key);
            $current_ids = array_values(array_diff($current_ids, wp_list_pluck($this->find_assigned_child_categories($all_terms, (int) $parent_term->term_id), 'term_id')));
            if (empty($values)) {
                continue;
            }

            $detail_terms = $this->resolve_hierarchy_terms_for_parent($values, $target_key, (int) $parent_term->term_id);
            $linked_terms[$target_key] = array();
            foreach ($detail_terms as $detail) {
                if (empty($detail['term']) || is_wp_error($detail['term'])) {
                    continue;
                }
                $linked_terms[$target_key][] = $detail['term'];
                $current_ids[] = (int) $detail['term']->term_id;
            }
        }

        wp_set_post_terms((int) $post_id, array_values(array_unique(array_map('intval', $current_ids))), 'category', false);
        return $linked_terms;
    }

    private function build_font_category_links_block($terms) {
        if (empty($terms) || !is_array($terms)) {
            return '';
        }

        $labels = array(
            'designer' => 'Designer',
            'foundry' => 'Foundry',
            'font_style' => 'Font Style',
            'font_mood' => 'Font Mood',
            'font_use_case' => 'Font Use Case',
        );

        $lines = array();
        foreach ($labels as $key => $label) {
            $items = isset($terms[$key]) ? (array) $terms[$key] : array();
            $links = array();
            foreach ($items as $term) {
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                $url = get_term_link($term, 'category');
                if (is_wp_error($url)) {
                    continue;
                }
                $links[] = '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
            }
            if (!empty($links)) {
                $lines[] = '<li><strong>' . esc_html($label) . ':</strong> ' . implode(', ', $links) . '</li>';
            }
        }

        if (empty($lines)) {
            return '';
        }

        return '<h2>Browse this font</h2><ul>' . implode('', $lines) . '</ul>';
    }

    private function relink_font_mentions_to_internal_categories($content, $terms) {
        if (empty($terms) || !is_array($terms) || !class_exists('DOMDocument')) {
            return (string) $content;
        }

        $content = (string) $content;
        if (trim($content) === '') {
            return $content;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $html = '<div id="kaco-root">' . $content . '</div>';

        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (!$loaded) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $link_map = $this->build_font_term_link_map($terms);
        if (empty($link_map)) {
            return $content;
        }

        foreach ($xpath->query('//a') as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $anchor_text = $this->normalize_link_text($anchor->textContent);
            foreach ($link_map as $name_key => $data) {
                if ($anchor_text === $name_key) {
                    $anchor->setAttribute('href', $data['url']);
                    $anchor->setAttribute('data-kaco-link', '1');
                    break;
                }
            }
        }

        foreach ($link_map as $name_key => $data) {
            if ($this->document_has_internal_term_link($xpath, $data['url'])) {
                continue;
            }
            $this->link_text_mentions($dom, $xpath, $data['name'], $data['url'], 2);
        }

        $root = $dom->getElementById('kaco-root');
        if (!$root) {
            return $content;
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return $output !== '' ? $output : $content;
    }

    private function build_font_term_link_map($terms) {
        $map = array();
        foreach ((array) $terms as $group) {
            foreach ((array) $group as $term) {
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                $url = get_term_link($term, 'category');
                if (is_wp_error($url)) {
                    continue;
                }
                $name = trim((string) $term->name);
                $key = $this->normalize_link_text($name);
                if ($key === '') {
                    continue;
                }
                $map[$key] = array(
                    'name' => $name,
                    'url' => $url,
                );
            }
        }
        return $map;
    }

    private function validate_assigned_hierarchy_terms($children, $key) {
        $assigned = array();
        $errors = array();
        foreach ((array) $children as $child) {
            if ($key === 'font_style') {
                $name = $this->canonical_font_style_name((string) $child->name);
                if ($name === '') {
                    $errors[] = 'font_style_invalid';
                    continue;
                }
            } elseif ($key === 'font_mood') {
                $name = $this->canonical_font_mood_name((string) $child->name);
                if ($name === '') {
                    $errors[] = 'font_mood_invalid';
                    continue;
                }
            } elseif ($key === 'font_use_case') {
                $name = $this->canonical_font_use_case_name((string) $child->name);
                if ($name === '') {
                    $errors[] = 'font_use_case_invalid';
                    continue;
                }
            } else {
                $name = (string) $child->name;
            }
            $assigned[] = array('term_id' => (int) $child->term_id, 'name' => $name);
        }

        if ($key === 'foundry' || $key === 'font_style') {
            if (count($assigned) !== 1) {
                $errors[] = $key;
                $assigned = count($assigned) === 1 ? $assigned : array();
            }
        } else {
            if (count($assigned) < 1) {
                $errors[] = $key;
            }
        }

        return array('assigned' => $assigned, 'errors' => array_values(array_unique($errors)));
    }

    private function normalize_hierarchy_input_values($value, $target_key) {
        if (in_array($target_key, array('foundry', 'font_style'), true)) {
            $value = is_array($value) ? reset($value) : $value;
            $value = sanitize_text_field((string) $value);
            if ($target_key === 'font_style') {
                $value = $this->canonical_font_style_name($value);
            }
            return $value !== '' ? array($value) : array();
        }

        if ($target_key === 'designer') {
            return $this->sanitize_name_list($value);
        }
        if ($target_key === 'font_mood') {
            return $this->sanitize_canonical_name_list($value, 'canonical_font_mood_name');
        }
        if ($target_key === 'font_use_case') {
            return $this->sanitize_canonical_name_list($value, 'canonical_font_use_case_name');
        }
        return array();
    }

    private function resolve_hierarchy_terms_for_parent($values, $target_key, $parent_id) {
        $details = array();
        foreach ((array) $values as $value) {
            if ($target_key === 'font_style') {
                $details[] = $this->find_or_create_fixed_font_style_category_details($value, $parent_id);
            } elseif ($target_key === 'font_mood') {
                $details[] = $this->find_or_create_fixed_font_mood_category_details($value, $parent_id);
            } elseif ($target_key === 'font_use_case') {
                $details[] = $this->find_or_create_fixed_font_use_case_category_details($value, $parent_id);
            } else {
                $details[] = $this->find_or_create_child_category_details($value, $parent_id);
            }
        }
        return $details;
    }

    private function normalize_link_text($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', strtolower(trim($text)));
        return (string) $text;
    }

    private function document_has_internal_term_link($xpath, $url) {
        $query = sprintf('//a[@href=%s]', $this->xpath_literal($url));
        $nodes = $xpath->query($query);
        return $nodes instanceof DOMNodeList && $nodes->length > 0;
    }

    private function link_text_mentions($dom, $xpath, $term_name, $url, $max_links) {
        $text_nodes = $xpath->query('//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]');
        if (!$text_nodes instanceof DOMNodeList) {
            return false;
        }

        $quoted = preg_quote((string) $term_name, '/');
        $linked = 0;
        foreach ($text_nodes as $text_node) {
            $text = $text_node->nodeValue;
            if (!preg_match_all('/\b(' . $quoted . ')\b/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $fragment = $dom->createDocumentFragment();
            $cursor = 0;
            foreach ($matches[1] as $match) {
                if ($linked >= (int) $max_links) {
                    break;
                }
                $match_text = $match[0];
                $offset = (int) $match[1];
                $before = substr($text, $cursor, $offset - $cursor);
                if ($before !== '') {
                    $fragment->appendChild($dom->createTextNode($before));
                }
                $anchor = $dom->createElement('a');
                $anchor->setAttribute('href', $url);
                $anchor->setAttribute('data-kaco-link', '1');
                $anchor->appendChild($dom->createTextNode($match_text));
                $fragment->appendChild($anchor);
                $cursor = $offset + strlen($match_text);
                $linked++;
            }
            $after = substr($text, $cursor);
            if ($after !== '') {
                $fragment->appendChild($dom->createTextNode($after));
            }
            $text_node->parentNode->replaceChild($fragment, $text_node);
            if ($linked >= (int) $max_links) {
                return true;
            }
        }

        return $linked > 0;
    }

    private function xpath_literal($value) {
        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }
        if (strpos($value, '"') === false) {
            return '"' . $value . '"';
        }

        $parts = explode("'", $value);
        $safe = array();
        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $safe[] = "'" . $part . "'";
            }
            if ($index !== count($parts) - 1) {
                $safe[] = "\"'\"";
            }
        }
        return 'concat(' . implode(',', $safe) . ')';
    }

}
