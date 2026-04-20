<?php

trait KACO_Admin_UI_Trait {
    private function tab_link($slug, $label, $current) {
        $grouped = array(
            'dashboard' => array('dashboard'),
            'create' => array('create', 'generator'),
            'refresh' => array('refresh', 'audit'),
            'review' => array('review', 'exceptions', 'suggestions'),
            'settings' => array('settings'),
        );
        $is_active = in_array($current, $grouped[$slug] ?? array($slug), true);
        $class = $is_active ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = admin_url('admin.php?page=kaco-dashboard&view=' . $slug);
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private function render_dashboard_view() {
        global $wpdb;

        $table = $this->table_name();
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
        $needs_review = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'needs_review'");
        $approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'approved'");
        $applied = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'applied'");
        $new_font_review = count($this->get_generator_automation_review());
        $url_inbox = count($this->get_generator_url_inbox());
        $next_queue_run = (int) wp_next_scheduled('kaco_generator_queue_event');
        $logs = $this->get_automation_logs();
        $failed_logs = count(array_filter((array) $logs, function($item) {
            return in_array((string) ($item['status'] ?? ''), array('failed', 'needs_review'), true);
        }));
        $automation_last_run = get_option('kaco_automation_last_run', array());

        echo '<h2>Operations dashboard</h2>';
        echo '<p>Start from one of the three primary actions below. Everything else in the plugin is support or maintenance.</p>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin:20px 0;">';
        $this->render_primary_action_card(
            'Add New Fonts',
            'Use New Fonts when you have marketplace URLs. Add them to the queue, process the queue, then review or create posts.',
            'Open New Fonts',
            admin_url('admin.php?page=kaco-dashboard&view=create')
        );
        $this->render_primary_action_card(
            'Review Problems',
            'Use Problems when refresh suggestions need review or automation failures need investigation.',
            'Open Problems',
            admin_url('admin.php?page=kaco-dashboard&view=review')
        );
        $this->render_primary_action_card(
            'Run Refresh',
            'Use Refresh Existing to scan older posts and create improvement suggestions. It does not rewrite content immediately.',
            'Open Refresh Existing',
            admin_url('admin.php?page=kaco-dashboard&view=refresh')
        );
        echo '</div>';

        echo '<div style="margin:16px 0 22px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=create')) . '">Add New Fonts</a>';
        if ($url_inbox > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0;">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="kaco_process_generator_queue_now" />';
            submit_button('Process Queue Now', 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=review')) . '">Open Problems</a>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=refresh')) . '">Run Refresh</a>';
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:20px 0;">';
        $this->render_dashboard_card('New Fonts', 'Queue waiting', (string) $url_inbox, 'Open New Fonts', admin_url('admin.php?page=kaco-dashboard&view=create'));
        $this->render_dashboard_card('New Fonts', 'Needs review', (string) $new_font_review, 'Open New Fonts', admin_url('admin.php?page=kaco-dashboard&view=create'));
        $this->render_dashboard_card('Problems', 'Suggestions needing review', (string) $needs_review, 'Open Problems', admin_url('admin.php?page=kaco-dashboard&view=review'));
        $this->render_dashboard_card('Problems', 'Approved and ready to apply', (string) $approved, 'Open Problems', admin_url('admin.php?page=kaco-dashboard&view=review'));
        $this->render_dashboard_card('Refresh Existing', 'Pending suggestions', (string) $pending, 'Open Refresh Existing', admin_url('admin.php?page=kaco-dashboard&view=refresh'));
        echo '</div>';

        echo '<h3>Current state</h3>';
        echo '<ul>';
        echo '<li><strong>New Fonts:</strong> ' . (int) $url_inbox . ' URL(s) waiting, ' . (int) $new_font_review . ' item(s) need review' . ($next_queue_run > 0 ? ', next queue pass ' . esc_html(wp_date('Y-m-d H:i', $next_queue_run)) : '') . '.</li>';
        echo '<li><strong>Refresh Existing:</strong> ' . (int) $pending . ' pending, ' . (int) $needs_review . ' need review, ' . (int) $approved . ' ready to apply, ' . (int) $applied . ' applied.</li>';
        echo '<li><strong>Problems:</strong> ' . (int) $failed_logs . ' recent failure log item(s).</li>';
        echo '</ul>';

        if (!empty($automation_last_run) && is_array($automation_last_run)) {
            echo '<h3>Last automation run</h3>';
            echo '<p>';
            echo 'Ran: ' . esc_html((string) ($automation_last_run['ran_at'] ?? '-'));
            echo ' | Scanned: ' . (int) ($automation_last_run['scanned'] ?? 0);
            echo ' | Matched: ' . (int) ($automation_last_run['matched'] ?? 0);
            echo ' | Queued: ' . (int) ($automation_last_run['queued'] ?? 0);
            if (!empty($automation_last_run['automation']) && is_array($automation_last_run['automation'])) {
                echo ' | AI generated: ' . (int) ($automation_last_run['automation']['generated'] ?? 0);
                echo ' | Auto-approved: ' . (int) ($automation_last_run['automation']['approved'] ?? 0);
                echo ' | Auto-applied: ' . (int) ($automation_last_run['automation']['applied'] ?? 0);
                echo ' | Failed: ' . (int) ($automation_last_run['automation']['failed'] ?? 0);
            }
            if (!empty($automation_last_run['generator_inbox']) && is_array($automation_last_run['generator_inbox'])) {
                echo ' | Queue processed: ' . (int) ($automation_last_run['generator_inbox']['processed'] ?? 0);
                echo ' | Posts created: ' . (int) ($automation_last_run['generator_inbox']['created'] ?? 0);
            }
            echo '</p>';
        }

        echo '<h3>Maintenance</h3>';
        echo '<ul>';
        echo '<li><strong>Settings:</strong> change thresholds, automation behavior, category mapping, and diagnostics only when you intend to alter operating mode.</li>';
        echo '</ul>';
    }

    private function render_dashboard_card($lane, $label, $value, $action_label, $action_url) {
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;">';
        echo '<div style="font-size:12px;text-transform:uppercase;color:#50575e;margin-bottom:6px;">' . esc_html($lane) . '</div>';
        echo '<div style="font-size:14px;color:#1d2327;margin-bottom:6px;">' . esc_html($label) . '</div>';
        echo '<div style="font-size:28px;font-weight:600;margin-bottom:10px;">' . esc_html($value) . '</div>';
        echo '<a href="' . esc_url($action_url) . '">' . esc_html($action_label) . '</a>';
        echo '</div>';
    }

    private function render_primary_action_card($title, $description, $action_label, $action_url) {
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;">';
        echo '<h3 style="margin-top:0;">' . esc_html($title) . '</h3>';
        echo '<p style="min-height:54px;">' . esc_html($description) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($action_url) . '">' . esc_html($action_label) . '</a></p>';
        echo '</div>';
    }

    private function render_refresh_view() {
        echo '<h2>Refresh existing posts</h2>';
        echo '<p>Use this lane for older content. Run audits, generate suggestions, then resolve or apply them. Automation status is shown below so you can see whether refresh work is moving without manual intervention.</p>';
        $this->render_audit_view();

        $automation_last_run = get_option('kaco_automation_last_run', array());
        if (!empty($automation_last_run) && is_array($automation_last_run)) {
            echo '<hr style="margin:24px 0;" />';
            echo '<h3>Automation status</h3>';
            echo '<p>';
            echo 'Ran: ' . esc_html((string) ($automation_last_run['ran_at'] ?? '-'));
            echo ' | Scanned: ' . (int) ($automation_last_run['scanned'] ?? 0);
            echo ' | Matched: ' . (int) ($automation_last_run['matched'] ?? 0);
            echo ' | Queued: ' . (int) ($automation_last_run['queued'] ?? 0);
            if (!empty($automation_last_run['automation']) && is_array($automation_last_run['automation'])) {
                echo ' | AI generated: ' . (int) ($automation_last_run['automation']['generated'] ?? 0);
                echo ' | Auto-approved: ' . (int) ($automation_last_run['automation']['approved'] ?? 0);
                echo ' | Auto-applied: ' . (int) ($automation_last_run['automation']['applied'] ?? 0);
                echo ' | Failed: ' . (int) ($automation_last_run['automation']['failed'] ?? 0);
            }
            echo '</p>';
        }
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=review')) . '">Open Problems</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_run_refresh_automation_now" />';
        submit_button('Run Refresh Automation Now', 'secondary', 'submit', false);
        echo '</form>';
        echo '<p class="description">Use this when you want the refresh lane processed immediately instead of waiting for WP-Cron.</p>';
    }

    private function render_review_view() {
        global $wpdb;
        $table = $this->table_name();
        $review_filter = isset($_GET['review_filter']) ? sanitize_key((string) $_GET['review_filter']) : 'all';
        $needs_review_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'needs_review'");
        $approved_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'approved'");
        $failed_logs_count = count(array_filter((array) $this->get_automation_logs(), function($item) {
            return (string) ($item['status'] ?? '') === 'failed';
        }));

        echo '<h2>Problems</h2>';
        echo '<p>This lane is for refresh triage and operational failures. Handle uncertain old-post suggestions first, then apply approved updates, then inspect failures if something looks wrong.</p>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:18px 0;">';
        $this->render_dashboard_card('Needs review', 'Old-post suggestions', (string) $needs_review_count, 'Open section', admin_url('admin.php?page=kaco-dashboard&view=review&review_filter=needs_review'));
        $this->render_dashboard_card('Ready to apply', 'Approved suggestions', (string) $approved_count, 'Open section', admin_url('admin.php?page=kaco-dashboard&view=review&review_filter=approved'));
        $this->render_dashboard_card('Failures', 'Automation failures', (string) $failed_logs_count, 'Open section', admin_url('admin.php?page=kaco-dashboard&view=review&review_filter=failed'));
        echo '</div>';

        echo '<p><strong>View:</strong> ';
        foreach (array(
            'all' => 'All',
            'needs_review' => 'Needs Review (' . $needs_review_count . ')',
            'approved' => 'Ready To Apply (' . $approved_count . ')',
            'failed' => 'Failures (' . $failed_logs_count . ')',
        ) as $key => $label) {
            $url = admin_url('admin.php?page=kaco-dashboard&view=review&review_filter=' . $key);
            echo '<a href="' . esc_url($url) . '" style="margin-right:10px;">' . esc_html($label) . '</a>';
        }
        echo '</p>';
        if ($review_filter === 'all' || $review_filter === 'needs_review' || $review_filter === 'failed') {
            $this->render_exceptions_view();
        }
        if ($review_filter === 'all' || $review_filter === 'approved') {
            echo '<hr style="margin:24px 0;" />';
            $this->render_suggestions_view();
        }
    }

    private function render_taxonomy_view() {
        echo '<h2>Taxonomy maintenance</h2>';
        echo '<p>Use this lane for archive health, not daily publishing. It groups hierarchy cleanup, category descriptions, and tag hygiene in one place.</p>';
        $this->render_taxonomy_health_summary();
        echo '<hr style="margin:24px 0;" />';
        $this->render_hierarchy_cleanup_view();
        echo '<hr style="margin:24px 0;" />';
        $this->render_duplicate_category_audit_view();
        echo '<hr style="margin:24px 0;" />';
        $this->render_categories_view();
        echo '<hr style="margin:24px 0;" />';
        $this->render_tags_view();
    }

    private function render_taxonomy_health_summary() {
        $summary = $this->build_taxonomy_health_snapshot();
        $cards = array(
            'Hierarchy rows' => (int) ($summary['hierarchy_rows'] ?? 0),
            'Category description gaps' => (int) ($summary['category_description_gaps'] ?? 0),
            'Duplicate category groups' => (int) ($summary['duplicate_category_groups'] ?? 0),
            'Duplicate tag groups' => (int) ($summary['duplicate_tag_groups'] ?? 0),
            'Tag/category overlaps' => (int) ($summary['category_overlap_tags'] ?? 0),
            'Over-tagged posts' => (int) ($summary['over_tagged_posts'] ?? 0),
        );

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">';
        foreach ($cards as $label => $value) {
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:14px;">';
            echo '<div style="font-size:12px;color:#50575e;text-transform:uppercase;letter-spacing:0.04em;">' . esc_html($label) . '</div>';
            echo '<div style="font-size:28px;font-weight:600;line-height:1.2;margin-top:6px;">' . (int) $value . '</div>';
            echo '</div>';
        }
        echo '</div>';
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
                    echo '<li>' . esc_html((string) ($row['title'] ?? ('Post #' . (int) ($row['post_id'] ?? 0)))) . ' | priority ' . (int) ($row['priority_score'] ?? 0) . ' | action: ' . esc_html((string) ($row['suggested_action'] ?? 'Manual review')) . ' | ' . esc_html(implode(', ', (array) ($row['reason_badges'] ?? array()))) . ' | ' . esc_html((string) ($row['current_hierarchy_preview'] ?? '')) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_run_audit" />';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_audit_preset">Audit preset</label></th>';
        echo '<td><select id="kaco_audit_preset" name="kaco_audit_preset">';
        foreach (array(
            'custom' => 'Custom',
            'missing_hierarchy' => 'Missing hierarchy',
            'thin' => 'Thin content',
            'stale' => 'Stale content',
            'high_priority' => 'High priority only',
        ) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select> <p class="description">Use a preset for fast audits, or keep Custom to use the form values directly.</p></td></tr>';
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
        $automation_previews = $this->get_generator_automation_review();
        $inbox = $this->get_generator_url_inbox();
        $automation_enabled = get_option('kaco_automation_enabled', '0') === '1';
        $automation_process_inbox = get_option('kaco_automation_process_url_inbox', '1') === '1';
        $queue_urls_per_run = min(25, max(1, (int) get_option('kaco_automation_queue_urls_per_run', 1)));
        $queue_delay_minutes = min(240, max(1, (int) get_option('kaco_automation_queue_delay_minutes', 10)));
        $last_run = get_option('kaco_automation_last_run', array());
        $last_queue_summary = !empty($last_run['generator_inbox']) && is_array($last_run['generator_inbox']) ? (array) $last_run['generator_inbox'] : array();
        $queue_activity = $this->recent_new_font_queue_activity(12);

        echo '<h2>New Fonts</h2>';
        echo '<p>Add marketplace URLs to the new-font queue, process the queue in paced runs, then review or create posts from the results.</p>';

        echo '<div style="display:grid;grid-template-columns:minmax(0,1fr);gap:24px;">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;">';
        echo '<h3 style="margin-top:0;">Marketplace URLs</h3>';
        echo '<p class="description" style="margin-top:0;">Paste marketplace URLs here to add them to the queue. The queue is the only generation path for new posts.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_generator_urls">Marketplace URLs</label></th>';
        echo '<td><textarea id="kaco_generator_urls" name="kaco_generator_urls" rows="8" cols="100" class="large-text code" placeholder="https://www.myfonts.com/...&#10;https://creativemarket.com/..."></textarea>';
        echo '<p class="description">One URL per line. Add them to the queue, then use <strong>Process Queue Now</strong> or let scheduled automation process them.</p></td></tr>';
        echo '</tbody></table>';
        if ($automation_enabled && $automation_process_inbox) {
            echo '<div style="background:#f6f7f7;border-left:4px solid #2271b1;padding:10px 12px;">';
            echo '<strong>New Font Queue</strong> <span style="color:#2271b1;">Primary workflow</span><br/>';
            echo 'Queued URLs are processed in paced runs. This install is currently set to <strong>' . (int) $queue_urls_per_run . ' URL(s)</strong> per pass with a <strong>' . (int) $queue_delay_minutes . '-minute</strong> delay between follow-up runs. Strong items become posts automatically; weaker or failed items stay here for review.';
            echo '</div>';
        } else {
            echo '<div style="background:#fff8e5;border-left:4px solid #dba617;padding:10px 12px;">';
            echo '<strong>Queue processing is disabled</strong><br/>Enable automation and queue processing in Settings before adding URLs here.';
            echo '</div>';
        }
        echo '<p style="margin-top:12px;">';
        echo '<button type="submit" name="action" value="kaco_add_generator_urls_to_inbox" class="button button-primary"' . ($automation_enabled && $automation_process_inbox ? '' : ' disabled="disabled"') . '>Add To New Font Queue</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';

        if ($automation_enabled && $automation_process_inbox) {
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;">';
            echo '<h3 style="margin-top:0;">New Font Queue</h3>';
            echo '<p><strong>Queue status:</strong> ' . count($inbox) . ' URL(s) waiting';
            if (!empty($inbox)) {
                echo '<br/>' . esc_html(implode(' | ', array_slice($inbox, 0, 5)));
                if (count($inbox) > 5) {
                    echo ' ...';
                }
            }
            if (!empty($last_queue_summary['next_queue_run_label'])) {
                echo '<br/><strong>Next queued pass:</strong> ' . esc_html((string) $last_queue_summary['next_queue_run_label']);
            }
            echo '</p>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="kaco_process_generator_queue_now" />';
            submit_button('Process Queue Now', 'secondary', 'submit', false);
            echo '</form>';
            echo '<p class="description">Use this when you want the queue processed immediately instead of waiting for WP-Cron. Each run processes only the configured queue slice, then schedules the next follow-up run if URLs remain.</p>';

            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:18px;">';
            echo '<div>';
            echo '<h4 style="margin:0 0 8px 0;">Waiting</h4>';
            if (empty($inbox)) {
                echo '<p class="description">No URLs are currently waiting in the queue.</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>State</th><th>URL</th></tr></thead><tbody>';
                foreach ((array) $inbox as $queued_url) {
                    echo '<tr><td>waiting</td><td style="word-break:break-word;">' . esc_html((string) $queued_url) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
            echo '<div>';
            echo '<h4 style="margin:0 0 8px 0;">Recent Queue Activity</h4>';
            if (empty($queue_activity)) {
                echo '<p class="description">No recent queue activity recorded yet.</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>When</th><th>State</th><th>Item</th></tr></thead><tbody>';
                foreach ($queue_activity as $entry) {
                    echo '<tr>';
                    echo '<td>' . esc_html((string) ($entry['logged_at'] ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) ($entry['state_label'] ?? '')) . '</td>';
                    echo '<td style="word-break:break-word;">' . esc_html((string) ($entry['item_label'] ?? '')) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        if (empty($automation_previews)) {
            return;
        }

        echo '<h3>Needs Review</h3>';
        echo '<p class="description">These new-font items were not auto-created. Successful items disappear automatically after creation. Leave <code>create draft</code> unchecked to keep an item for later, or use <code>remove from review queue</code> to discard it.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_create_generated_drafts" />';

        $review_previews = array_map(function($item) {
            $item['preview_source'] = 'automation';
            return $item;
        }, $automation_previews);

        foreach ($review_previews as $index => $item) {
            $can_create = empty($item['automation_error'])
                && trim((string) ($item['title'] ?? '')) !== ''
                && trim((string) ($item['content'] ?? '')) !== '';
            echo '<div style="border:1px solid #ccd0d4;padding:12px;margin:0 0 18px 0;background:#fff;">';
            echo '<p><strong>Source:</strong> queued automation</p>';
            echo '<p><strong>Source URL:</strong> ' . esc_html((string) ($item['url'] ?? '')) . '</p>';
            echo '<p><strong>Confidence:</strong> ' . esc_html(isset($item['confidence']) ? number_format((float) $item['confidence'], 2) : '0.00') . '</p>';
            if (!empty($item['evidence']) && is_array($item['evidence'])) {
                echo '<p><strong>Evidence:</strong> ' . esc_html($this->summarize_ai_evidence((array) $item['evidence'])) . '</p>';
            }
            if (!empty($item['automation_error'])) {
                echo '<p><strong>Automation note:</strong> ' . esc_html((string) $item['automation_error']) . '</p>';
            }
            if (!empty($item['diagnostics']) && (get_option('kaco_debug_mode', '0') === '1' || !empty($item['automation_error']))) {
                echo '<details style="margin:8px 0;"><summary><strong>Diagnostics</strong></summary><pre style="white-space:pre-wrap;word-break:break-word;">' . esc_html($this->format_debug_data($item['diagnostics'])) . '</pre></details>';
            }
            if (empty($item['designer_names'])) {
                echo '<p><strong>Designer status:</strong> no explicit source match found. Review before creating the draft.</p>';
            }
            echo '<input type="hidden" name="previews[' . (int) $index . '][preview_source]" value="automation" />';
            echo '<input type="hidden" name="previews[' . (int) $index . '][url]" value="' . esc_attr((string) ($item['url'] ?? '')) . '" />';
            echo '<p><label><strong>Title</strong><br/><input type="text" name="previews[' . (int) $index . '][title]" value="' . esc_attr((string) ($item['title'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Image URL</strong><br/><input type="url" name="previews[' . (int) $index . '][image_url]" value="' . esc_attr((string) ($item['image_url'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Designers</strong><br/><input type="text" name="previews[' . (int) $index . '][designer_names]" value="' . esc_attr(implode(', ', (array) ($item['designer_names'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Foundry</strong><br/><input type="text" name="previews[' . (int) $index . '][foundry_name]" value="' . esc_attr((string) ($item['foundry_name'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Font Style</strong><br/><input type="text" name="previews[' . (int) $index . '][font_style_name]" value="' . esc_attr((string) ($item['font_style_name'] ?? '')) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Font Moods</strong><br/><input type="text" name="previews[' . (int) $index . '][font_mood_names]" value="' . esc_attr(implode(', ', (array) ($item['font_mood_names'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Font Use Cases</strong><br/><input type="text" name="previews[' . (int) $index . '][font_use_case_names]" value="' . esc_attr(implode(', ', (array) ($item['font_use_case_names'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Tags</strong><br/><input type="text" name="previews[' . (int) $index . '][tags]" value="' . esc_attr(implode(', ', (array) ($item['tags'] ?? array()))) . '" class="regular-text" style="width:100%;" /></label></p>';
            echo '<p><label><strong>Page Summary</strong><br/><textarea name="previews[' . (int) $index . '][summary_excerpt]" rows="3" class="large-text">' . esc_textarea((string) ($item['summary_excerpt'] ?? '')) . '</textarea></label></p>';
            echo '<p><label><strong>Content</strong><br/><textarea name="previews[' . (int) $index . '][content]" rows="18" class="large-text code">' . esc_textarea((string) ($item['content'] ?? '')) . '</textarea></label></p>';
            echo '<p>';
            echo '<label style="margin-right:16px;"><input type="checkbox" name="previews[' . (int) $index . '][create]" value="1"' . ($can_create ? ' checked="checked"' : '') . ' /> create draft</label>';
            echo '<label><input type="checkbox" name="previews[' . (int) $index . '][discard]" value="1" /> remove from review queue</label>';
            echo '</p>';
            if (!$can_create) {
                echo '<p class="description">This item is not ready for draft creation yet. Retry generation or edit the missing fields first.</p>';
            }
            echo '</div>';
        }

        submit_button('Create Selected Drafts');
        echo '</form>';
    }

    private function render_hierarchy_cleanup_view() {
        $plan = $this->get_hierarchy_cleanup_plan();
        $rows = !empty($plan['rows']) && is_array($plan['rows']) ? $plan['rows'] : array();
        $history = $this->get_hierarchy_cleanup_history();

        echo '<h2>Hierarchy cleanup</h2>';
        echo '<p>Repair legacy font post category assignments against the current rules. This tool only uses existing category assignments and fixed-list normalization. It does not guess missing branches from AI.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_scan_hierarchy_cleanup" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_cleanup_post_type">Post type</label></th>';
        echo '<td><input type="text" id="kaco_cleanup_post_type" name="kaco_cleanup_post_type" value="post" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_cleanup_limit">Batch size</label></th>';
        echo '<td><input type="number" min="1" max="1000" id="kaco_cleanup_limit" name="kaco_cleanup_limit" value="200" /></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_cleanup_scan_all">Scan all matching posts</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_cleanup_scan_all" name="kaco_cleanup_scan_all" value="1" /> yes</label></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_cleanup_fonts_only">Fonts posts only</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_cleanup_fonts_only" name="kaco_cleanup_fonts_only" value="1" checked="checked" /> yes</label></td></tr>';
        echo '</tbody></table>';
        submit_button('Scan Hierarchy Issues');
        echo '</form>';

        if (!empty($plan)) {
            echo '<p><strong>Last scan:</strong> ' . esc_html((string) ($plan['generated_at'] ?? '-')) . ' | Scanned: ' . (int) ($plan['scanned'] ?? 0) . ' | Rows: ' . count($rows) . '</p>';
        }

        if (!empty($rows)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="kaco_apply_hierarchy_cleanup" />';
            echo '<p>';
            submit_button('Apply Selected Repairs', 'primary', '', false);
            echo '</p>';
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 12px 0;">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="kaco_export_hierarchy_cleanup_csv" />';
            submit_button('Export Cleanup CSV', 'secondary', '', false);
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="kaco_apply_hierarchy_cleanup" />';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th><input type="checkbox" onclick="jQuery(\'.kaco-cleanup-select\').prop(\'checked\', this.checked);" /></th><th>Post</th><th>Current</th><th>Proposed</th><th>Issues</th><th>Changes</th><th>Status</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $post_id = (int) ($row['post_id'] ?? 0);
                $applyable = !empty($row['applyable']);
                $review_required = !empty($row['review_required']);
                echo '<tr>';
                echo '<td>';
                if ($applyable) {
                    echo '<input class="kaco-cleanup-select" type="checkbox" name="cleanup_post_ids[]" value="' . $post_id . '" checked="checked" />';
                }
                echo '</td>';
                echo '<td><a href="' . esc_url((string) ($row['edit_url'] ?? '#')) . '">' . esc_html((string) ($row['post_title'] ?? ('Post #' . $post_id))) . '</a></td>';
                echo '<td>' . esc_html((string) ($row['current_preview'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['proposed_preview'] ?? '-')) . '</td>';
                echo '<td>' . esc_html(implode(', ', (array) ($row['issues'] ?? array()))) . '</td>';
                echo '<td>' . esc_html(!empty($row['changes']) ? implode(' | ', (array) $row['changes']) : 'No automatic repair available') . '</td>';
                echo '<td>' . esc_html($applyable ? ($review_required ? 'Applyable with review notes' : 'Applyable') : 'Needs review') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</form>';
        } else {
            echo '<p>No hierarchy cleanup plan is loaded yet.</p>';
        }

        if (!empty($history)) {
            echo '<h3>Recent cleanup batches</h3>';
            echo '<table class="widefat striped"><thead><tr><th>When</th><th>Posts</th><th>Action</th></tr></thead><tbody>';
            foreach ($history as $batch_id => $batch) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($batch['created_at'] ?? '')) . '</td>';
                echo '<td>' . count((array) ($batch['items'] ?? array())) . '</td>';
                echo '<td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
                wp_nonce_field(self::NONCE_ACTION);
                echo '<input type="hidden" name="action" value="kaco_rollback_hierarchy_cleanup" />';
                echo '<input type="hidden" name="cleanup_batch_id" value="' . esc_attr((string) $batch_id) . '" />';
                submit_button('Rollback', 'secondary small', '', false);
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function render_duplicate_category_audit_view() {
        $groups = $this->find_duplicate_like_categories();

        echo '<h2>Duplicate category audit</h2>';
        echo '<p>Preview duplicate-like child categories under the controlled font branches before deciding whether to merge them manually. This audit is read-only.</p>';

        if (empty($groups)) {
            echo '<p>No duplicate-like category groups were found under the controlled parent branches.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Branch</th><th>Parent</th><th>Keep</th><th>Merge Candidates</th><th>Reason</th></tr></thead><tbody>';
        foreach ($groups as $group) {
            $keep = $group['keep'] ?? null;
            $merge = !empty($group['merge']) && is_array($group['merge']) ? $group['merge'] : array();
            $merge_labels = array();
            foreach ($merge as $term) {
                $merge_labels[] = (string) $term->name . ' (' . (int) $term->count . ')';
            }

            echo '<tr>';
            echo '<td>' . esc_html((string) ($group['branch_label'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($group['parent_name'] ?? '')) . '</td>';
            echo '<td>' . esc_html($keep ? ((string) $keep->name . ' (' . (int) $keep->count . ')') : '') . '</td>';
            echo '<td>' . esc_html(implode(', ', $merge_labels)) . '</td>';
            echo '<td>Normalized names collide under the same parent. Keep the strongest canonical term and review the rest for merge/reassignment.</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_exceptions_view() {
        global $wpdb;
        $review_filter = isset($_GET['review_filter']) ? sanitize_key((string) $_GET['review_filter']) : 'all';

        $needs_review_rows = $wpdb->get_results('SELECT * FROM ' . $this->table_name() . " WHERE status = 'needs_review' ORDER BY updated_at DESC LIMIT 100", ARRAY_A);
        $generator_review = $this->get_generator_automation_review();
        $logs = array_values(array_filter($this->get_automation_logs(), function($item) {
            $status = (string) ($item['status'] ?? '');
            return in_array($status, array('failed', 'needs_review'), true);
        }));

        if ($review_filter === 'all' || $review_filter === 'needs_review') {
            echo '<h3>Needs review</h3>';
            echo '<p class="description">These old-post suggestions were generated, but confidence was not high enough to bypass review.</p>';
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
                echo '<td><a href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=review&review_filter=needs_review&filter=needs_review')) . '">Open in Problems</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        }

        if ($review_filter === 'all' || $review_filter === 'failed') {
            echo '<h3>Failures</h3>';
            echo '<p class="description">These are recent automation failures and escalations. Use diagnostics mode when you need to inspect the exact failing stage.</p>';
        if (empty($logs)) {
            echo '<p>No recent automation failures or review escalations.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>When</th><th>Lane</th><th>Action</th><th>Status</th><th>Item</th><th>Confidence</th><th>Message</th><th>Diagnostics</th></tr></thead><tbody>';
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
                echo '<td>';
                if (!empty($log['debug']) && get_option('kaco_debug_mode', '0') === '1') {
                    echo '<details><summary>View</summary><pre style="white-space:pre-wrap;word-break:break-word;">' . esc_html($this->format_debug_data($log['debug'])) . '</pre></details>';
                } else {
                    echo '-';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
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
        $filter = isset($_GET['filter']) ? sanitize_key((string) $_GET['filter']) : 'approved';
        $allowed = array('approved', 'applied');
        if (!in_array($filter, $allowed, true)) {
            $filter = 'approved';
        }
        $where = $filter === 'applied' ? "status = 'applied'" : "status IN ('approved','applied')";
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 200", ARRAY_A);

        echo '<h2>Ready to apply</h2>';
        echo '<p>These are approved refresh suggestions. Applying them writes the proposed update into the live post with rollback support.</p>';
        echo '<p>';
        foreach (array('approved' => 'Approved + Applied', 'applied' => 'Applied Only') as $key => $label) {
            $url = admin_url('admin.php?page=kaco-dashboard&view=review' . ($key !== 'all' ? '&filter=' . $key : ''));
            echo '<a href="' . esc_url($url) . '" style="margin-right:10px;">' . esc_html($label) . '</a>';
        }
        echo '</p>';

        if (!$rows) {
            echo '<p>No approved refresh suggestions are waiting here.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Post</th><th>Status</th><th>Findings</th><th>AI</th><th>Created</th><th>Actions</th></tr></thead><tbody>';

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
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td><a href="' . esc_url($post_link) . '">' . esc_html($post_title ?: ('Post #' . (int) $row['post_id'])) . '</a></td>';
            echo '<td>' . esc_html($row['status']) . '</td>';
            echo '<td>internal links: ' . (int) $internal_links . '<br/>stale: ' . esc_html($stale) . '<br/>thin: ' . esc_html($thin) . ' (' . (int) $word_count . ' words)' . '<br/>duplicates: ' . (int) $dup_count . '<br/>category desc gaps: ' . (int) $cat_desc_gaps . '<br/>font hierarchy: ' . esc_html($font_hierarchy_missing) . '<br/>ai ready: ' . esc_html($ai_ready) . '</td>';
            echo '<td>confidence: ' . esc_html(number_format($confidence, 2)) . ($below_threshold ? ' / below threshold' : '') . '<br/>evidence: ' . esc_html($evidence_text) . '</td>';
            echo '<td>' . esc_html($row['created_at']) . '</td>';
            echo '<td>';

            if ($row['status'] === 'approved') {
                if (!$below_threshold) {
                    $this->render_action_form('kaco_apply_suggestion', 'Apply', (int) $row['id']);
                }
            }

            if ($row['status'] === 'applied') {
                $this->render_action_form('kaco_rollback_suggestion', 'Rollback', (int) $row['id']);
            }

            echo '</td>';
            echo '</tr>';
            echo '<tr><td></td><td colspan="6">' . $this->render_preview_panel($row, $audit, $suggestion, $ai) . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function recent_new_font_queue_activity($limit = 12) {
        $limit = min(50, max(1, (int) $limit));
        $logs = array_values(array_filter((array) $this->get_automation_logs(), function($item) {
            return (string) ($item['lane'] ?? '') === 'generator';
        }));
        $logs = array_slice($logs, 0, $limit);
        return array_map(function($item) {
            $status = (string) ($item['status'] ?? '');
            $action = (string) ($item['action'] ?? '');
            $label = $status;
            if ($action === 'queue_review') {
                $label = 'needs review';
            } elseif ($action === 'create_post' && $status === 'success') {
                $label = 'created';
            } elseif ($action === 'create_post' && $status === 'skipped') {
                $label = 'duplicate';
            } elseif ($action === 'generate_preview' && $status === 'failed') {
                $label = 'failed';
            }
            $item_label = (string) ($item['title'] ?? '');
            if ($item_label === '') {
                $item_label = (string) ($item['url'] ?? '');
            } elseif (!empty($item['url'])) {
                $item_label .= ' | ' . (string) $item['url'];
            }
            $item['state_label'] = $label;
            $item['item_label'] = $item_label;
            return $item;
        }, $logs);
    }

    private function render_action_form($action, $label, $suggestion_id) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        echo '<input type="hidden" name="suggestion_id" value="' . (int) $suggestion_id . '" />';
        submit_button($label, 'secondary small', '', false);
        echo '</form>';
    }

    private function render_generator_review_action_form($action, $label, $queue_key) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        echo '<input type="hidden" name="queue_key" value="' . (int) $queue_key . '" />';
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

    private function format_debug_data($data) {
        if (empty($data)) {
            return '';
        }
        if (is_string($data)) {
            return $data;
        }
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : print_r($data, true);
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
        $debug_mode = (string) get_option('kaco_debug_mode', '0');
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
        $automation_operating_mode = $this->sanitize_automation_operating_mode((string) get_option('kaco_automation_operating_mode', 'balanced'));
        $automation_frequency = (string) get_option('kaco_automation_frequency', 'daily');
        $automation_post_type = $this->automation_post_type();
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
        $automation_queue_urls_per_run = (int) get_option('kaco_automation_queue_urls_per_run', 1);
        $automation_queue_delay_minutes = (int) get_option('kaco_automation_queue_delay_minutes', 10);
        $automation_auto_create_drafts = (string) get_option('kaco_automation_auto_create_drafts', '1');
        $automation_generator_create_confidence = (string) get_option('kaco_automation_generator_create_confidence', '0.90');
        $automation_auto_schedule_generated_posts = (string) get_option('kaco_automation_auto_schedule_generated_posts', '1');
        $automation_generated_post_spacing_hours = (int) get_option('kaco_automation_generated_post_spacing_hours', 3);
        $automation_last_run = get_option('kaco_automation_last_run', array());
        $parent_warnings = $this->get_parent_category_warnings();
        $section_style = 'background:#fff;border:1px solid #dcdcde;padding:16px;margin:18px 0;';

        echo '<h2>Rules and template</h2>';
        echo '<p>Settings are grouped by operating concern. Keep automation thresholds conservative until the source parsers are stable on your real URLs.</p>';
        if (!empty($parent_warnings)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(implode(' | ', $parent_warnings)) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="kaco_save_settings" />';

        echo '<div style="' . esc_attr($section_style) . '">';
        echo '<h3 style="margin-top:0;">AI</h3>';
        echo '<p class="description">Controls the model and endpoint used for generation, optimization, and category drafting.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_openai_api_key">OpenAI API key</label></th>';
        echo '<td><input type="password" id="kaco_openai_api_key" name="kaco_openai_api_key" value="' . esc_attr($openai_key) . '" class="regular-text" autocomplete="off" /><p class="description">Required for every AI action in New Fonts, Refresh Existing, and Problems.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_openai_model">OpenAI model</label></th>';
        echo '<td><select id="kaco_openai_model" name="kaco_openai_model">';
        foreach ($this->allowed_openai_models() as $model_name) {
            echo '<option value="' . esc_attr($model_name) . '"' . selected($openai_model, $model_name, false) . '>' . esc_html($model_name) . '</option>';
        }
        echo '</select><p class="description">Use only the validated models here. This avoids invalid model names breaking queue processing in production.</p></td></tr>';
        echo '<tr><td colspan="2" style="padding:0;">';
        echo '<details style="margin:8px 0 0 0;"><summary><strong>Advanced AI</strong></summary>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_openai_endpoint">OpenAI endpoint</label></th>';
        echo '<td><input type="url" id="kaco_openai_endpoint" name="kaco_openai_endpoint" value="' . esc_attr($openai_endpoint) . '" class="regular-text" /><p class="description">Advanced setting. Leave this on the default Responses API unless you are deliberately testing another endpoint.</p></td></tr>';
        echo '</tbody></table>';
        echo '</details>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        echo '<div style="' . esc_attr($section_style) . '">';
        echo '<h3 style="margin-top:0;">Content</h3>';
        echo '<p class="description">Defines rewrite thresholds, structure, and editorial behavior for old-post refreshes.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_stale_months">Stale threshold (months)</label></th>';
        echo '<td><input type="number" min="1" max="120" id="kaco_stale_months" name="kaco_stale_months" value="' . (int) $stale_months . '" /><p class="description">Posts older than this become eligible for stale-content audits.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_min_internal_links">Minimum internal links</label></th>';
        echo '<td><input type="number" min="0" max="50" id="kaco_min_internal_links" name="kaco_min_internal_links" value="' . (int) $min_internal_links . '" /><p class="description">Used by Refresh to flag posts with weak site integration.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_min_words">Minimum content words</label></th>';
        echo '<td><input type="number" min="50" max="5000" id="kaco_min_words" name="kaco_min_words" value="' . (int) $min_words . '" /><p class="description">Posts under this threshold are treated as thin content.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_ai_confidence_threshold">AI confidence threshold</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_ai_confidence_threshold" name="kaco_ai_confidence_threshold" value="' . esc_attr($ai_confidence_threshold) . '" /><p class="description">Apply is blocked below this value. Lowering it increases throughput but also increases editorial risk.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_enable_title_regenerator">Enable title regenerator</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_enable_title_regenerator" name="kaco_enable_title_regenerator" value="1" ' . checked('1', $enable_title_regenerator, false) . ' /> yes</label><p class="description">Allows AI to replace weak titles during optimization. Turn this off if titles are already manually curated.</p></td></tr>';
        echo '<tr><td colspan="2" style="padding:0;">';
        echo '<details style="margin:8px 0 0 0;"><summary><strong>Advanced content rules</strong></summary>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_rewrite_mode">Rewrite mode</label></th>';
        echo '<td><select id="kaco_rewrite_mode" name="kaco_rewrite_mode">';
        echo '<option value="append"' . selected($rewrite_mode, 'append', false) . '>append</option>';
        echo '<option value="replace_body"' . selected($rewrite_mode, 'replace_body', false) . '>replace body</option>';
        echo '<option value="full_rebuild"' . selected($rewrite_mode, 'full_rebuild', false) . '>full rebuild</option>';
        echo '</select><p class="description"><code>append</code> is safest but can get messy. <code>replace body</code> is the default operational mode. <code>full rebuild</code> is the most aggressive and should be used only when you accept full content replacement.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_editorial_style_guide">Editorial style guide</label></th>';
        echo '<td><textarea id="kaco_editorial_style_guide" name="kaco_editorial_style_guide" rows="8" cols="80" class="large-text">' . esc_textarea($editorial_style_guide) . '</textarea><p class="description">Optional house rules that should shape generator and optimizer prose.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_update_template">Content update template</label></th>';
        echo '<td><textarea id="kaco_update_template" name="kaco_update_template" rows="12" cols="80" class="large-text code">' . esc_textarea($template) . '</textarea><p class="description">Keep this aligned with the current new-font HTML structure. Use <code>{{font_details}}</code> for the current generator layout.</p></td></tr>';
        echo '</tbody></table>';
        echo '</details>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        echo '<div style="' . esc_attr($section_style) . '">';
        echo '<h3 style="margin-top:0;">Automation</h3>';
        echo '<p class="description">Controls scheduled old-post refreshes and queued new-font processing. The highest-risk switches are called out explicitly.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_automation_operating_mode">Operating mode</label></th>';
        echo '<td><select id="kaco_automation_operating_mode" name="kaco_automation_operating_mode">';
        foreach ($this->automation_operating_modes() as $mode_key => $mode) {
            echo '<option value="' . esc_attr($mode_key) . '"' . selected($automation_operating_mode, $mode_key, false) . '>' . esc_html((string) ($mode['label'] ?? ucfirst($mode_key))) . '</option>';
        }
        echo '</select><p class="description">Use this to set the queue pace and automation aggressiveness as a single policy. Advanced controls below can still override details if you need to tune them manually.</p>';
        foreach ($this->automation_operating_modes() as $mode_key => $mode) {
            echo '<div style="margin-top:4px;"><strong>' . esc_html((string) ($mode['label'] ?? ucfirst($mode_key))) . ':</strong> ' . esc_html((string) ($mode['description'] ?? '')) . '</div>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_enabled">Automation enabled</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_enabled" name="kaco_automation_enabled" value="1" ' . checked('1', $automation_enabled, false) . ' /> yes</label><p class="description">Master switch for scheduled audits and queued URL processing. Disable this if you want the plugin to run only on manual actions.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_frequency">Automation frequency</label></th>';
        echo '<td><select id="kaco_automation_frequency" name="kaco_automation_frequency">';
        foreach (array('hourly' => 'hourly', 'twicedaily' => 'twice daily', 'daily' => 'daily') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($automation_frequency, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Hourly gives faster queue movement but puts more load on source fetching and AI calls.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_scan_limit">Automation scan limit</label></th>';
        echo '<td><input type="number" min="1" max="500" id="kaco_automation_scan_limit" name="kaco_automation_scan_limit" value="' . (int) $automation_scan_limit . '" /><p class="description">Number of existing posts checked per scheduled run.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_issue_filter">Automation issue filter</label></th>';
        echo '<td><select id="kaco_automation_issue_filter" name="kaco_automation_issue_filter">';
        foreach (array('all' => 'all issues', 'missing_hierarchy' => 'missing hierarchy', 'thin' => 'thin content', 'stale' => 'stale content', 'low_links' => 'low internal links', 'duplicate' => 'duplicate risk', 'category_desc' => 'category description gaps') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($automation_issue_filter, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Use a narrow filter if you want automation to attack only one class of problem at a time.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_fonts_only">Automation fonts only</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_fonts_only" name="kaco_automation_fonts_only" value="1" ' . checked('1', $automation_fonts_only, false) . ' /> yes</label><p class="description">Recommended. Keeps scheduled audits scoped to font content instead of the full site.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_auto_generate_ai">Automation auto-generate AI</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_generate_ai" name="kaco_automation_auto_generate_ai" value="1" ' . checked('1', $automation_auto_generate_ai, false) . ' /> yes</label><p class="description">If off, automation only builds the queue. If on, it also drafts AI output for each queued item.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_auto_approve">Automation auto-approve</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_approve" name="kaco_automation_auto_approve" value="1" ' . checked('1', $automation_auto_approve, false) . ' /> yes</label><p class="description">High-impact switch. Anything at or above the approval threshold skips manual review and moves straight to approved.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_approve_confidence">Automation approve confidence</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_automation_approve_confidence" name="kaco_automation_approve_confidence" value="' . esc_attr($automation_approve_confidence) . '" /><p class="description">Keep this high. Lower values reduce manual work but increase false approvals.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_auto_apply">Automation auto-apply approved suggestions</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_apply" name="kaco_automation_auto_apply" value="1" ' . checked('1', $automation_auto_apply, false) . ' /> yes</label><p class="description">Highest-risk switch in the plugin. When enabled, high-confidence old-post rewrites are written to live content without another manual checkpoint.</p></td></tr>';
        echo '<tr><td colspan="2" style="padding:0;">';
        echo '<details style="margin:8px 0 0 0;"><summary><strong>Advanced automation controls</strong></summary>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_automation_post_type">Automation post type</label></th>';
        echo '<td><input type="text" id="kaco_automation_post_type" name="kaco_automation_post_type" value="' . esc_attr($automation_post_type) . '" class="regular-text" /><p class="description">Usually <code>post</code>. Change only if your font content lives in a custom post type.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_scan_limit">Automation scan limit</label></th>';
        echo '<td><input type="number" min="1" max="500" id="kaco_automation_scan_limit" name="kaco_automation_scan_limit" value="' . (int) $automation_scan_limit . '" /><p class="description">Number of existing posts checked per scheduled run.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_issue_filter">Automation issue filter</label></th>';
        echo '<td><select id="kaco_automation_issue_filter" name="kaco_automation_issue_filter">';
        foreach (array('all' => 'all issues', 'missing_hierarchy' => 'missing hierarchy', 'thin' => 'thin content', 'stale' => 'stale content', 'low_links' => 'low internal links', 'duplicate' => 'duplicate risk', 'category_desc' => 'category description gaps') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($automation_issue_filter, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Use a narrow filter if you want automation to attack only one class of problem at a time.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_fonts_only">Automation fonts only</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_fonts_only" name="kaco_automation_fonts_only" value="1" ' . checked('1', $automation_fonts_only, false) . ' /> yes</label><p class="description">Recommended. Keeps scheduled audits scoped to font content instead of the full site.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_apply_confidence">Automation auto-apply confidence</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_automation_apply_confidence" name="kaco_automation_apply_confidence" value="' . esc_attr($automation_apply_confidence) . '" /><p class="description">Set this above the approval threshold. This should be your strictest gate.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_process_url_inbox">Automation process queue</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_process_url_inbox" name="kaco_automation_process_url_inbox" value="1" ' . checked('1', $automation_process_url_inbox, false) . ' /> yes</label><p class="description">Processes the New Fonts lane\'s queue during scheduled runs.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_url_batch_size">Automation URL batch size</label></th>';
        echo '<td><input type="number" min="1" max="100" id="kaco_automation_url_batch_size" name="kaco_automation_url_batch_size" value="' . (int) $automation_url_batch_size . '" /><p class="description">Keep this modest if your host times out on marketplace fetches or multiple AI calls.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_queue_urls_per_run">Queue URLs per run</label></th>';
        echo '<td><input type="number" min="1" max="25" id="kaco_automation_queue_urls_per_run" name="kaco_automation_queue_urls_per_run" value="' . (int) $automation_queue_urls_per_run . '" /><p class="description">Recommended: <code>1</code>. The queue processes only this many URLs per pass, then waits for the next follow-up run.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_queue_delay_minutes">Queue delay (minutes)</label></th>';
        echo '<td><input type="number" min="1" max="240" id="kaco_automation_queue_delay_minutes" name="kaco_automation_queue_delay_minutes" value="' . (int) $automation_queue_delay_minutes . '" /><p class="description">Recommended: <code>10</code>. Remaining URLs are picked up by a dedicated queue event after this delay instead of being processed back-to-back.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_auto_create_drafts">Automation auto-create posts</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_create_drafts" name="kaco_automation_auto_create_drafts" value="1" ' . checked('1', $automation_auto_create_drafts, false) . ' /> yes</label><p class="description">High-confidence new-font items become posts automatically. Lower-confidence items stay in New Fonts for review.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_generator_create_confidence">Generator auto-create confidence</label></th>';
        echo '<td><input type="number" step="0.01" min="0" max="1" id="kaco_automation_generator_create_confidence" name="kaco_automation_generator_create_confidence" value="' . esc_attr($automation_generator_create_confidence) . '" /><p class="description">Primary safety gate for new-font automation. Raise this if Creative Market or MyFonts extraction is noisy.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_auto_schedule_generated_posts">Auto-schedule generated posts</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_automation_auto_schedule_generated_posts" name="kaco_automation_auto_schedule_generated_posts" value="1" ' . checked('1', $automation_auto_schedule_generated_posts, false) . ' /> yes</label><p class="description">If off, auto-created items stay as drafts. If on, they reserve future publish slots immediately.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_automation_generated_post_spacing_hours">Generated post spacing (hours)</label></th>';
        echo '<td><input type="number" min="1" max="24" id="kaco_automation_generated_post_spacing_hours" name="kaco_automation_generated_post_spacing_hours" value="' . (int) $automation_generated_post_spacing_hours . '" /><p class="description">Spacing between scheduled auto-created posts. This affects your publication cadence, not content quality.</p></td></tr>';
        echo '</tbody></table>';
        echo '</details>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        echo '<div style="' . esc_attr($section_style) . '">';
        echo '<h3 style="margin-top:0;">Category Mapping</h3>';
        echo '<p class="description">Controls the internal category branches and fixed vocabularies used when the plugin assigns font categories.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_category_desc_min_chars">Minimum category description chars</label></th>';
        echo '<td><input type="number" min="20" max="2000" id="kaco_category_desc_min_chars" name="kaco_category_desc_min_chars" value="' . (int) $category_desc_min_chars . '" /><p class="description">Categories shorter than this are treated as needing description work.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_tag_max_per_post">Maximum tags per post</label></th>';
        echo '<td><input type="number" min="1" max="100" id="kaco_tag_max_per_post" name="kaco_tag_max_per_post" value="' . (int) $tag_max_per_post . '" /><p class="description">Posts above this threshold are flagged as over-tagged.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_tag_min_posts_per_tag">Minimum posts per tag</label></th>';
        echo '<td><input type="number" min="1" max="100" id="kaco_tag_min_posts_per_tag" name="kaco_tag_min_posts_per_tag" value="' . (int) $tag_min_posts_per_tag . '" /><p class="description">Tags used on fewer posts than this are candidates for cleanup or merge.</p></td></tr>';
        echo '<tr><td colspan="2" style="padding:0;">';
        echo '<details style="margin:8px 0 0 0;"><summary><strong>Advanced category mapping</strong></summary>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_fonts_category_name">Fonts category name</label></th>';
        echo '<td><input type="text" id="kaco_fonts_category_name" name="kaco_fonts_category_name" value="' . esc_attr($fonts_category_name) . '" class="regular-text" /><p class="description">Top-level category used to scope font content.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_designer_parent_category_name">Designer parent category</label></th>';
        echo '<td><input type="text" id="kaco_designer_parent_category_name" name="kaco_designer_parent_category_name" value="' . esc_attr($designer_parent_category_name) . '" class="regular-text" /><p class="description">Multi-value branch. Each post should end up with one or more designer children.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_foundry_parent_category_name">Foundry parent category</label></th>';
        echo '<td><input type="text" id="kaco_foundry_parent_category_name" name="kaco_foundry_parent_category_name" value="' . esc_attr($foundry_parent_category_name) . '" class="regular-text" /><p class="description">Single-value branch. Each post should have exactly one foundry child.</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_font_style_parent_category_name">Font Style parent category</label></th>';
        echo '<td><input type="text" id="kaco_font_style_parent_category_name" name="kaco_font_style_parent_category_name" value="' . esc_attr($font_style_parent_category_name) . '" class="regular-text" /><p class="description">Single-value fixed list: ' . esc_html(implode(', ', $this->fixed_font_styles())) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_font_mood_parent_category_name">Font Mood parent category</label></th>';
        echo '<td><input type="text" id="kaco_font_mood_parent_category_name" name="kaco_font_mood_parent_category_name" value="' . esc_attr($font_mood_parent_category_name) . '" class="regular-text" /><p class="description">Multi-value fixed list: ' . esc_html(implode(', ', $this->fixed_font_moods())) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="kaco_font_use_case_parent_category_name">Font Use Case parent category</label></th>';
        echo '<td><input type="text" id="kaco_font_use_case_parent_category_name" name="kaco_font_use_case_parent_category_name" value="' . esc_attr($font_use_case_parent_category_name) . '" class="regular-text" /><p class="description">Multi-value fixed list: ' . esc_html(implode(', ', $this->fixed_font_use_cases())) . '</p></td></tr>';
        echo '</tbody></table>';
        echo '</details>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        echo '<div style="' . esc_attr($section_style) . '">';
        echo '<h3 style="margin-top:0;">Diagnostics</h3>';
        echo '<p class="description">Use this when you are investigating failures or validating parser behavior on a staging site.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="kaco_debug_mode">Diagnostics mode</label></th>';
        echo '<td><label><input type="checkbox" id="kaco_debug_mode" name="kaco_debug_mode" value="1" ' . checked('1', $debug_mode, false) . ' /> yes</label><p class="description">Shows detailed failure diagnostics for source fetch, parser, OpenAI, and write stages in New Fonts and Problems. Leave this off during normal operation unless you are troubleshooting.</p></td></tr>';
        echo '</tbody></table>';
        echo '</div>';

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
                $automation_bits[] = 'Queue processed: ' . (int) ($automation_last_run['generator_inbox']['processed'] ?? 0);
                $automation_bits[] = 'Drafts created: ' . (int) ($automation_last_run['generator_inbox']['created'] ?? 0);
                $automation_bits[] = 'New-font review queued: ' . (int) ($automation_last_run['generator_inbox']['queued_for_review'] ?? 0);
            }
            $automation_bits[] = '<a href="' . esc_url(admin_url('admin.php?page=kaco-dashboard&view=review')) . '">Open problems</a>';
            echo '<p><strong>Last automation run</strong><br/>' . implode(' | ', $automation_bits) . '</p>';
        }

        echo '<p class="description"><strong>Plugin version:</strong> ' . esc_html(self::VERSION) . '</p>';

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
        update_option('kaco_openai_model', $this->sanitize_openai_model(wp_unslash($_POST['kaco_openai_model'] ?? self::OPENAI_MODEL)));
        update_option('kaco_debug_mode', !empty($_POST['kaco_debug_mode']) ? '1' : '0');
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
        $automation_operating_mode = $this->sanitize_automation_operating_mode((string) ($_POST['kaco_automation_operating_mode'] ?? 'balanced'));
        $this->apply_automation_operating_mode($automation_operating_mode);
        update_option('kaco_automation_frequency', $this->sanitize_automation_frequency((string) ($_POST['kaco_automation_frequency'] ?? 'daily')));
        $automation_post_type = sanitize_key((string) ($_POST['kaco_automation_post_type'] ?? 'post'));
        if ($automation_post_type === '' || !post_type_exists($automation_post_type)) {
            $automation_post_type = 'post';
        }
        update_option('kaco_automation_post_type', $automation_post_type);
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
        update_option('kaco_automation_queue_urls_per_run', min(25, max(1, (int) ($_POST['kaco_automation_queue_urls_per_run'] ?? get_option('kaco_automation_queue_urls_per_run', 1)))));
        update_option('kaco_automation_queue_delay_minutes', min(240, max(1, (int) ($_POST['kaco_automation_queue_delay_minutes'] ?? get_option('kaco_automation_queue_delay_minutes', 10)))));
        update_option('kaco_automation_auto_create_drafts', !empty($_POST['kaco_automation_auto_create_drafts']) ? '1' : '0');
        update_option('kaco_automation_generator_create_confidence', min(1, max(0, (float) ($_POST['kaco_automation_generator_create_confidence'] ?? get_option('kaco_automation_generator_create_confidence', 0.90)))));
        update_option('kaco_automation_auto_schedule_generated_posts', !empty($_POST['kaco_automation_auto_schedule_generated_posts']) ? '1' : '0');
        update_option('kaco_automation_generated_post_spacing_hours', min(24, max(1, (int) ($_POST['kaco_automation_generated_post_spacing_hours'] ?? 3))));
        $this->ensure_automation_schedule();

        $this->redirect_with_notice('Settings saved.', 'settings');
    }
}
