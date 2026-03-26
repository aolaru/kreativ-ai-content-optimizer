<?php

trait KACO_Actions_Trait {
    public function handle_scan_hierarchy_cleanup() {
        $this->require_admin_request();

        $post_type = sanitize_key((string) ($_POST['kaco_cleanup_post_type'] ?? 'post'));
        $limit = min(1000, max(1, (int) ($_POST['kaco_cleanup_limit'] ?? 200)));
        $scan_all = !empty($_POST['kaco_cleanup_scan_all']);
        $fonts_only = !empty($_POST['kaco_cleanup_fonts_only']);

        $query = new WP_Query(array(
            'post_type' => $post_type,
            'post_status' => array('publish', 'draft', 'future'),
            'posts_per_page' => $scan_all ? -1 : $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ));

        $rows = array();
        foreach ((array) $query->posts as $post_id) {
            $post_id = (int) $post_id;
            if ($fonts_only && !$this->is_fonts_post($post_id)) {
                continue;
            }
            $row = $this->build_hierarchy_cleanup_row($post_id);
            if (!$row) {
                continue;
            }
            if (empty($row['issues']) && empty($row['changes'])) {
                continue;
            }
            $rows[] = $row;
        }
        wp_reset_postdata();

        update_option('kaco_hierarchy_cleanup_plan', array(
            'generated_at' => current_time('mysql', true),
            'post_type' => $post_type,
            'scanned' => count((array) $query->posts),
            'rows' => $rows,
        ), false);

        $this->redirect_with_notice('Hierarchy cleanup scan complete. Found ' . count($rows) . ' posts with hierarchy issues.', 'cleanup');
    }

    public function handle_apply_hierarchy_cleanup() {
        $this->require_admin_request();

        $plan = $this->get_hierarchy_cleanup_plan();
        $rows = !empty($plan['rows']) && is_array($plan['rows']) ? $plan['rows'] : array();
        if (empty($rows)) {
            $this->redirect_with_notice('No hierarchy cleanup plan is available.', 'cleanup');
        }

        $selected = !empty($_POST['cleanup_post_ids']) && is_array($_POST['cleanup_post_ids']) ? array_map('intval', (array) $_POST['cleanup_post_ids']) : array();
        if (empty($selected)) {
            $selected = array_map(function($row) {
                return (int) ($row['post_id'] ?? 0);
            }, array_filter($rows, function($row) {
                return !empty($row['applyable']);
            }));
        }
        $selected = array_values(array_filter(array_unique($selected)));
        if (empty($selected)) {
            $this->redirect_with_notice('No hierarchy cleanup rows were selected.', 'cleanup');
        }

        $history = $this->get_hierarchy_cleanup_history();
        $batch_id = uniqid('hierarchy_', true);
        $batch = array(
            'created_at' => current_time('mysql', true),
            'items' => array(),
        );
        $applied = 0;

        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($post_id <= 0 || !in_array($post_id, $selected, true) || empty($row['applyable'])) {
                continue;
            }

            $snapshot = $this->capture_post_terms($post_id, array('category'));
            $linked_terms = $this->apply_font_category_hierarchy($post_id, (array) ($row['proposed_hierarchy'] ?? array()));
            $after = $this->capture_post_terms($post_id, array('category'));
            $batch['items'][$post_id] = array(
                'post_id' => $post_id,
                'post_title' => (string) ($row['post_title'] ?? ''),
                'before_terms' => $snapshot,
                'after_terms' => $after,
                'linked_terms' => $linked_terms,
            );
            $applied++;
        }

        if ($applied === 0) {
            $this->redirect_with_notice('No hierarchy cleanup repairs were applied.', 'cleanup');
        }

        $history[$batch_id] = $batch;
        update_option('kaco_hierarchy_cleanup_history', $history, false);

        $remaining_rows = array_values(array_filter($rows, function($row) use ($selected) {
            $post_id = (int) ($row['post_id'] ?? 0);
            return !in_array($post_id, $selected, true);
        }));
        $plan['rows'] = $remaining_rows;
        update_option('kaco_hierarchy_cleanup_plan', $plan, false);

