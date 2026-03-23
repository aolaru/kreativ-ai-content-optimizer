<?php

trait KACO_Admin_UI_Trait {
    private function tab_link($slug, $label, $current) {
        $class = $slug === $current ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = admin_url('admin.php?page=kaco-dashboard&view=' . $slug);
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private function render_audit_view() {
        $last_summary = get_option('kaco_last_audit_summary', array());

        echo '<h2>Run audit and create suggestions</h2>';
        echo '<p>This scans posts for category hierarchy gaps, weak internal linking, stale/thin content, duplicate risk, and category description gaps. It creates pending suggestions only.</p>';

        if (!empty($last_summary) && is_array($last_summary)) {
            echo '<div class="notice notice-info inline"><p><strong>Last audit summary</strong><br/>';
            echo 'Scanned: ' . (int) ($last_summary['scanned'] ?? 0);
            echo ' | Matched: ' . (int) ($last_summary['matched'] ?? 0);
            echo ' | Queued: ' . (int) ($last_summary['queued'] ?? 0);
            echo ' | Mode: ' . (!empty($last_summary['dry_run']) ? 'Dry Run' : 'Queue');
            echo ' | Filter: ' . esc_html((string) ($last_summary['issue_filter'] ?? 'all'));
            echo '</p>';
            if (!empty($last_summary['reason_totals']) && is_array($last_summary['reason_totals'])) {
                $reason_bits = array();
                foreach ($last_summary['reason_totals'] as $label => $count) {
                    $reason_bits[] = $label . ' (' . (int) $count . ')';
                }
                echo '<p><strong>Reasons:</strong> ' . esc_html(implode(' | ', $reason_bits)) . '</p>';
            }
            if (!empty($last_summary['top_rows']) && is_array($last_summary['top_rows'])) {
                echo '<p><strong>Top matches:</strong></p><ul>';
                foreach ($last_summary['top_rows'] as $row) {
                    echo '<li>' . esc_html((string) ($row['title'] ?? ('Post #' . (int) ($row['post_id'] ?? 0)))) . ' | priority ' . (int) ($row['priority_score'] ?? 0) . ' | ' . esc_html(implode(', ', (array) ($row['reason_badges'] ?? array()))) . ' | ' . esc_html((string) ($row['current_hierarchy_preview'] ?? '')) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_run_audit" />';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_post_type">Post type</label></th>';
        echo '<td><input type="text" id="kaco_post_type" name="kaco_post_type" value="post" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_limit">Batch size</label></th>';
        echo '<td><input type="number" min="1" max="500" id="kaco_limit" name="kaco_limit" value="100" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_only_missing">Only posts with missing font hierarchy</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_only_missing" name="kaco_only_missing" value="1" checked="checked" /> yes</label></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_scan_all">Scan all matching posts</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_scan_all" name="kaco_scan_all" value="1" /> yes (ignores batch size)</label></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_fonts_only">Fonts posts only</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_fonts_only" name="kaco_fonts_only" value="1" checked="checked" /> yes</label></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_issue_filter">Issue filter</label></th>';
        echo '<td><select id="kaco_issue_filter" name="kaco_issue_filter">';
        foreach (array(
            'all' => 'All issues',
            'missing_hierarchy' => 'Missing hierarchy',
            'thin' => 'Thin content',
            'stale' => 'Stale content',
            'low_links' => 'Low internal links',
            'duplicate' => 'Duplicate risk',
            'category_desc' => 'Category description gaps',
        ) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_dry_run">Dry run only</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_dry_run" name="kaco_dry_run" value="1" /> yes, summarize matches without queueing suggestions</label></td></tr>';
        echo '</tbody></table>';

        submit_button('Run Audit & Queue Suggestions');
        echo '</form>';
    }

    private function render_generator_view() {
        $previews = $this->get_generator_previews();
        $automation_previews = $this->get_generator_automation_review();
        $inbox = $this->get_generator_url_inbox();

        echo '<h2>Font Generator</h2>';
        echo '<p>Generate draft-ready commercial font posts from marketplace URLs, then review and create drafts inside WordPress.</p>';

        echo '<h3>URL inbox</h3>';
        echo '<p>Store raw marketplace URLs here and let automation process them in batches.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_add_generator_urls_to_inbox" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_generator_inbox_urls">Inbox URLs</label></th>';
        echo '<td><textarea id="kaco_generator_inbox_urls" name="kaco_generator_inbox_urls" rows="5" cols="100" class="large-text code" placeholder="https://www.myfonts.com/...&#10;https://creativemarket.com/..."></textarea>';
        echo '<p class="description">One URL per line. Duplicates already in the inbox are skipped.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Add URLs To Inbox', 'secondary', 'submit', false);
        echo '</form>';

        echo '<p><strong>Inbox status:</strong> ' . count($inbox) . ' URL(s) waiting';
        if (!empty($inbox)) {
            echo '<br/>' . esc_html(implode(' | ', array_slice($inbox, 0, 5)));
            if (count($inbox) > 5) {
                echo ' ...';
            }
        }
        echo '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_generate_font_previews" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_generator_urls">Marketplace URLs</label></th>';
        echo '<td><textarea id="kaco_generator_urls" name="kaco_generator_urls" rows="8" cols="100" class="large-text code" placeholder="https://www.myfonts.com/...&#10;https://creativemarket.com/..."></textarea>';
        echo '<p class="description">One URL per line. Supported workflow is best-effort generation using the same OpenAI settings as this plugin.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Generate Draft Previews');
        echo '</form>';

        if (empty($previews) && empty($automation_previews)) {
            return;
        }

        echo '<h3>Review previews</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_create_generated_drafts" />';

        $review_previews = array_merge(
            array_map(function($item) {
                $item['preview_source'] = 'automation';
                return $item;
            }, $automation_previews),
            array_map(function($item) {
                $item['preview_source'] = 'manual';
                return $item;
            }, $previews)
        );

        foreach ($review_previews as $index => $item) {
            echo '<div style="border:1px solid #ccd0d4;padding:12px;margin:0 0 18px 0;background:#fff;">';
            echo '<p><strong>Source:</strong> ' . esc_html((string) ($item['preview_source'] ?? 'manual')) . '</p>';
            echo '<p><strong>Source URL:</strong> ' . esc_html((string) ($item['url'] ?? '')) . '</p>';
            echo '<p><strong>Confidence:</strong> ' . esc_html(isset($item['confidence']) ? number_format((float) $item['confidence'], 2) : '0.00') . '</p>';
            if (!empty($item['evidence']) && is_array($item['evidence'])) {
                echo '<p><strong>Evidence:</strong> ' . esc_html($this->summarize_ai_evidence((array) $item['evidence'])) . '</p>';
            }
            if (!empty($item['automation_error'])) {
                echo '<p><strong>Automation note:</strong> ' . esc_html((string) $item['automation_error']) . '</p>';
            }
            if (empty($item['designer_names'])) {
                echo '<p><strong>Designer status:</strong> no explicit source match found. Review before creating the draft.</p>';
            }
            echo '<input type="hidden" name="previews[' . (int) $index . '][preview_source]" value="' . esc_attr((string) ($item['preview_source'] ?? 'manual')) . '" />';
            echo '<input type="hidden" name="previews[' . (int) $index . '][url]" value="' . esc_attr((string) ($item['url'] ?? '')) . '" />';
            echo '<p><label><strong>Title</strong><br/><input type="text" name="previews[' . (int) $index . '][title]" value="' . esc_attr((string) ($item['title'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Image URL</strong><br/><input type="url" name="previews[' . (int) $index . '][image_url]" value="' . esc_attr((string) ($item['image_url'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Designers</strong><br/><input type="text" name="previews[' . (int) $index . '][designer_names]" value="' . esc_attr(implode(', ', (array) ($item['designer_names'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Foundry</strong><br/><input type="text" name="previews[' . (int) $index . '][foundry_name]" value="' . esc_attr((string) ($item['foundry_name'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Font Style</strong><br/><input type="text" name="previews[' . (int) $index . '][font_style_name]" value="' . esc_attr((string) ($item['font_style_name'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Font Moods</strong><br/><input type="text" name="previews[' . (int) $index . '][font_mood_names]" value="' . esc_attr(implode(', ', (array) ($item['font_mood_names'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Font Use Cases</strong><br/><input type="text" name="previews[' . (int) $index . '][font_use_case_names]" value="' . esc_attr(implode(', ', (array) ($item['font_use_case_names'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Tags</strong><br/><input type="text" name="previews[' . (int) $index . '][tags]" value="' . esc_attr(implode(', ', (array) ($item['tags'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Content</strong><br/><textarea name="previews[' . (int) $index . '][content]" rows="18" class="large-text code">' . esc_textarea((string) ($item['content'] ?? '')) . '</textarea></label></p>';
            echo '<label><input type="checkbox" name="previews[' . (int) $index . '][create]" value="1" checked="checked" /> create draft</label>';
            echo '</div>';
        }

        submit_button('Create Selected Drafts');
        echo '</form>';
    }

    private function render_exceptions_view() {
        global $wpdb;

        $needs_review_rows = $wpdb->get_results('SELECT * FROM ' . $this->table_name() . " WHERE status = 'needs_review' ORDER BY updated_at DESC LIMIT 100", ARRAY_A);
        $generator_review = $this->get_generator_automation_review();
        $logs = array_values(array_filter($this->get_automation_logs(), function($item) {
            $status = (string) ($item['status'] ?? '');
            return in_array($status, array('failed', 'needs_review'), true);
        }));

        echo '<h2>Exception inbox</h2>';
        echo '<p>This is the manual review surface for automation fallout: low-confidence old-post suggestions, generator previews that were not auto-created, and automation failures that need retry or cleanup.</p>';

        echo '<h3>Old-post suggestions needing review</h3>';
        if (empty($needs_review_rows)) {
            echo '<p>No old-post suggestions are waiting in <code>needs_review</code>.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Post</th><th>Confidence</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
            foreach ($needs_review_rows as $row) {
                $post_title = get_the_title((int) $row['post_id']);
                $suggestion = json_decode((string) $row['suggestion_data'], true);
                $ai = !empty($suggestion['ai']) && is_array($suggestion['ai']) ? $suggestion['ai'] : array();
                $confidence = isset($ai['confidence']) ? number_format((float) $ai['confidence'], 2) : '0.00';
                echo '<tr>';
                echo '<td>' . (int) $row['id'] . '</td>';
                echo '<td><a href="' . esc_url(get_edit_post_link((int) $row['post_id'])) . '">' . esc_html($post_title ?: ('Post #' . (int) $row['post_id'])) . '</a></td>';
                echo '<td>' . esc_html($confidence) . '</td>';
                echo '<td>' . esc_html((string) $row['updated_at']) . '</td>';
                echo '<td><a href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=suggestions&filter=needs_review')) . '">Open in Suggestions</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3>Generator review queue</h3>';
        if (empty($generator_review)) {
            echo '<p>No generator previews are waiting for review.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>URL</th><th>Title</th><th>Confidence</th><th>Note</th></tr></thead><tbody>';
            foreach ($generator_review as $item) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['url'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($item['title'] ?? '')) . '</td>';
                echo '<td>' . esc_html(isset($item['confidence']) ? number_format((float) $item['confidence'], 2) : '0.00') . '</td>';
                echo '<td>' . esc_html((string) ($item['automation_error'] ?? 'Manual review required.')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3>Automation failure log</h3>';
        if (empty($logs)) {
            echo '<p>No recent automation failures or review escalations.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>When</th><th>Lane</th><th>Action</th><th>Status</th><th>Item</th><th>Confidence</th><th>Message</th></tr></thead><tbody>';
            foreach (array_slice($logs, 0, 100) as $log) {
                $item = array();
                if (!empty($log['suggestion_id'])) {
                    $item[] = 'Suggestion #' . (int) $log['suggestion_id'];
                }
                if (!empty($log['post_id'])) {
                    $item[] = 'Post #' . (int) $log['post_id'];
                }
                if (!empty($log['title'])) {
                    $item[] = (string) $log['title'];
                }
                if (!empty($log['url'])) {
                    $item[] = (string) $log['url'];
                }
                echo '<tr>';
                echo '<td>' . esc_html((string) ($log['logged_at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($log['lane'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($log['action'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($log['status'] ?? '')) . '</td>';
                echo '<td>' . esc_html(implode(' | ', $item)) . '</td>';
                echo '<td>' . esc_html(isset($log['confidence']) && $log['confidence'] !== null ? number_format((float) $log['confidence'], 2) : '-') . '</td>';
                echo '<td>' . esc_html((string) ($log['message'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function render_categories_view() {
        if (!taxonomy_exists('category')) {
            echo '<p>The `category` taxonomy is not registered on this site.</p>';
            return;
        }

        $min_chars = (int) get_option('kaco_category_desc_min_chars', 120);
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 100;
        $offset = ($paged - 1) * $per_page;
        $all_ids = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'fields' => 'ids',
        ));
        $total = is_array($all_ids) ? count($all_ids) : 0;

        $terms = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'number' => $per_page,
            'offset' => $offset,
            'orderby' => 'count',
            'order' => 'DESC',
        ));

        $term_suggestions = $this->get_term_suggestions();
        $term_history = $this->get_term_history();
        $parent_warnings = $this->get_parent_category_warnings();

        echo '<h2>Category analysis</h2>';
        echo '<p>Review category quality across large taxonomies and generate read-only AI description drafts before applying them.</p>';
        if (!empty($parent_warnings)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(implode(' | ', $parent_warnings)) . '</p></div>';
        }

        if (empty($terms) || is_wp_error($terms)) {
            echo '<p>No categories found.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Name</th><th>Posts</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ($terms as $term) {
            $desc = trim((string) $term->description);
            $status = strlen($desc) < $min_chars ? ($desc === '' ? 'missing' : 'short') : 'ok';
            $draft = $term_suggestions[$term->term_id] ?? null;
            $history = $term_history[$term->term_id] ?? null;

            echo '<tr>';
            echo '<td>' . (int) $term->term_id . '</td>';
            echo '<td>' . esc_html($term->name) . '</td>';
            echo '<td>' . (int) $term->count . '</td>';
            echo '<td>' . esc_html(wp_trim_words($desc !== '' ? $desc : '[empty]', 20, '...')) . '</td>';
            echo '<td>' . esc_html($status) . (!empty($draft['description']) ? ' / draft ready' : '') . '</td>';
            echo '<td>';
            if ($status !== 'ok') {
                $this->render_term_action_form('kaco_generate_category_ai', 'Generate Draft', (int) $term->term_id);
                if (!empty($draft['description'])) {
                    $this->render_term_action_form('kaco_apply_category_ai', 'Apply Draft', (int) $term->term_id);
                }
                if (!empty($history['previous_description']) || !empty($history['applied_description'])) {
                    $this->render_term_action_form('kaco_rollback_category_ai', 'Rollback', (int) $term->term_id);
                }
            }
            echo '</td>';
            echo '</tr>';

            if (!empty($draft['description'])) {
                echo '<tr><td></td><td colspan="5"><strong>Draft:</strong> ' . esc_html(wp_trim_words((string) $draft['description'], 40, '...')) . '</td></tr>';
            }
        }
        echo '</tbody></table>';

        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($total_pages > 1) {
            echo '<p>';
            for ($i = 1; $i <= $total_pages; $i++) {
                $url = admin_url('admin.php?page=kaco-dashboard&view=categories&paged=' . $i);
                if ($i === $paged) {
                    echo '<strong>' . (int) $i . '</strong> ';
                } else {
                    echo '<a href="' . esc_url($url) . '">' . (int) $i . '</a> ';
                }
            }
            echo '</p>';
        }
    }

    private function render_tags_view() {
        if (!taxonomy_exists('post_tag')) {
            echo '<p>The `post_tag` taxonomy is not registered on this site.</p>';
            return;
        }

        $tag_max_per_post = (int) get_option('kaco_tag_max_per_post', 12);
        $tag_min_posts_per_tag = (int) get_option('kaco_tag_min_posts_per_tag', 2);
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 100;
        $offset = ($paged - 1) * $per_page;

        $all_ids = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'fields' => 'ids',
        ));
        $total = is_array($all_ids) ? count($all_ids) : 0;

        $terms = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'number' => $per_page,
            'offset' => $offset,
            'orderby' => 'count',
            'order' => 'ASC',
        ));

        $over_tagged_posts = $this->find_over_tagged_posts($tag_max_per_post);
        $thin_tags = $this->find_thin_tags($tag_min_posts_per_tag);
        $duplicate_like_tags = $this->find_duplicate_like_tags();
        $category_overlap_tags = $this->find_category_overlap_tags();

        echo '<h2>Tag analysis</h2>';
        echo '<p>Review tag hygiene and best-practice issues before deciding whether to keep, merge, or reduce tags.</p>';

        echo '<div class="notice notice-info inline"><p>';
        echo 'Posts over tag limit: ' . (int) count($over_tagged_posts);
        echo ' | Tags used on fewer than ' . (int) $tag_min_posts_per_tag . ' posts: ' . (int) count($thin_tags);
        echo ' | Potential duplicate-like tags: ' . (int) count($duplicate_like_tags);
        echo ' | Tags overlapping categories: ' . (int) count($category_overlap_tags);
        echo '</p></div>';

        if (empty($terms) || is_wp_error($terms)) {
            echo '<p>No tags found.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Name</th><th>Posts</th><th>Status</th><th>Best Practice Notes</th></tr></thead><tbody>';
        foreach ($terms as $term) {
            $issues = array();
            if ((int) $term->count < $tag_min_posts_per_tag) {
                $issues[] = 'low usage';
            }
            $normalized = $this->normalize_term_name((string) $term->name);
            if (isset($duplicate_like_tags[$normalized]) && count($duplicate_like_tags[$normalized]) > 1) {
                $issues[] = 'duplicate-like';
            }
            if (isset($category_overlap_tags[$normalized])) {
                $issues[] = 'overlaps category';
            }
            $status = empty($issues) ? 'ok' : implode(', ', $issues);

            echo '<tr>';
            echo '<td>' . (int) $term->term_id . '</td>';
            echo '<td>' . esc_html($term->name) . '</td>';
            echo '<td>' . (int) $term->count . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>';
            if ((int) $term->count < $tag_min_posts_per_tag) {
                echo 'Consider removing or merging this tag if it does not represent a reusable browsing intent. ';
            }
            if (isset($duplicate_like_tags[$normalized]) && count($duplicate_like_tags[$normalized]) > 1) {
                echo 'Potential near-duplicate naming exists. ';
            }
            if (isset($category_overlap_tags[$normalized])) {
                echo 'This tag duplicates an existing category concept. ';
            }
            if (empty($issues)) {
                echo 'Tag looks healthy.';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h3>Tag merge recommendations</h3>';
        $merge_groups = $this->build_tag_merge_recommendations($duplicate_like_tags);
        if (empty($merge_groups)) {
            echo '<p>No merge recommendations found.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Keep</th><th>Merge Candidates</th><th>Reason</th><th>Actions</th></tr></thead><tbody>';
            foreach ($merge_groups as $group) {
                $keep_term = get_term_by('name', $group['keep'], 'post_tag');
                $merge_ids = $this->find_tag_ids_by_names($group['merge']);
                echo '<tr>';
                echo '<td>' . esc_html($group['keep']) . '</td>';
                echo '<td>' . esc_html(implode(', ', $group['merge'])) . '</td>';
                echo '<td>Normalized names collide; keep the strongest/most used canonical tag.</td>';
                echo '<td>';
                if ($keep_term && !empty($merge_ids)) {
                    $this->render_tag_merge_form('kaco_preview_tag_merge', 'Preview', (int) $keep_term->term_id, $merge_ids);
                    $this->render_tag_merge_form('kaco_export_tag_merge_csv', 'Export CSV', (int) $keep_term->term_id, $merge_ids);
                    $this->render_tag_merge_form('kaco_apply_tag_merge', 'Apply Merge', (int) $keep_term->term_id, $merge_ids);
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        $tag_merge_history = $this->get_tag_merge_history();
        if (!empty($tag_merge_history)) {
            echo '<h3>Recent tag merges</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Keep Tag</th><th>Merged Tags</th><th>When</th><th>Action</th></tr></thead><tbody>';
            foreach ($tag_merge_history as $merge_id => $item) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($item['keep_name'] ?? '')) . '</td>';
                echo '<td>' . esc_html(implode(', ', (array) ($item['merged_names'] ?? array()))) . '</td>';
                echo '<td>' . esc_html((string) ($item['created_at'] ?? '')) . '</td>';
                echo '<td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
                wp_nonce_field(self::NONCE_ACTION);
                echo '<input type="hidden" name="action" value="kaco_rollback_tag_merge" />';
                echo '<input type="hidden" name="merge_id" value="' . esc_attr((string) $merge_id) . '" />';
                submit_button('Rollback Merge', 'secondary small', '', false);
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3>Over-tagged font posts</h3>';
        if (empty($over_tagged_posts)) {
            echo '<p>No posts currently exceed the tag limit.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Tag Count</th><th>Keep</th><th>Consider Removing</th><th>Recommendation</th></tr></thead><tbody>';
            foreach ($over_tagged_posts as $item) {
                $tag_suggestion = $this->suggest_tag_keep_remove((int) $item['post_id'], $tag_max_per_post, $category_overlap_tags, $duplicate_like_tags);
                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link((int) $item['post_id'])) . '">' . esc_html(get_the_title((int) $item['post_id'])) . '</a></td>';
                echo '<td>' . (int) $item['tag_count'] . '</td>';
                echo '<td>' . esc_html(implode(', ', $tag_suggestion['keep'])) . '</td>';
                echo '<td>' . esc_html(implode(', ', $tag_suggestion['remove'])) . '</td>';
                echo '<td>Reduce to the strongest browsing/use-case tags. Avoid redundant style synonyms.</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($total_pages > 1) {
            echo '<p>';
            for ($i = 1; $i <= $total_pages; $i++) {
                $url = admin_url('admin.php?page=kaco-dashboard&view=tags&paged=' . $i);
                if ($i === $paged) {
                    echo '<strong>' . (int) $i . '</strong> ';
                } else {
                    echo '<a href="' . esc_url($url) . '">' . (int) $i . '</a> ';
                }
            }
            echo '</p>';
        }
    }

    private function render_suggestions_view() {
        global $wpdb;
        $table = $this->table_name();
        $filter = isset($_GET['filter']) ? sanitize_key((string) $_GET['filter']) : '';
        $where = '1=1';
        if ($filter === 'needs_review') {
            $where = "status = 'needs_review'";
        } elseif ($filter === 'approved') {
            $where = "status = 'approved'";
        } elseif ($filter === 'pending') {
            $where = "status = 'pending'";
        } elseif ($filter === 'missing_designer') {
            $where = "audit_data LIKE '%designer%'";
        } elseif ($filter === 'missing_foundry') {
            $where = "audit_data LIKE '%foundry%'";
        } elseif ($filter === 'missing_font_style') {
            $where = "audit_data LIKE '%font_style%'";
        } elseif ($filter === 'missing_font_mood') {
            $where = "audit_data LIKE '%font_mood%'";
        } elseif ($filter === 'missing_font_use_case') {
            $where = "audit_data LIKE '%font_use_case%'";
        }
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 200", ARRAY_A);

        echo '<h2>Suggestions queue</h2>';
        echo '<p>All changes are pending by default. Apply creates a post revision automatically.</p>';
        echo '<p>';
        foreach (array('all' => 'All', 'pending' => 'Pending', 'needs_review' => 'Needs Review', 'approved' => 'Approved', 'missing_designer' => 'Missing Designer', 'missing_foundry' => 'Missing Foundry', 'missing_font_style' => 'Missing Font Style', 'missing_font_mood' => 'Missing Font Mood', 'missing_font_use_case' => 'Missing Font Use Case') as $key => $label) {
            $url = admin_url('admin.php?page=kaco-dashboard&view=suggestions' . ($key !== 'all' ? '&filter=' . $key : ''));
            echo '<a href="' . esc_url($url) . '" style="margin-right:10px;">' . esc_html($label) . '</a>';
        }
        echo '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:10px 0 20px 0;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_generate_ai_batch" />';
        echo '<label for="kaco_ai_batch_size">Generate AI for pending items: </label> ';
        echo '<input type="number" min="1" max="100" id="kaco_ai_batch_size" name="kaco_ai_batch_size" value="20" /> ';
        submit_button('Run AI Batch', 'secondary', '', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 12px 16px 0;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_export_suggestions_csv" />';
        submit_button('Export CSV', 'secondary', '', false);
        echo '</form>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 12px 16px 0;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_import_suggestions_csv" />';
        echo '<input type="file" name="kaco_csv_file" accept=".csv" /> ';
        submit_button('Import CSV', 'secondary', '', false);
        echo '</form>';

        if (!$rows) {
            echo '<p>No suggestions yet.</p>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_bulk_suggestions" />';
        echo '<p><select name="kaco_bulk_action">';
        echo '<option value="">Bulk action</option>';
        echo '<option value="generate_ai">Generate AI</option>';
        echo '<option value="approve">Approve</option>';
        echo '<option value="apply">Apply</option>';
        echo '<option value="reject">Reject</option>';
        echo '</select> ';
        submit_button('Run', 'secondary', '', false);
        echo '</p>';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th><input type="checkbox" onclick="jQuery(\'.kaco-select\').prop(\'checked\', this.checked);" /></th><th>ID</th><th>Post</th><th>Status</th><th>Findings</th><th>AI</th><th>Created</th><th>Actions</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $post_link = get_edit_post_link((int) $row['post_id']);
            $post_title = get_the_title((int) $row['post_id']);
            $audit = json_decode($row['audit_data'], true);
            $suggestion = json_decode($row['suggestion_data'], true);
            $ai = !empty($suggestion['ai']) && is_array($suggestion['ai']) ? $suggestion['ai'] : array();
            $ai_ready = !empty($ai) ? 'yes' : 'no';
            $internal_links = isset($audit['internal_links']) ? (int) $audit['internal_links'] : 0;
            $stale = !empty($audit['stale']) ? 'yes' : 'no';
            $thin = !empty($audit['thin_content']) ? 'yes' : 'no';
            $word_count = isset($audit['word_count']) ? (int) $audit['word_count'] : 0;
            $dup_count = isset($audit['duplicate_candidates']) ? count((array) $audit['duplicate_candidates']) : 0;
            $cat_desc_gaps = isset($audit['category_desc_gaps']) ? count((array) $audit['category_desc_gaps']) : 0;
            $font_hierarchy_missing = isset($audit['font_hierarchy_missing']) ? implode(', ', (array) $audit['font_hierarchy_missing']) : '-';
            $confidence = isset($ai['confidence']) ? (float) $ai['confidence'] : 0;
            $evidence = !empty($ai['evidence']) && is_array($ai['evidence']) ? $ai['evidence'] : array();
            $evidence_text = $this->summarize_ai_evidence($evidence);
            $below_threshold = !empty($ai) && !$this->passes_ai_confidence($ai);

            echo '<tr>';
            echo '<td><input class="kaco-select" type="checkbox" name="suggestion_ids[]" value="' . (int) $row['id'] . '" /></td>';
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td><a href="' . esc_url($post_link) . '">' . esc_html($post_title ?: ('Post #' . (int) $row['post_id'])) . '</a></td>';
            echo '<td>' . esc_html($row['status']) . '</td>';
            echo '<td>internal links: ' . (int) $internal_links . '<br/>stale: ' . esc_html($stale) . '<br/>thin: ' . esc_html($thin) . ' (' . (int) $word_count . ' words)' . '<br/>duplicates: ' . (int) $dup_count . '<br/>category desc gaps: ' . (int) $cat_desc_gaps . '<br/>font hierarchy: ' . esc_html($font_hierarchy_missing) . '<br/>ai ready: ' . esc_html($ai_ready) . '</td>';
            echo '<td>confidence: ' . esc_html(number_format($confidence, 2)) . ($below_threshold ? ' / below threshold' : '') . '<br/>evidence: ' . esc_html($evidence_text) . '</td>';
            echo '<td>' . esc_html($row['created_at']) . '</td>';
            echo '<td>';

            if ($row['status'] === 'pending' || $row['status'] === 'needs_review') {
                $this->render_action_form('kaco_generate_ai_suggestion', 'Generate AI', (int) $row['id']);
                $this->render_action_form('kaco_approve_suggestion', 'Approve', (int) $row['id']);
                $this->render_action_form('kaco_reject_suggestion', 'Reject', (int) $row['id']);
            }

            if ($row['status'] === 'approved') {
                if (!$below_threshold) {
                    $this->render_action_form('kaco_apply_suggestion', 'Apply', (int) $row['id']);
                }
                $this->render_action_form('kaco_reject_suggestion', 'Reject', (int) $row['id']);
            }

            if ($row['status'] === 'applied') {
                $this->render_action_form('kaco_rollback_suggestion', 'Rollback', (int) $row['id']);
            }

            echo '</td>';
            echo '</tr>';
            echo '<tr><td></td><td colspan="7">' . $this->render_preview_panel($row, $audit, $suggestion, $ai) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</form>';
    }

    private function render_action_form($action, $label, $suggestion_id) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        echo '<input type="hidden" name="suggestion_id" value="' . (int) $suggestion_id . '" />';
        submit_button($label, 'secondary small', '', false);
        echo '</form>';
    }

    private function render_term_action_form($action, $label, $term_id) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        echo '<input type="hidden" name="term_id" value="' . (int) $term_id . '" />';
        submit_button($label, 'secondary small', '', false);
        echo '</form>';
    }

    private function render_tag_merge_form($action, $label, $keep_term_id, $merge_ids) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        echo '<input type="hidden" name="keep_term_id" value="' . (int) $keep_term_id . '" />';
        foreach ((array) $merge_ids as $merge_id) {
            echo '<input type="hidden" name="merge_term_ids[]" value="' . (int) $merge_id . '" />';
        }
        submit_button($label, 'secondary small', '', false);
        echo '</form>';
    }

    private function render_preview_panel($row, $audit, $suggestion, $ai) {
        $post = get_post((int) $row['post_id']);
        if (!$post) {
            return '<em>Preview unavailable.</em>';
        }
        $before_content = (string) $post->post_content;
        $before = wp_trim_words(wp_strip_all_tags($before_content), 30, '...');
        $after_intro = !empty($ai['refreshed_intro']) ? wp_trim_words(wp_strip_all_tags((string) $ai['refreshed_intro']), 30, '...') : '[no AI intro]';
        $visual = !empty($ai['visual_analysis']) ? wp_trim_words(wp_strip_all_tags((string) $ai['visual_analysis']), 24, '...') : '[no visual analysis]';
        $excerpt = !empty($ai['excerpt']) ? wp_trim_words((string) $ai['excerpt'], 20, '...') : '[no AI excerpt]';
        $hierarchy = !empty($ai['font_category_hierarchy']) ? $ai['font_category_hierarchy'] : array();
        $after_content = $before_content;
        $content_diff = '[AI not generated yet]';
        $title_diff = '[No proposed title change]';
        $excerpt_diff = '[No proposed excerpt change]';
        $category_diff = $this->build_hierarchy_assignment_diff($audit, $hierarchy);

        if (!empty($ai)) {
            $template = (string) get_option('kaco_update_template', '');
            $rewrite_mode = $this->sanitize_rewrite_mode((string) get_option('kaco_rewrite_mode', 'replace_body'));
            $context = array(
                'ai_intro' => (string) ($ai['refreshed_intro'] ?? ($ai['content_append'] ?? '')),
                'visual_analysis' => (string) ($ai['visual_analysis'] ?? ''),
                'best_for' => !empty($ai['best_for']) ? (array) $ai['best_for'] : array(),
                'pairing_notes' => !empty($ai['pairing_notes']) ? (array) $ai['pairing_notes'] : array(),
                'font_features' => !empty($ai['font_features']) ? (array) $ai['font_features'] : array(),
                'whats_included' => !empty($ai['whats_included']) ? (array) $ai['whats_included'] : array(),
                'pricing_details' => !empty($ai['pricing_details']) ? (array) $ai['pricing_details'] : array(),
                'verified_details' => !empty($ai['verified_details']) ? (array) $ai['verified_details'] : array(),
                'related_links' => !empty($ai['internal_links']) ? $ai['internal_links'] : ($suggestion['suggested_internal_links'] ?? array()),
            );
            $rendered_template = $this->render_template($template, (int) $post->ID, $context);
            $after_content = $this->rewrite_existing_post_content($before_content, $rendered_template, (string) ($suggestion['append_template'] ?? ''), $rewrite_mode);
            $content_diff = wp_text_diff(wp_strip_all_tags($before_content), wp_strip_all_tags($after_content), array('show_split_view' => true)) ?: '[No content diff]';

            $proposed_title = $this->sanitize_regenerated_title((string) ($ai['title'] ?? ''), (string) $post->post_title);
            if ($proposed_title !== '' && $proposed_title !== (string) $post->post_title) {
                $title_diff = wp_text_diff((string) $post->post_title, $proposed_title, array('show_split_view' => true)) ?: '[No title diff]';
            }

            if (!empty($ai['excerpt']) && (string) $ai['excerpt'] !== (string) $post->post_excerpt) {
                $excerpt_diff = wp_text_diff((string) $post->post_excerpt, (string) $ai['excerpt'], array('show_split_view' => true)) ?: '[No excerpt diff]';
            }
        }

        $out = '<strong>Preview</strong><br/>Before: ' . esc_html($before)
            . '<br/>Priority: ' . (int) ($audit['priority_score'] ?? 0)
            . '<br/>Reasons: ' . esc_html(!empty($audit['reason_badges']) ? implode(', ', (array) $audit['reason_badges']) : '-')
            . '<br/>Current hierarchy: ' . esc_html((string) ($audit['current_hierarchy_preview'] ?? '-'))
            . '<br/>Title: ' . esc_html(!empty($ai['title']) ? (string) $ai['title'] : $post->post_title)
            . '<br/>After intro: ' . esc_html($after_intro)
            . '<br/>Visual: ' . esc_html($visual)
            . '<br/>Excerpt: ' . esc_html($excerpt)
            . '<br/>Designers: ' . esc_html(!empty($hierarchy['designer_names']) ? implode(', ', (array) $hierarchy['designer_names']) : '-')
            . ' | Foundry: ' . esc_html((string) ($hierarchy['foundry_name'] ?? '-'))
            . ' | Font Style: ' . esc_html((string) ($hierarchy['font_style_name'] ?? '-'))
            . ' | Font Moods: ' . esc_html(!empty($hierarchy['font_mood_names']) ? implode(', ', (array) $hierarchy['font_mood_names']) : '-')
            . ' | Font Use Cases: ' . esc_html(!empty($hierarchy['font_use_case_names']) ? implode(', ', (array) $hierarchy['font_use_case_names']) : '-');
        $out .= '<details style="margin-top:8px;"><summary><strong>Content Diff</strong></summary>' . wp_kses_post($content_diff) . '</details>';
        $out .= '<details style="margin-top:8px;"><summary><strong>Title Diff</strong></summary>' . wp_kses_post($title_diff) . '</details>';
        $out .= '<details style="margin-top:8px;"><summary><strong>Excerpt Diff</strong></summary>' . wp_kses_post($excerpt_diff) . '</details>';
        $out .= '<details style="margin-top:8px;"><summary><strong>Category Assignment Diff</strong></summary>' . esc_html($category_diff) . '</details>';
        return $out;
    }

    private function build_hierarchy_assignment_diff($audit, $proposed_hierarchy) {
        $current_assigned = !empty($audit['font_category_hierarchy']['assigned']) ? (array) $audit['font_category_hierarchy']['assigned'] : array();
        $keys = array(
            'designer' => 'designer_names',
            'foundry' => 'foundry_name',
            'font_style' => 'font_style_name',
            'font_mood' => 'font_mood_names',
            'font_use_case' => 'font_use_case_names',
        );
        $parts = array();
        foreach ($keys as $current_key => $proposed_key) {
            $current_values = array();
            foreach ((array) ($current_assigned[$current_key] ?? array()) as $item) {
                if (!empty($item['name'])) {
                    $current_values[] = (string) $item['name'];
                }
            }

            $proposed_raw = $proposed_hierarchy[$proposed_key] ?? array();
            if (!is_array($proposed_raw)) {
                $proposed_raw = $proposed_raw !== '' ? array((string) $proposed_raw) : array();
            }
            $proposed_values = array_values(array_filter(array_map('sanitize_text_field', (array) $proposed_raw)));

            $label = ucwords(str_replace('_', ' ', $current_key));
            $parts[] = $label . ' current: [' . (!empty($current_values) ? implode(', ', $current_values) : '-') . '] -> proposed: [' . (!empty($proposed_values) ? implode(', ', $proposed_values) : '-') . ']';
        }

        return implode(' | ', $parts);
    }

    private function render_settings_view() {
        $stale_months = (int) get_option('kaco_stale_months', 18);
        $min_internal_links = (int) get_option('kaco_min_internal_links', 4);
        $min_words = (int) get_option('kaco_min_words', 250);
        $category_desc_min_chars = (int) get_option('kaco_category_desc_min_chars', 120);
        $template = get_option('kaco_update_template', '');
        $openai_key = (string) get_option('kaco_openai_api_key', '');
        $openai_endpoint = (string) get_option('kaco_openai_endpoint', self::OPENAI_ENDPOINT);
        $openai_model = (string) get_option('kaco_openai_model', self::OPENAI_MODEL);
        $fonts_category_name = (string) get_option('kaco_fonts_category_name', 'Fonts');
        $designer_parent_category_name = (string) get_option('kaco_designer_parent_category_name', 'Designer');
        $foundry_parent_category_name = (string) get_option('kaco_foundry_parent_category_name', 'Foundry');
        $font_style_parent_category_name = (string) get_option('kaco_font_style_parent_category_name', 'Font Style');
        $font_mood_parent_category_name = (string) get_option('kaco_font_mood_parent_category_name', 'Font Mood');
        $font_use_case_parent_category_name = (string) get_option('kaco_font_use_case_parent_category_name', 'Font Use Case');
        $ai_confidence_threshold = (string) get_option('kaco_ai_confidence_threshold', '0.65');
        $enable_title_regenerator = (string) get_option('kaco_enable_title_regenerator', '1');
        $tag_max_per_post = (int) get_option('kaco_tag_max_per_post', 12);
        $tag_min_posts_per_tag = (int) get_option('kaco_tag_min_posts_per_tag', 2);
        $editorial_style_guide = (string) get_option('kaco_editorial_style_guide', '');
        $rewrite_mode = (string) get_option('kaco_rewrite_mode', 'replace_body');
        $automation_enabled = (string) get_option('kaco_automation_enabled', '0');
        $automation_frequency = (string) get_option('kaco_automation_frequency', 'daily');
        $automation_post_type = (string) get_option('kaco_automation_post_type', 'post');
        $automation_scan_limit = (int) get_option('kaco_automation_scan_limit', 50);
        $automation_fonts_only = (string) get_option('kaco_automation_fonts_only', '1');
        $automation_issue_filter = (string) get_option('kaco_automation_issue_filter', 'all');
        $automation_auto_generate_ai = (string) get_option('kaco_automation_auto_generate_ai', '1');
        $automation_auto_approve = (string) get_option('kaco_automation_auto_approve', '1');
        $automation_approve_confidence = (string) get_option('kaco_automation_approve_confidence', '0.85');
        $automation_auto_apply = (string) get_option('kaco_automation_auto_apply', '0');
        $automation_apply_confidence = (string) get_option('kaco_automation_apply_confidence', '0.93');
        $automation_process_url_inbox = (string) get_option('kaco_automation_process_url_inbox', '1');
        $automation_url_batch_size = (int) get_option('kaco_automation_url_batch_size', 10);
        $automation_auto_create_drafts = (string) get_option('kaco_automation_auto_create_drafts', '1');
        $automation_generator_create_confidence = (string) get_option('kaco_automation_generator_create_confidence', '0.90');
        $automation_auto_schedule_generated_posts = (string) get_option('kaco_automation_auto_schedule_generated_posts', '1');
        $automation_generated_post_spacing_hours = (int) get_option('kaco_automation_generated_post_spacing_hours', 3);
        $automation_last_run = get_option('kaco_automation_last_run', array());
        $parent_warnings = $this->get_parent_category_warnings();

        echo '<h2>Rules and template</h2>';
        if (!empty($parent_warnings)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(implode(' | ', $parent_warnings)) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_save_settings" />';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_stale_months">Stale threshold (months)</label></th>';
        echo '<td><input type="number" min="1" max="120" id="kaco_stale_months" name="kaco_stale_months" value="' . (int) $stale_months . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_min_internal_links">Minimum internal links</label></th>';
        echo '<td><input type="number" min="0" max="50" id="kaco_min_internal_links" name="kaco_min_internal_links" value="' . (int) $min_internal_links . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_min_words">Minimum content words</label></th>';
        echo '<td><input type="number" min="50" max="5000" id="kaco_min_words" name="kaco_min_words" value="' . (int) $min_words . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_category_desc_min_chars">Minimum category description chars</label></th>';
        echo '<td><input type="number" min="20" max="2000" id="kaco_category_desc_min_chars" name="kaco_category_desc_min_chars" value="' . (int) $category_desc_min_chars . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_update_template">Content update template</label></th>';
        echo '<td><textarea id="kaco_update_template" name="kaco_update_template" rows="12" cols="80" class="large-text code">' . esc_textarea($template) . '</textarea></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_openai_api_key">OpenAI API key</label></th>';
        echo '<td><input type="password" id="kaco_openai_api_key" name="kaco_openai_api_key" value="' . esc_attr($openai_key) . '" class="regular-text" autocomplete="off" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_openai_endpoint">OpenAI endpoint</label></th>';
        echo '<td><input type="url" id="kaco_openai_endpoint" name="kaco_openai_endpoint" value="' . esc_attr($openai_endpoint) . '" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_openai_model">OpenAI model</label></th>';
        echo '<td><input type="text" id="kaco_openai_model" name="kaco_openai_model" value="' . esc_attr($openai_model) . '" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_fonts_category_name">Fonts category name</label></th>';
        echo '<td><input type="text" id="kaco_fonts_category_name" name="kaco_fonts_category_name" value="' . esc_attr($fonts_category_name) . '" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_designer_parent_category_name">Designer parent category</label></th>';
        echo '<td><input type="text" id="kaco_designer_parent_category_name" name="kaco_designer_parent_category_name" value="' . esc_attr($designer_parent_category_name) . '" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_foundry_parent_category_name">Foundry parent category</label></th>';
        echo '<td><input type="text" id="kaco_foundry_parent_category_name" name="kaco_foundry_parent_category_name" value="' . esc_attr($foundry_parent_category_name) . '" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_font_style_parent_category_name">Font Style parent category</label></th>';
        echo '<td><input type="text" id="kaco_font_style_parent_category_name" name="kaco_font_style_parent_category_name" value="' . esc_attr($font_style_parent_category_name) . '" class="regular-text" /> <p class="description">Fixed Font Style children only: ' . esc_html(implode(', ', $this->fixed_font_styles())) . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_font_mood_parent_category_name">Font Mood parent category</label></th>';
        echo '<td><input type="text" id="kaco_font_mood_parent_category_name" name="kaco_font_mood_parent_category_name" value="' . esc_attr($font_mood_parent_category_name) . '" class="regular-text" /> <p class="description">Fixed Font Mood children only: ' . esc_html(implode(', ', $this->fixed_font_moods())) . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_font_use_case_parent_category_name">Font Use Case parent category</label></th>';
        echo '<td><input type="text" id="kaco_font_use_case_parent_category_name" name="kaco_font_use_case_parent_category_name" value="' . esc_attr($font_use_case_parent_category_name) . '" class="regular-text" /> <p class="description">Fixed Font Use Case children only: ' . esc_html(implode(', ', $this->fixed_font_use_cases())) . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_ai_confidence_threshold">AI confidence threshold</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_ai_confidence_threshold" name="kaco_ai_confidence_threshold" value="' . esc_attr($ai_confidence_threshold) . '" /> <p class="description">Below this value, Apply is blocked until you lower the threshold or regenerate.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_enable_title_regenerator">Enable title regenerator</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_enable_title_regenerator" name="kaco_enable_title_regenerator" value="1" ' . checked('1', $enable_title_regenerator, false) . ' /> yes</label> <p class="description">Proposes titles like: Font Name - four word descriptor</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_tag_max_per_post">Max tags per font post</label></th>';
        echo '<td><input type="number" min="1" max="50" id="kaco_tag_max_per_post" name="kaco_tag_max_per_post" value="' . (int) $tag_max_per_post . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_tag_min_posts_per_tag">Min posts per tag</label></th>';
        echo '<td><input type="number" min="1" max="100" id="kaco_tag_min_posts_per_tag" name="kaco_tag_min_posts_per_tag" value="' . (int) $tag_min_posts_per_tag . '" /> <p class="description">Tags used on fewer posts than this are flagged as weak taxonomy candidates.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_editorial_style_guide">Editorial rewrite guide</label></th>';
        echo '<td><textarea id="kaco_editorial_style_guide" name="kaco_editorial_style_guide" rows="6" cols="80" class="large-text code">' . esc_textarea($editorial_style_guide) . '</textarea></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_rewrite_mode">Content rewrite mode</label></th>';
        echo '<td><select id="kaco_rewrite_mode" name="kaco_rewrite_mode">';
        echo '<option value="append"' . selected($rewrite_mode, 'append', false) . '>append</option>';
        echo '<option value="replace_body"' . selected($rewrite_mode, 'replace_body', false) . '>replace body</option>';
        echo '<option value="full_rebuild"' . selected($rewrite_mode, 'full_rebuild', false) . '>full rebuild</option>';
        echo '</select> <p class="description">`append` keeps existing content and adds the new structure. `replace body` preserves the top commercial blocks and replaces the descriptive body. `full rebuild` replaces the entire post content with the new structure.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_enabled">Automation enabled</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_enabled" name="kaco_automation_enabled" value="1" ' . checked('1', $automation_enabled, false) . ' /> yes</label> <p class="description">Runs scheduled content audits and can auto-generate AI plus auto-approve high-confidence suggestions.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_frequency">Automation frequency</label></th>';
        echo '<td><select id="kaco_automation_frequency" name="kaco_automation_frequency">';
        foreach (array('hourly' => 'hourly', 'twicedaily' => 'twice daily', 'daily' => 'daily') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($automation_frequency, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_post_type">Automation post type</label></th>';
        echo '<td><input type="text" id="kaco_automation_post_type" name="kaco_automation_post_type" value="' . esc_attr($automation_post_type) . '" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_scan_limit">Automation scan limit</label></th>';
        echo '<td><input type="number" min="1" max="500" id="kaco_automation_scan_limit" name="kaco_automation_scan_limit" value="' . (int) $automation_scan_limit . '" /> <p class="description">Number of posts checked per scheduled run.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_issue_filter">Automation issue filter</label></th>';
        echo '<td><select id="kaco_automation_issue_filter" name="kaco_automation_issue_filter">';
        foreach (array('all' => 'all issues', 'missing_hierarchy' => 'missing hierarchy', 'thin' => 'thin content', 'stale' => 'stale content', 'low_links' => 'low internal links', 'duplicate' => 'duplicate risk', 'category_desc' => 'category description gaps') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($automation_issue_filter, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_fonts_only">Automation fonts only</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_fonts_only" name="kaco_automation_fonts_only" value="1" ' . checked('1', $automation_fonts_only, false) . ' /> yes</label></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_auto_generate_ai">Automation auto-generate AI</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_generate_ai" name="kaco_automation_auto_generate_ai" value="1" ' . checked('1', $automation_auto_generate_ai, false) . ' /> yes</label></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_auto_approve">Automation auto-approve</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_approve" name="kaco_automation_auto_approve" value="1" ' . checked('1', $automation_auto_approve, false) . ' /> yes</label> <p class="description">Only suggestions at or above the automation confidence threshold will be approved automatically. Others stay in the exception queue.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_approve_confidence">Automation approve confidence</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_automation_approve_confidence" name="kaco_automation_approve_confidence" value="' . esc_attr($automation_approve_confidence) . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_auto_apply">Automation auto-apply approved suggestions</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_apply" name="kaco_automation_auto_apply" value="1" ' . checked('1', $automation_auto_apply, false) . ' /> yes</label> <p class="description">Only high-confidence approved old-post suggestions are auto-applied. Failures go to the exception inbox and automation log.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_apply_confidence">Automation auto-apply confidence</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_automation_apply_confidence" name="kaco_automation_apply_confidence" value="' . esc_attr($automation_apply_confidence) . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_process_url_inbox">Automation process URL inbox</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_process_url_inbox" name="kaco_automation_process_url_inbox" value="1" ' . checked('1', $automation_process_url_inbox, false) . ' /> yes</label></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_url_batch_size">Automation URL batch size</label></th>';
        echo '<td><input type="number" min="1" max="100" id="kaco_automation_url_batch_size" name="kaco_automation_url_batch_size" value="' . (int) $automation_url_batch_size . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_auto_create_drafts">Automation auto-create drafts</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_create_drafts" name="kaco_automation_auto_create_drafts" value="1" ' . checked('1', $automation_auto_create_drafts, false) . ' /> yes</label> <p class="description">High-confidence generator previews can become scheduled posts automatically. Weaker previews stay in the review queue.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_generator_create_confidence">Generator auto-create confidence</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_automation_generator_create_confidence" name="kaco_automation_generator_create_confidence" value="' . esc_attr($automation_generator_create_confidence) . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_auto_schedule_generated_posts">Auto-schedule generated posts</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_schedule_generated_posts" name="kaco_automation_auto_schedule_generated_posts" value="1" ' . checked('1', $automation_auto_schedule_generated_posts, false) . ' /> yes</label> <p class="description">When enabled, high-confidence generator posts are created as scheduled posts instead of drafts.</p></td></tr>';

        echo '<tr><th scope="row"><label for="kaco_automation_generated_post_spacing_hours">Generated post spacing (hours)</label></th>';
        echo '<td><input type="number" min="1" max="24" id="kaco_automation_generated_post_spacing_hours" name="kaco_automation_generated_post_spacing_hours" value="' . (int) $automation_generated_post_spacing_hours . '" /> <p class="description">Each newly scheduled post is placed this many hours after the last reserved slot.</p></td></tr>';
        echo '</tbody></table>';

        if (!empty($automation_last_run) && is_array($automation_last_run)) {
            $automation_bits = array(
                'Ran: ' . esc_html((string) ($automation_last_run['ran_at'] ?? '-')),
                'Scanned: ' . (int) ($automation_last_run['scanned'] ?? 0),
                'Matched: ' . (int) ($automation_last_run['matched'] ?? 0),
                'Queued: ' . (int) ($automation_last_run['queued'] ?? 0),
            );
            if (!empty($automation_last_run['automation']) && is_array($automation_last_run['automation'])) {
                $automation_bits[] = 'AI generated: ' . (int) ($automation_last_run['automation']['generated'] ?? 0);
                $automation_bits[] = 'Auto-approved: ' . (int) ($automation_last_run['automation']['approved'] ?? 0);
                $automation_bits[] = 'Auto-applied: ' . (int) ($automation_last_run['automation']['applied'] ?? 0);
                $automation_bits[] = 'Failed: ' . (int) ($automation_last_run['automation']['failed'] ?? 0);
            }
            if (!empty($automation_last_run['generator_inbox']) && is_array($automation_last_run['generator_inbox'])) {
                $automation_bits[] = 'Inbox processed: ' . (int) ($automation_last_run['generator_inbox']['processed'] ?? 0);
                $automation_bits[] = 'Drafts created: ' . (int) ($automation_last_run['generator_inbox']['created'] ?? 0);
                $automation_bits[] = 'Review queued: ' . (int) ($automation_last_run['generator_inbox']['queued_for_review'] ?? 0);
            }
            $automation_bits[] = '<a href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=exceptions')) . '">Open exceptions</a>';
            echo '<p><strong>Last automation run</strong><br/>' . implode(' | ', $automation_bits) . '</p>';
        }

        submit_button('Save Settings');
        echo '</form>';
    }

    public function handle_save_settings() {
        $this->require_admin_request();

        update_option('kaco_stale_months', max(1, (int) ($_POST['kaco_stale_months'] ?? 18)));
        update_option('kaco_min_internal_links', max(0, (int) ($_POST['kaco_min_internal_links'] ?? 4)));
        update_option('kaco_min_words', max(50, (int) ($_POST['kaco_min_words'] ?? 250)));
        update_option('kaco_category_desc_min_chars', max(20, (int) ($_POST['kaco_category_desc_min_chars'] ?? 120)));
        update_option('kaco_update_template', wp_kses_post(wp_unslash($_POST['kaco_update_template'] ?? '')));
        update_option('kaco_openai_api_key', sanitize_text_field(wp_unslash($_POST['kaco_openai_api_key'] ?? '')));
        update_option('kaco_openai_endpoint', esc_url_raw(wp_unslash($_POST['kaco_openai_endpoint'] ?? self::OPENAI_ENDPOINT)));
        update_option('kaco_openai_model', sanitize_text_field(wp_unslash($_POST['kaco_openai_model'] ?? self::OPENAI_MODEL)));
        update_option('kaco_fonts_category_name', sanitize_text_field(wp_unslash($_POST['kaco_fonts_category_name'] ?? 'Fonts')));
        update_option('kaco_designer_parent_category_name', sanitize_text_field(wp_unslash($_POST['kaco_designer_parent_category_name'] ?? 'Designer')));
        update_option('kaco_foundry_parent_category_name', sanitize_text_field(wp_unslash($_POST['kaco_foundry_parent_category_name'] ?? 'Foundry')));
        update_option('kaco_font_style_parent_category_name', sanitize_text_field(wp_unslash($_POST['kaco_font_style_parent_category_name'] ?? 'Font Style')));
        update_option('kaco_font_mood_parent_category_name', sanitize_text_field(wp_unslash($_POST['kaco_font_mood_parent_category_name'] ?? 'Font Mood')));
        update_option('kaco_font_use_case_parent_category_name', sanitize_text_field(wp_unslash($_POST['kaco_font_use_case_parent_category_name'] ?? 'Font Use Case')));
        update_option('kaco_ai_confidence_threshold', min(1, max(0, (float) ($_POST['kaco_ai_confidence_threshold'] ?? 0.65))));
        update_option('kaco_enable_title_regenerator', !empty($_POST['kaco_enable_title_regenerator']) ? '1' : '0');
        update_option('kaco_tag_max_per_post', max(1, (int) ($_POST['kaco_tag_max_per_post'] ?? 12)));
        update_option('kaco_tag_min_posts_per_tag', max(1, (int) ($_POST['kaco_tag_min_posts_per_tag'] ?? 2)));
        update_option('kaco_editorial_style_guide', sanitize_textarea_field((string) ($_POST['kaco_editorial_style_guide'] ?? '')));
        update_option('kaco_rewrite_mode', $this->sanitize_rewrite_mode((string) ($_POST['kaco_rewrite_mode'] ?? 'replace_body')));
        update_option('kaco_automation_enabled', !empty($_POST['kaco_automation_enabled']) ? '1' : '0');
        update_option('kaco_automation_frequency', $this->sanitize_automation_frequency((string) ($_POST['kaco_automation_frequency'] ?? 'daily')));
        update_option('kaco_automation_post_type', sanitize_key((string) ($_POST['kaco_automation_post_type'] ?? 'post')));
        update_option('kaco_automation_scan_limit', min(500, max(1, (int) ($_POST['kaco_automation_scan_limit'] ?? 50))));
        update_option('kaco_automation_fonts_only', !empty($_POST['kaco_automation_fonts_only']) ? '1' : '0');
        update_option('kaco_automation_issue_filter', sanitize_key((string) ($_POST['kaco_automation_issue_filter'] ?? 'all')));
        update_option('kaco_automation_auto_generate_ai', !empty($_POST['kaco_automation_auto_generate_ai']) ? '1' : '0');
        update_option('kaco_automation_auto_approve', !empty($_POST['kaco_automation_auto_approve']) ? '1' : '0');
        update_option('kaco_automation_approve_confidence', min(1, max(0, (float) ($_POST['kaco_automation_approve_confidence'] ?? 0.85))));
        update_option('kaco_automation_auto_apply', !empty($_POST['kaco_automation_auto_apply']) ? '1' : '0');
        update_option('kaco_automation_apply_confidence', min(1, max(0, (float) ($_POST['kaco_automation_apply_confidence'] ?? 0.93))));
        update_option('kaco_automation_process_url_inbox', !empty($_POST['kaco_automation_process_url_inbox']) ? '1' : '0');
        update_option('kaco_automation_url_batch_size', min(100, max(1, (int) ($_POST['kaco_automation_url_batch_size'] ?? 10))));
        update_option('kaco_automation_auto_create_drafts', !empty($_POST['kaco_automation_auto_create_drafts']) ? '1' : '0');
        update_option('kaco_automation_generator_create_confidence', min(1, max(0, (float) ($_POST['kaco_automation_generator_create_confidence'] ?? 0.90))));
        update_option('kaco_automation_auto_schedule_generated_posts', !empty($_POST['kaco_automation_auto_schedule_generated_posts']) ? '1' : '0');
        update_option('kaco_automation_generated_post_spacing_hours', min(24, max(1, (int) ($_POST['kaco_automation_generated_post_spacing_hours'] ?? 3))));
        $this->ensure_automation_schedule();

        $this->redirect_with_notice('Settings saved.', 'settings');
    }
}