        $this->redirect_with_notice('Hierarchy cleanup applied to ' . $applied . ' posts.', 'cleanup');
    }

    public function handle_rollback_hierarchy_cleanup() {
        $this->require_admin_request();

        $batch_id = sanitize_text_field((string) ($_POST['cleanup_batch_id'] ?? ''));
        $history = $this->get_hierarchy_cleanup_history();
        $batch = $history[$batch_id] ?? null;
        if (!$batch || empty($batch['items']) || !is_array($batch['items'])) {
            $this->redirect_with_notice('Hierarchy cleanup rollback data not found.', 'cleanup');
        }

        foreach ($batch['items'] as $item) {
            $post_id = (int) ($item['post_id'] ?? 0);
            if ($post_id <= 0 || empty($item['before_terms'])) {
                continue;
            }
            $this->restore_post_terms($post_id, (array) $item['before_terms']);
        }

        unset($history[$batch_id]);
        update_option('kaco_hierarchy_cleanup_history', $history, false);
        $this->redirect_with_notice('Hierarchy cleanup batch rolled back.', 'cleanup');
    }

    public function handle_run_audit() {
        $this->require_admin_request();

        $summary = $this->run_audit_job(array(
            'post_type' => sanitize_key($_POST['kaco_post_type'] ?? 'post'),
            'limit' => min(500, max(1, (int) ($_POST['kaco_limit'] ?? 100))),
            'only_missing' => !empty($_POST['kaco_only_missing']),
            'scan_all' => !empty($_POST['kaco_scan_all']),
            'fonts_only' => !empty($_POST['kaco_fonts_only']),
            'dry_run' => !empty($_POST['kaco_dry_run']),
            'issue_filter' => sanitize_key((string) ($_POST['kaco_issue_filter'] ?? 'all')),
        ));
        update_option('kaco_last_audit_summary', $summary, false);

        $message = !empty($summary['dry_run'])
            ? "Dry-run audit complete. Scanned {$summary['scanned']} posts. Matched {$summary['matched']} posts. Queued 0 suggestions."
            : "Audit complete. Scanned {$summary['scanned']} posts. Matched {$summary['matched']} posts. Queued {$summary['queued']} suggestions.";
        $this->redirect_with_notice($message, 'audit');
    }

    public function handle_automation_event() {
        if (get_option('kaco_automation_enabled', '0') !== '1') {
            return;
        }

        $summary = $this->run_audit_job(array(
            'post_type' => sanitize_key((string) get_option('kaco_automation_post_type', 'post')),
            'limit' => min(500, max(1, (int) get_option('kaco_automation_scan_limit', 50))),
            'only_missing' => false,
            'scan_all' => false,
            'fonts_only' => get_option('kaco_automation_fonts_only', '1') === '1',
            'dry_run' => false,
            'issue_filter' => sanitize_key((string) get_option('kaco_automation_issue_filter', 'all')),
        ));

        $processed = array(
            'generated' => 0,
            'approved' => 0,
            'applied' => 0,
            'failed' => 0,
        );
        if (!empty($summary['queued_ids']) && is_array($summary['queued_ids'])) {
            $processed = $this->process_automation_suggestions($summary['queued_ids']);
        }

        $summary['automation'] = array(
            'generated' => (int) ($processed['generated'] ?? 0),
            'approved' => (int) ($processed['approved'] ?? 0),
            'applied' => (int) ($processed['applied'] ?? 0),
            'failed' => (int) ($processed['failed'] ?? 0),
        );
        $summary['generator_inbox'] = $this->process_generator_url_inbox();
        update_option('kaco_last_audit_summary', $summary, false);
        update_option('kaco_automation_last_run', $summary, false);
    }

    private function run_audit_job($args) {
        $post_type = sanitize_key((string) ($args['post_type'] ?? 'post'));
        $limit = min(500, max(1, (int) ($args['limit'] ?? 100)));
        $only_missing = !empty($args['only_missing']);
        $scan_all = !empty($args['scan_all']);
        $fonts_only = !empty($args['fonts_only']);
        $dry_run = !empty($args['dry_run']);
        $issue_filter = sanitize_key((string) ($args['issue_filter'] ?? 'all'));

        $stale_months = (int) get_option('kaco_stale_months', 18);
        $min_internal_links = (int) get_option('kaco_min_internal_links', 4);
        $min_words = (int) get_option('kaco_min_words', 250);
        $category_desc_min_chars = (int) get_option('kaco_category_desc_min_chars', 120);
        $template = (string) get_option('kaco_update_template', '');

        $query = new WP_Query(array(
            'post_type' => $post_type,
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => $scan_all ? -1 : $limit,
            'orderby' => 'modified',
            'order' => 'ASC',
            'fields' => 'ids',
        ));

        $post_ids = array_map('intval', (array) $query->posts);
        if ($fonts_only) {
            $post_ids = array_values(array_filter($post_ids, array($this, 'is_fonts_post')));
        }
        $audit_index = $this->build_audit_index($post_ids, $category_desc_min_chars);

        $queued = 0;
        $matched = 0;
        $queued_ids = array();
        $reason_totals = array();
        $top_rows = array();
        foreach ($post_ids as $post_id) {
            $audit = $this->audit_post((int) $post_id, $stale_months, $min_internal_links, $min_words, $audit_index);
            if ($only_missing && empty($audit['font_hierarchy_missing'])) {
                continue;
            }
            if (!$this->audit_matches_issue_filter($audit, $issue_filter, $min_internal_links)) {
                continue;
            }
            if (!$audit['needs_update']) {
                continue;
            }
            $matched++;

            foreach ((array) ($audit['reason_badges'] ?? array()) as $badge) {
                if (!isset($reason_totals[$badge])) {
                    $reason_totals[$badge] = 0;
                }
                $reason_totals[$badge]++;
            }

            $post = get_post((int) $post_id);
            if (!$post) {
                continue;
            }

            $top_rows[] = array(
                'post_id' => (int) $post_id,
                'title' => (string) $post->post_title,
                'priority_score' => (int) ($audit['priority_score'] ?? 0),
                'reason_badges' => (array) ($audit['reason_badges'] ?? array()),
                'current_hierarchy_preview' => (string) ($audit['current_hierarchy_preview'] ?? ''),
            );

            if ($this->has_active_suggestion((int) $post_id)) {
                continue;
            }

            if ($dry_run) {
                continue;
            }

            $snapshot = array(
                'post_title' => $post->post_title,
                'post_excerpt' => $post->post_excerpt,
                'post_content' => $post->post_content,
                'terms' => $this->capture_post_terms((int) $post_id, array('category')),
                'term_descriptions' => $this->capture_term_descriptions_from_audit($audit),
            );

            $suggestion = array(
                'append_template' => $this->build_suggested_appendix($post->post_content, $template, (int) $post_id),
                'suggested_internal_links' => $this->suggest_related_links((int) $post_id),
                'category_description_targets' => $audit['category_desc_gaps'],
                'font_category_hierarchy' => !empty($audit['font_category_hierarchy']) ? $audit['font_category_hierarchy'] : array(),
                'created_by_rule_engine' => true,
            );

            $inserted_id = $this->insert_suggestion((int) $post_id, $audit, $suggestion, $snapshot);
            if ($inserted_id > 0) {
                $queued++;
                $queued_ids[] = (int) $inserted_id;
            }
        }

        wp_reset_postdata();
        usort($top_rows, function($a, $b) {
            return ((int) ($b['priority_score'] ?? 0)) <=> ((int) ($a['priority_score'] ?? 0));
        });

        return array(
            'ran_at' => current_time('mysql', true),
            'post_type' => $post_type,
            'scanned' => count($post_ids),
            'matched' => $matched,
            'queued' => $queued,
            'queued_ids' => $queued_ids,
            'dry_run' => $dry_run,
            'issue_filter' => $issue_filter,
            'reason_totals' => $reason_totals,
            'top_rows' => array_slice($top_rows, 0, 8),
        );
    }

    private function audit_matches_issue_filter($audit, $issue_filter, $min_internal_links) {
        switch ($issue_filter) {
            case 'missing_hierarchy':
                return !empty($audit['font_hierarchy_missing']);
            case 'thin':
                return !empty($audit['thin_content']);
            case 'stale':
                return !empty($audit['stale']);
            case 'low_links':
                return (int) ($audit['internal_links'] ?? 0) < (int) $min_internal_links;
            case 'duplicate':
                return !empty($audit['duplicate_candidates']);
            case 'category_desc':
                return !empty($audit['category_desc_gaps']);
            default:
                return true;
        }
    }

    public function handle_generate_ai_batch() {
        $this->require_admin_request();
        global $wpdb;

        $batch_size = min(100, max(1, (int) ($_POST['kaco_ai_batch_size'] ?? 20)));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $this->table_name() . " WHERE status = 'pending' ORDER BY created_at DESC LIMIT %d", $batch_size), ARRAY_A);

        if (!$rows) {
            $this->redirect_with_notice('No pending suggestions found.', 'suggestions');
        }

        $done = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $ok = $this->generate_ai_for_row($row);
            if ($ok) {
                $done++;
            } else {
                $failed++;
            }
        }

        $this->redirect_with_notice("AI batch complete. Updated: {$done}, failed: {$failed}.", 'suggestions');
    }

    public function handle_bulk_suggestions() {
        $this->require_admin_request();

        $ids = !empty($_POST['suggestion_ids']) && is_array($_POST['suggestion_ids']) ? array_map('intval', (array) $_POST['suggestion_ids']) : array();
        $bulk_action = sanitize_key((string) ($_POST['kaco_bulk_action'] ?? ''));

        if (empty($ids) || $bulk_action === '') {
            $this->redirect_with_notice('Select suggestions and a bulk action first.', 'suggestions');
        }

        $done = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $row = $this->get_suggestion($id);
            if (!$row) {
                $failed++;
                continue;
            }

            if ($bulk_action === 'generate_ai') {
                $ok = in_array($row['status'], array('pending', 'needs_review', 'approved'), true) ? $this->generate_ai_for_row($row) : false;
            } elseif ($bulk_action === 'approve') {
                $ok = in_array($row['status'], array('pending', 'needs_review'), true) ? $this->approve_suggestion_row($row) : false;
            } elseif ($bulk_action === 'apply') {
                $ok = $row['status'] === 'approved' ? $this->apply_suggestion_row($row) : false;
            } elseif ($bulk_action === 'reject') {
                $ok = in_array($row['status'], array('pending', 'needs_review'), true) ? $this->reject_suggestion_row($row) : false;
            } else {
                $ok = false;
            }

            if ($ok === true) {
                $done++;
            } else {
                $failed++;
            }
        }

        $this->redirect_with_notice("Bulk action complete. Updated: {$done}, failed: {$failed}.", 'suggestions');
    }

    public function handle_export_suggestions_csv() {
        $this->require_admin_request();
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $this->table_name() . ' ORDER BY created_at DESC LIMIT 5000', ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=kaco-suggestions-' . gmdate('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array('suggestion_id', 'post_id', 'post_title', 'status', 'confidence', 'designer_names', 'foundry_name', 'font_style_name', 'font_mood_names', 'font_use_case_names', 'font_hierarchy_missing', 'evidence', 'created_at'));

        foreach ((array) $rows as $row) {
            $audit = json_decode($row['audit_data'], true);
            $suggestion = json_decode($row['suggestion_data'], true);
            $ai = !empty($suggestion['ai']) && is_array($suggestion['ai']) ? $suggestion['ai'] : array();
            $hierarchy = !empty($ai['font_category_hierarchy']) && is_array($ai['font_category_hierarchy']) ? $ai['font_category_hierarchy'] : array();
            fputcsv($out, array(
                (int) $row['id'],
                (int) $row['post_id'],
                get_the_title((int) $row['post_id']),
                (string) $row['status'],
                isset($ai['confidence']) ? (float) $ai['confidence'] : '',
                implode(', ', (array) ($hierarchy['designer_names'] ?? array())),
                (string) ($hierarchy['foundry_name'] ?? ''),
                (string) ($hierarchy['font_style_name'] ?? ''),
                implode(', ', (array) ($hierarchy['font_mood_names'] ?? array())),
                implode(', ', (array) ($hierarchy['font_use_case_names'] ?? array())),
                implode(', ', (array) ($audit['font_hierarchy_missing'] ?? array())),
                $this->summarize_ai_evidence((array) ($ai['evidence'] ?? array())),
                (string) $row['created_at'],
            ));
        }

        fclose($out);
        exit;
    }

    public function handle_import_suggestions_csv() {
        $this->require_admin_request();
        if (empty($_FILES['kaco_csv_file']['tmp_name'])) {
            $this->redirect_with_notice('No CSV file uploaded.', 'suggestions');
        }

        $fh = fopen($_FILES['kaco_csv_file']['tmp_name'], 'r');
        if (!$fh) {
            $this->redirect_with_notice('CSV file could not be read.', 'suggestions');
        }

        $header = fgetcsv($fh);
        $done = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $data = array_combine($header, $row);
            if (empty($data['suggestion_id']) || empty($data['action'])) {
                continue;
            }
            $suggestion = $this->get_suggestion((int) $data['suggestion_id']);
            if (!$suggestion) {
                continue;
            }
            $action = sanitize_key((string) $data['action']);
            if ($action === 'reject') {
                $done += $this->reject_suggestion_row($suggestion) ? 1 : 0;
            } elseif ($action === 'apply') {
                $done += $this->apply_suggestion_row($suggestion) ? 1 : 0;
            } elseif ($action === 'generate_ai') {
                $done += $this->generate_ai_for_row($suggestion) ? 1 : 0;
            }
        }
        fclose($fh);
        $this->redirect_with_notice("CSV import complete. Updated: {$done}.", 'suggestions');
    }

    public function handle_generate_ai_suggestion() {
        $this->require_admin_request();
        $suggestion_id = (int) ($_POST['suggestion_id'] ?? 0);
        $row = $this->get_suggestion($suggestion_id);

        if (!$row || !in_array($row['status'], array('pending', 'needs_review', 'approved'), true)) {
            $this->redirect_with_notice('Suggestion not found or not eligible for AI generation.', 'suggestions');
        }

        $ok = $this->generate_ai_for_row($row);
        if (!$ok) {
            $this->redirect_with_notice('AI generation failed. Check API settings and retry.', 'suggestions');
        }

        $this->redirect_with_notice("AI generated for suggestion #{$suggestion_id}.", 'suggestions');
    }

    public function handle_generate_category_ai() {
        $this->require_admin_request();

        $term_id = (int) ($_POST['term_id'] ?? 0);
        $term = get_term($term_id, 'category');
        if (!$term || is_wp_error($term)) {
            $this->redirect_with_notice('Category not found.', 'categories');
        }

        $draft = $this->request_category_description_ai($term);
        if (!$draft) {
            $this->redirect_with_notice('Category draft generation failed. Check API settings and retry.', 'categories');
        }

        $suggestions = $this->get_term_suggestions();
        $suggestions[$term_id] = array(
            'taxonomy' => 'category',
            'term_id' => $term_id,
            'term_name' => (string) $term->name,
            'original_description' => (string) $term->description,
            'description' => (string) $draft,
            'updated_at' => current_time('mysql', true),
        );
        update_option('kaco_term_suggestions', $suggestions, false);

        $this->redirect_with_notice('Category draft generated.', 'categories');
    }

    public function handle_apply_category_ai() {
        $this->require_admin_request();

        $term_id = (int) ($_POST['term_id'] ?? 0);
        $suggestions = $this->get_term_suggestions();
        $draft = $suggestions[$term_id] ?? null;
        $term = get_term($term_id, 'category');

        if (!$draft || !$term || is_wp_error($term)) {
            $this->redirect_with_notice('Category draft not found.', 'categories');
        }

        $term_update = wp_update_term($term_id, 'category', array(
            'description' => wp_kses_post((string) $draft['description']),
        ));
        if (is_wp_error($term_update)) {
            $this->redirect_with_notice('Category description apply failed: ' . $term_update->get_error_message(), 'categories');
        }

        $history = $this->get_term_history();
        $history[$term_id] = array(
            'previous_description' => (string) $term->description,
            'applied_description' => (string) $draft['description'],
            'updated_at' => current_time('mysql', true),
        );
        update_option('kaco_term_history', $history, false);

        unset($suggestions[$term_id]);
        update_option('kaco_term_suggestions', $suggestions, false);

        $this->redirect_with_notice('Category description applied.', 'categories');
    }

    public function handle_rollback_category_ai() {
        $this->require_admin_request();
        $term_id = (int) ($_POST['term_id'] ?? 0);
        $history = $this->get_term_history();
        $item = $history[$term_id] ?? null;
        $term = get_term($term_id, 'category');
        if (!$item || !$term || is_wp_error($term)) {
            $this->redirect_with_notice('Category rollback data not found.', 'categories');
        }

        wp_update_term($term_id, 'category', array(
            'description' => wp_kses_post((string) ($item['previous_description'] ?? '')),
        ));
        unset($history[$term_id]);
        update_option('kaco_term_history', $history, false);
        $this->redirect_with_notice('Category description rolled back.', 'categories');
    }

    public function handle_preview_tag_merge() {
        $this->require_admin_request();
        $plan = $this->build_tag_merge_plan_from_request();
        if (!$plan) {
            $this->redirect_with_notice('Tag merge preview could not be prepared.', 'tags');
        }
        $message = 'Preview: keep "' . $plan['keep_name'] . '" and merge ' . implode(', ', $plan['merged_names']) . '. Posts affected: ' . count($plan['posts']);
        $this->redirect_with_notice($message, 'tags');
    }

    public function handle_export_tag_merge_csv() {
        $this->require_admin_request();
        $plan = $this->build_tag_merge_plan_from_request();
        if (!$plan) {
            $this->redirect_with_notice('Tag merge export could not be prepared.', 'tags');
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=kaco-tag-merge-preview-' . gmdate('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('keep_tag', 'merge_tag', 'affected_post_id', 'affected_post_title'));
        foreach ($plan['posts'] as $post_id => $merge_names) {
            foreach ((array) $merge_names as $merge_name) {
                fputcsv($out, array($plan['keep_name'], $merge_name, (int) $post_id, get_the_title((int) $post_id)));
            }
        }
        fclose($out);
        exit;
    }

    public function handle_apply_tag_merge() {
        $this->require_admin_request();
        $plan = $this->build_tag_merge_plan_from_request();
        if (!$plan) {
            $this->redirect_with_notice('Tag merge could not be prepared.', 'tags');
        }

        foreach ($plan['posts'] as $post_id => $merge_names) {
            $current = wp_get_post_terms((int) $post_id, 'post_tag', array('fields' => 'ids'));
            if (is_wp_error($current)) {
                continue;
            }
            $new_ids = array_values(array_unique(array_merge(array((int) $plan['keep_term_id']), array_diff(array_map('intval', $current), $plan['merge_term_ids']))));
            wp_set_post_terms((int) $post_id, $new_ids, 'post_tag', false);
        }

        $history = $this->get_tag_merge_history();
        $merge_id = uniqid('merge_', true);
        $history[$merge_id] = $plan;
        $history[$merge_id]['created_at'] = current_time('mysql', true);
        update_option('kaco_tag_merge_history', $history, false);

        foreach ($plan['merge_term_ids'] as $merge_term_id) {
            wp_delete_term((int) $merge_term_id, 'post_tag');
        }

        $this->redirect_with_notice('Tag merge applied.', 'tags');
    }

    public function handle_rollback_tag_merge() {
        $this->require_admin_request();
        $merge_id = sanitize_text_field((string) ($_POST['merge_id'] ?? ''));
        $history = $this->get_tag_merge_history();
        $plan = $history[$merge_id] ?? null;
        if (!$plan) {
            $this->redirect_with_notice('Tag merge rollback data not found.', 'tags');
        }

        $restored_ids = array();
        foreach ((array) $plan['merged_names'] as $name) {
            $term = wp_insert_term($name, 'post_tag', array('slug' => sanitize_title($name)));
            if (!is_wp_error($term) && !empty($term['term_id'])) {
                $restored_ids[$name] = (int) $term['term_id'];
            } else {
                $existing = get_term_by('slug', sanitize_title($name), 'post_tag');
                if ($existing && !is_wp_error($existing)) {
                    $restored_ids[$name] = (int) $existing->term_id;
                }
            }
        }

        foreach ((array) $plan['posts'] as $post_id => $merge_names) {
            $current = wp_get_post_terms((int) $post_id, 'post_tag', array('fields' => 'ids'));
            if (is_wp_error($current)) {
                continue;
            }
            $new_ids = array_map('intval', $current);
            foreach ((array) $merge_names as $name) {
                if (!empty($restored_ids[$name])) {
                    $new_ids[] = (int) $restored_ids[$name];
                }
            }
            wp_set_post_terms((int) $post_id, array_values(array_unique($new_ids)), 'post_tag', false);
        }

        unset($history[$merge_id]);
        update_option('kaco_tag_merge_history', $history, false);
        $this->redirect_with_notice('Tag merge rolled back.', 'tags');
    }

    public function handle_apply_suggestion() {
        $this->require_admin_request();

        $suggestion_id = (int) ($_POST['suggestion_id'] ?? 0);
        $row = $this->get_suggestion($suggestion_id);

        if (!$row || $row['status'] !== 'approved') {
            $this->redirect_with_notice('Suggestion must be approved before apply.', 'suggestions');
        }

        $ok = $this->apply_suggestion_row($row);
        if (is_wp_error($ok)) {
            $this->redirect_with_notice('Suggestion could not be applied: ' . $ok->get_error_message(), 'suggestions');
        }
        if ($ok !== true) {
            $this->redirect_with_notice('Suggestion could not be applied. Check AI confidence or payload completeness.', 'suggestions');
        }

        $this->redirect_with_notice("Suggestion #{$suggestion_id} applied.", 'suggestions');
    }

    private function apply_suggestion_row($row) {
        $suggestion_id = (int) $row['id'];

        $post = get_post((int) $row['post_id']);
        if (!$post) {
            return new WP_Error('missing_post', 'The target post no longer exists.');
        }

        $suggestion = json_decode($row['suggestion_data'], true);
        $append = isset($suggestion['append_template']) ? (string) $suggestion['append_template'] : '';
        $ai = isset($suggestion['ai']) && is_array($suggestion['ai']) ? $suggestion['ai'] : array();
        if (!empty($ai) && !$this->passes_ai_confidence($ai)) {
            return new WP_Error('low_confidence', 'AI confidence is below the configured threshold.');
        }

        $new_content = (string) $post->post_content;
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
        $new_content = $this->rewrite_existing_post_content($new_content, $rendered_template, $append, $rewrite_mode);

        $font_link_terms = array();
        if (!empty($ai['font_category_hierarchy']) && is_array($ai['font_category_hierarchy'])) {
            $font_link_terms = $this->apply_font_category_hierarchy((int) $post->ID, $ai['font_category_hierarchy']);
        }

        if (!empty($ai['term_descriptions']) && is_array($ai['term_descriptions'])) {
            $term_update_result = $this->apply_term_description_updates($ai['term_descriptions']);
            if (is_wp_error($term_update_result)) {
                return $term_update_result;
            }
        }

        $font_links_block = $this->build_font_category_links_block($font_link_terms);
        if ($font_links_block !== '' && strpos($new_content, trim($font_links_block)) === false) {
            $new_content .= "\n\n" . $font_links_block;
        }
        $new_content = $this->relink_font_mentions_to_internal_categories($new_content, $font_link_terms);

        $post_update = array(
            'ID' => (int) $post->ID,
            'post_content' => $new_content,
        );

        if ($this->title_regenerator_enabled()) {
            $proposed_title = $this->sanitize_regenerated_title((string) ($ai['title'] ?? ''), (string) $post->post_title);
            if ($proposed_title !== '') {
                $post_update['post_title'] = $proposed_title;
            }
        }

        if (!empty($ai['excerpt'])) {
            $post_update['post_excerpt'] = (string) $ai['excerpt'];
        }

        $updated_post_id = wp_update_post($post_update, true);
        if (is_wp_error($updated_post_id) || (int) $updated_post_id <= 0) {
            return is_wp_error($updated_post_id) ? $updated_post_id : new WP_Error('post_update_failed', 'Post update failed.');
        }

        if (!empty($post_update['post_excerpt'])) {
            update_post_meta((int) $post->ID, 'kreativ-page-summary', (string) $post_update['post_excerpt']);
        }

        $this->update_suggestion_status($suggestion_id, 'applied', get_current_user_id());
        return true;
    }

    public function handle_approve_suggestion() {
        $this->require_admin_request();

        $suggestion_id = (int) ($_POST['suggestion_id'] ?? 0);
        $row = $this->get_suggestion($suggestion_id);
        if (!$row || !in_array($row['status'], array('pending', 'needs_review'), true)) {
            $this->redirect_with_notice('Suggestion not found or not approvable.', 'suggestions');
        }

        $ok = $this->approve_suggestion_row($row);
        if (!$ok) {
            $this->redirect_with_notice('Suggestion could not be approved.', 'suggestions');
        }

        $this->redirect_with_notice("Suggestion #{$suggestion_id} approved.", 'suggestions');
    }

    private function approve_suggestion_row($row) {
        if (!$row || !in_array($row['status'], array('pending', 'needs_review'), true)) {
            return false;
        }
        $this->update_suggestion_status((int) $row['id'], 'approved', get_current_user_id());
        return true;
    }

    public function handle_reject_suggestion() {
        $this->require_admin_request();

        $suggestion_id = (int) ($_POST['suggestion_id'] ?? 0);
        $row = $this->get_suggestion($suggestion_id);

        if (!$row || $row['status'] !== 'pending') {
            $this->redirect_with_notice('Suggestion not found or not pending.', 'suggestions');
        }

        $this->reject_suggestion_row($row);
        $this->redirect_with_notice("Suggestion #{$suggestion_id} rejected.", 'suggestions');
    }

    private function reject_suggestion_row($row) {
        if (!$row || !in_array($row['status'], array('pending', 'needs_review'), true)) {
            return false;
        }
        $this->update_suggestion_status((int) $row['id'], 'rejected', get_current_user_id());
        return true;
    }

    public function handle_rollback_suggestion() {
        $this->require_admin_request();

        $suggestion_id = (int) ($_POST['suggestion_id'] ?? 0);
        $row = $this->get_suggestion($suggestion_id);

        if (!$row || $row['status'] !== 'applied') {
            $this->redirect_with_notice('Suggestion not found or not applied.', 'suggestions');
        }

        $snapshot = json_decode($row['original_snapshot'], true);
        $post_id = (int) $row['post_id'];

        if (empty($snapshot)) {
            $this->redirect_with_notice('Rollback snapshot is missing.', 'suggestions');
        }

        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => (string) ($snapshot['post_title'] ?? ''),
            'post_excerpt' => (string) ($snapshot['post_excerpt'] ?? ''),
            'post_content' => (string) ($snapshot['post_content'] ?? ''),
        ));

        if (!empty($snapshot['terms']) && is_array($snapshot['terms'])) {
            $this->restore_post_terms($post_id, $snapshot['terms']);
        }
        if (!empty($snapshot['term_descriptions']) && is_array($snapshot['term_descriptions'])) {
            $this->restore_term_descriptions($snapshot['term_descriptions']);
        }

        $this->update_suggestion_status($suggestion_id, 'rolled_back', get_current_user_id());
        $this->redirect_with_notice("Suggestion #{$suggestion_id} rolled back.", 'suggestions');
    }

    private function get_term_suggestions() {
        $value = get_option('kaco_term_suggestions', array());
        return is_array($value) ? $value : array();
    }

    private function get_term_history() {
        $value = get_option('kaco_term_history', array());
        return is_array($value) ? $value : array();
    }

    private function get_tag_merge_history() {
        $value = get_option('kaco_tag_merge_history', array());
        return is_array($value) ? $value : array();
    }

    private function get_hierarchy_cleanup_plan() {
        $value = get_option('kaco_hierarchy_cleanup_plan', array());
        return is_array($value) ? $value : array();
    }

    private function get_hierarchy_cleanup_history() {
        $value = get_option('kaco_hierarchy_cleanup_history', array());
        return is_array($value) ? $value : array();
    }

    private function get_automation_logs() {
        $value = get_option('kaco_automation_logs', array());
        return is_array($value) ? array_values($value) : array();
    }

    private function set_automation_logs($logs) {
        $logs = is_array($logs) ? array_values($logs) : array();
        update_option('kaco_automation_logs', array_slice($logs, 0, 400), false);
    }

    private function append_automation_log($entry) {
        $logs = $this->get_automation_logs();
        $normalized = array(
            'logged_at' => current_time('mysql', true),
            'lane' => sanitize_key((string) ($entry['lane'] ?? 'automation')),
            'action' => sanitize_key((string) ($entry['action'] ?? 'event')),
            'status' => sanitize_key((string) ($entry['status'] ?? 'info')),
            'message' => sanitize_text_field((string) ($entry['message'] ?? '')),
            'title' => sanitize_text_field((string) ($entry['title'] ?? '')),
            'url' => esc_url_raw((string) ($entry['url'] ?? '')),
            'suggestion_id' => (int) ($entry['suggestion_id'] ?? 0),
            'post_id' => (int) ($entry['post_id'] ?? 0),
            'confidence' => isset($entry['confidence']) ? round((float) $entry['confidence'], 2) : null,
        );
        array_unshift($logs, $normalized);
        $this->set_automation_logs($logs);
    }

    private function insert_suggestion($post_id, $audit, $suggestion, $snapshot) {
        global $wpdb;
        $now = current_time('mysql', true);

        $ok = $wpdb->insert(
            $this->table_name(),
            array(
                'post_id' => (int) $post_id,
                'status' => 'pending',
                'audit_data' => wp_json_encode($audit),
                'suggestion_data' => wp_json_encode($suggestion),
                'original_snapshot' => wp_json_encode($snapshot),
                'created_by' => (int) get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    private function get_suggestion($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name() . ' WHERE id = %d', $id), ARRAY_A);
    }

    private function update_suggestion_status($id, $status, $user_id) {
        global $wpdb;

        $wpdb->update(
            $this->table_name(),
            array(
                'status' => $status,
                'approved_by' => (int) $user_id,
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => (int) $id),
            array('%s', '%d', '%s'),
            array('%d')
        );
    }

    private function update_suggestion_payload($id, $suggestion) {
        global $wpdb;
        $wpdb->update(
            $this->table_name(),
            array(
                'suggestion_data' => wp_json_encode($suggestion),
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => (int) $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    private function has_active_suggestion($post_id) {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $this->table_name() . " WHERE post_id = %d AND status IN ('pending','needs_review','approved')", $post_id));
        return $count > 0;
    }

    private function process_automation_suggestions($ids) {
        $auto_generate = get_option('kaco_automation_auto_generate_ai', '1') === '1';
        $auto_approve = get_option('kaco_automation_auto_approve', '1') === '1';
        $auto_apply = get_option('kaco_automation_auto_apply', '0') === '1';
        $approve_confidence = min(1, max(0, (float) get_option('kaco_automation_approve_confidence', 0.85)));
        $apply_confidence = min(1, max(0, (float) get_option('kaco_automation_apply_confidence', 0.93)));
        $result = array(
            'generated' => 0,
            'approved' => 0,
            'applied' => 0,
            'failed' => 0,
        );

        foreach ((array) $ids as $id) {
            $row = $this->get_suggestion((int) $id);
            if (!$row) {
                $this->append_automation_log(array(
                    'lane' => 'optimizer',
                    'action' => 'load_suggestion',
                    'status' => 'failed',
                    'suggestion_id' => (int) $id,
                    'message' => 'Suggestion row no longer exists.',
                ));
                $result['failed']++;
                continue;
            }

            if ($auto_generate) {
                $ok = $this->generate_ai_for_row($row);
                if (!$ok) {
                    $this->append_automation_log(array(
                        'lane' => 'optimizer',
                        'action' => 'generate_ai',
                        'status' => 'failed',
                        'suggestion_id' => (int) $row['id'],
                        'post_id' => (int) $row['post_id'],
                        'message' => 'AI generation failed during automation.',
                    ));
                    $result['failed']++;
                    continue;
                }
                $result['generated']++;
                $this->append_automation_log(array(
                    'lane' => 'optimizer',
                    'action' => 'generate_ai',
                    'status' => 'success',
                    'suggestion_id' => (int) $row['id'],
                    'post_id' => (int) $row['post_id'],
                    'message' => 'AI suggestion generated.',
                ));
                $row = $this->get_suggestion((int) $id);
                if (!$row) {
                    $this->append_automation_log(array(
                        'lane' => 'optimizer',
                        'action' => 'reload_suggestion',
                        'status' => 'failed',
                        'suggestion_id' => (int) $id,
                        'message' => 'Suggestion row disappeared after AI generation.',
                    ));
                    $result['failed']++;
                    continue;
                }
            }

            $suggestion = json_decode((string) $row['suggestion_data'], true);
            $ai = !empty($suggestion['ai']) && is_array($suggestion['ai']) ? $suggestion['ai'] : array();
            $confidence = isset($ai['confidence']) ? (float) $ai['confidence'] : 0.0;

            if ($auto_approve && in_array($row['status'], array('pending', 'needs_review'), true)) {
                if ($confidence < $approve_confidence) {
                    $this->append_automation_log(array(
                        'lane' => 'optimizer',
                        'action' => 'approve',
                        'status' => 'needs_review',
                        'suggestion_id' => (int) $row['id'],
                        'post_id' => (int) $row['post_id'],
                        'confidence' => $confidence,
                        'message' => 'Suggestion stayed in review because confidence is below the auto-approve threshold.',
                    ));
                } elseif ($this->approve_suggestion_row($row)) {
                    $result['approved']++;
                    $this->append_automation_log(array(
                        'lane' => 'optimizer',
                        'action' => 'approve',
                        'status' => 'success',
                        'suggestion_id' => (int) $row['id'],
                        'post_id' => (int) $row['post_id'],
                        'confidence' => $confidence,
                        'message' => 'Suggestion auto-approved.',
                    ));
                    $row = $this->get_suggestion((int) $id);
                } else {
                    $this->append_automation_log(array(
                        'lane' => 'optimizer',
                        'action' => 'approve',
                        'status' => 'failed',
                        'suggestion_id' => (int) $row['id'],
                        'post_id' => (int) $row['post_id'],
                        'confidence' => $confidence,
                        'message' => 'Suggestion could not be auto-approved.',
                    ));
                    $result['failed']++;
                }
            }

            if (!$auto_apply || !$row || $row['status'] !== 'approved') {
                continue;
            }

            if ($confidence < $apply_confidence) {
                $this->append_automation_log(array(
                    'lane' => 'optimizer',
                    'action' => 'apply',
                    'status' => 'skipped',
                    'suggestion_id' => (int) $row['id'],
                    'post_id' => (int) $row['post_id'],
                    'confidence' => $confidence,
                    'message' => 'Suggestion stayed approved because confidence is below the auto-apply threshold.',
                ));
                continue;
            }

            $applied = $this->apply_suggestion_row($row);
            if ($applied === true) {
                $result['applied']++;
                $this->append_automation_log(array(
                    'lane' => 'optimizer',
                    'action' => 'apply',
                    'status' => 'success',
                    'suggestion_id' => (int) $row['id'],
                    'post_id' => (int) $row['post_id'],
                    'confidence' => $confidence,
                    'message' => 'Approved suggestion auto-applied.',
                ));
            } else {
                $this->append_automation_log(array(
                    'lane' => 'optimizer',
                    'action' => 'apply',
                    'status' => 'failed',
                    'suggestion_id' => (int) $row['id'],
                    'post_id' => (int) $row['post_id'],
                    'confidence' => $confidence,
                    'message' => is_wp_error($applied) ? $applied->get_error_message() : 'Suggestion could not be auto-applied.',
                ));
                $result['failed']++;
            }
        }

        return $result;
    }

    private function require_admin_request() {
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions.');
        }

        check_admin_referer(self::NONCE_ACTION);
    }

    private function redirect_with_notice($message, $view) {
        $url = add_query_arg(
            array(
                'page' => 'kaco-dashboard',
                'view' => $view,
                'kaco_notice' => rawurlencode($message),
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }
}
