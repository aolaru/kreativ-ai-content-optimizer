<?php

require_once __DIR__ . '/traits/trait-kaco-admin-ui.php';
require_once __DIR__ . '/traits/trait-kaco-actions.php';
require_once __DIR__ . '/traits/trait-kaco-ai-generator.php';
require_once __DIR__ . '/traits/trait-kaco-content.php';
require_once __DIR__ . '/traits/trait-kaco-taxonomy-tags.php';

final class KACO_Plugin {
    use KACO_Admin_UI_Trait;
    use KACO_Actions_Trait;
    use KACO_AI_Generator_Trait;
    use KACO_Content_Trait;
    use KACO_Taxonomy_Tags_Trait;

    const VERSION = '1.1.0';
    const TABLE = 'kaco_suggestions';
    const NONCE_ACTION = 'kaco_admin_action';
    const OPENAI_ENDPOINT = 'https://api.openai.com/v1/responses';
    const OPENAI_MODEL = 'gpt-5-mini';
    const OPENAI_ALLOWED_MODELS = array(
        'gpt-5-mini',
        'gpt-4.1-mini',
    );

    private $plugin_file;

    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->migrate_default_openai_settings();
        $this->migrate_update_template_default();
        register_activation_hook($this->plugin_file, array($this, 'activate'));
        register_deactivation_hook($this->plugin_file, array($this, 'deactivate'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('kaco_automation_event', array($this, 'handle_automation_event'));
        add_action('kaco_generator_queue_event', array($this, 'handle_generator_queue_event'));
        add_action('admin_post_kaco_add_generator_urls_to_inbox', array($this, 'handle_add_generator_urls_to_inbox'));
        add_action('admin_post_kaco_process_generator_queue_now', array($this, 'handle_process_generator_queue_now'));
        add_action('admin_post_kaco_create_generated_drafts', array($this, 'handle_create_generated_drafts'));
        add_action('admin_post_kaco_retry_generator_review_item', array($this, 'handle_retry_generator_review_item'));
        add_action('admin_post_kaco_discard_generator_review_item', array($this, 'handle_discard_generator_review_item'));
        add_action('admin_post_kaco_create_generator_review_item', array($this, 'handle_create_generator_review_item'));
        add_action('admin_post_kaco_queue_refresh_urls', array($this, 'handle_queue_refresh_urls'));
        add_action('admin_post_kaco_run_refresh_automation_now', array($this, 'handle_run_refresh_automation_now'));
        add_action('admin_post_kaco_scan_hierarchy_cleanup', array($this, 'handle_scan_hierarchy_cleanup'));
        add_action('admin_post_kaco_apply_hierarchy_cleanup', array($this, 'handle_apply_hierarchy_cleanup'));
        add_action('admin_post_kaco_export_hierarchy_cleanup_csv', array($this, 'handle_export_hierarchy_cleanup_csv'));
        add_action('admin_post_kaco_rollback_hierarchy_cleanup', array($this, 'handle_rollback_hierarchy_cleanup'));
        add_action('admin_post_kaco_generate_category_ai', array($this, 'handle_generate_category_ai'));
        add_action('admin_post_kaco_apply_category_ai', array($this, 'handle_apply_category_ai'));
        add_action('admin_post_kaco_rollback_category_ai', array($this, 'handle_rollback_category_ai'));
        add_action('admin_post_kaco_preview_tag_merge', array($this, 'handle_preview_tag_merge'));
        add_action('admin_post_kaco_export_tag_merge_csv', array($this, 'handle_export_tag_merge_csv'));
        add_action('admin_post_kaco_apply_tag_merge', array($this, 'handle_apply_tag_merge'));
        add_action('admin_post_kaco_rollback_tag_merge', array($this, 'handle_rollback_tag_merge'));
        add_action('admin_post_kaco_generate_ai_suggestion', array($this, 'handle_generate_ai_suggestion'));
        add_action('admin_post_kaco_generate_ai_batch', array($this, 'handle_generate_ai_batch'));
        add_action('admin_post_kaco_bulk_suggestions', array($this, 'handle_bulk_suggestions'));
        add_action('admin_post_kaco_export_suggestions_csv', array($this, 'handle_export_suggestions_csv'));
        add_action('admin_post_kaco_import_suggestions_csv', array($this, 'handle_import_suggestions_csv'));
        add_action('admin_post_kaco_approve_suggestion', array($this, 'handle_approve_suggestion'));
        add_action('admin_post_kaco_apply_suggestion', array($this, 'handle_apply_suggestion'));
        add_action('admin_post_kaco_reject_suggestion', array($this, 'handle_reject_suggestion'));
        add_action('admin_post_kaco_rollback_suggestion', array($this, 'handle_rollback_suggestion'));
        add_action('admin_post_kaco_save_settings', array($this, 'handle_save_settings'));
    }

    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            audit_data LONGTEXT NULL,
            suggestion_data LONGTEXT NULL,
            original_snapshot LONGTEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta($sql);

        add_option('kaco_stale_months', '18');
        add_option('kaco_min_internal_links', '4');
        add_option('kaco_min_words', '250');
        add_option('kaco_category_desc_min_chars', '120');
        add_option('kaco_update_template', $this->default_update_template());
        add_option('kaco_openai_api_key', '');
        add_option('kaco_openai_endpoint', self::OPENAI_ENDPOINT);
        add_option('kaco_openai_model', self::OPENAI_MODEL);
        add_option('kaco_debug_mode', '0');
        add_option('kaco_term_suggestions', array());
        add_option('kaco_fonts_category_name', 'Fonts');
        add_option('kaco_designer_parent_category_name', 'Designer');
        add_option('kaco_foundry_parent_category_name', 'Foundry');
        add_option('kaco_font_style_parent_category_name', 'Font Style');
        add_option('kaco_font_mood_parent_category_name', 'Font Mood');
        add_option('kaco_font_use_case_parent_category_name', 'Font Use Case');
        add_option('kaco_ai_confidence_threshold', '0.65');
        add_option('kaco_term_history', array());
        add_option('kaco_enable_title_regenerator', '1');
        add_option('kaco_tag_max_per_post', '12');
        add_option('kaco_tag_min_posts_per_tag', '2');
        add_option('kaco_tag_merge_history', array());
        add_option('kaco_editorial_style_guide', 'Write like an editorial font reviewer. Avoid filler words such as versatile, unique touch, suitable for various projects, reliable choice, and engaging aesthetics. Use concrete visual observations and specific use cases. Do not invent technical features.');
        add_option('kaco_rewrite_mode', 'replace_body');
        add_option('kaco_generator_url_inbox', array());
        add_option('kaco_generator_automation_review', array());
        add_option('kaco_automation_enabled', '0');
        add_option('kaco_automation_operating_mode', 'balanced');
        add_option('kaco_automation_frequency', 'daily');
        add_option('kaco_automation_post_type', 'post');
        add_option('kaco_automation_scan_limit', '50');
        add_option('kaco_automation_fonts_only', '1');
        add_option('kaco_automation_issue_filter', 'all');
        add_option('kaco_automation_auto_generate_ai', '1');
        add_option('kaco_automation_auto_approve', '1');
        add_option('kaco_automation_approve_confidence', '0.85');
        add_option('kaco_automation_auto_apply', '0');
        add_option('kaco_automation_apply_confidence', '0.93');
        add_option('kaco_automation_process_url_inbox', '1');
        add_option('kaco_automation_url_batch_size', '10');
        add_option('kaco_automation_queue_urls_per_run', '1');
        add_option('kaco_automation_queue_delay_minutes', '10');
        add_option('kaco_automation_auto_create_drafts', '1');
        add_option('kaco_automation_generator_create_confidence', '0.90');
        add_option('kaco_automation_auto_schedule_generated_posts', '1');
        add_option('kaco_automation_generated_post_spacing_hours', '3');
        add_option('kaco_automation_last_scheduled_gmt', '');
        add_option('kaco_automation_last_run', array());
        add_option('kaco_automation_logs', array());
        add_option('kaco_hierarchy_cleanup_plan', array());
        add_option('kaco_hierarchy_cleanup_history', array());

        $this->ensure_automation_schedule();
        $this->migrate_default_openai_settings();
        $this->migrate_update_template_default();
    }

    public function deactivate() {
        $this->clear_automation_schedule();
    }

    public function register_admin_menu() {
        add_menu_page(
            'Kreativ Commercial Fonts',
            'Commercial Fonts',
            'edit_posts',
            'kaco-dashboard',
            array($this, 'render_admin_page'),
            'dashicons-superhero',
            58
        );
    }

    public function render_admin_page() {
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions.');
        }

        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : 'dashboard';
        $notice = isset($_GET['kaco_notice']) ? sanitize_text_field(wp_unslash($_GET['kaco_notice'])) : '';

        echo '<div class="wrap">';
        echo '<h1>Kreativ Commercial Fonts</h1>';

        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<nav class="nav-tab-wrapper">';
        $this->tab_link('dashboard', 'Dashboard', $view);
        $this->tab_link('create', 'New Fonts', $view);
        $this->tab_link('refresh', 'Refresh Existing', $view);
        $this->tab_link('review', 'Problems', $view);
        $this->tab_link('settings', 'Settings', $view);
        echo '</nav>';

        if ($view === 'dashboard') {
            $this->render_dashboard_view();
        } elseif ($view === 'create' || $view === 'generator') {
            $this->render_generator_view();
        } elseif ($view === 'refresh' || $view === 'audit') {
            $this->render_refresh_view();
        } elseif ($view === 'review' || $view === 'exceptions' || $view === 'suggestions') {
            $this->render_review_view();
        } elseif ($view === 'taxonomy' || $view === 'cleanup' || $view === 'categories' || $view === 'tags') {
            $this->render_dashboard_view();
        } elseif ($view === 'settings') {
            $this->render_settings_view();
        } else {
            $this->render_dashboard_view();
        }

        echo '</div>';
    }

    private function ensure_automation_schedule() {
        $enabled = get_option('kaco_automation_enabled', '0') === '1';
        $timestamp = wp_next_scheduled('kaco_automation_event');
        if (!$enabled) {
            $this->clear_automation_schedule();
            return;
        }

        $recurrence = $this->sanitize_automation_frequency((string) get_option('kaco_automation_frequency', 'daily'));
        if ($timestamp) {
            $scheduled = wp_get_schedule('kaco_automation_event');
            if ($scheduled === $recurrence) {
                return;
            }
            $this->clear_automation_schedule();
        }

        wp_schedule_event(time() + MINUTE_IN_SECONDS * 5, $recurrence, 'kaco_automation_event');
    }

    private function clear_automation_schedule() {
        wp_clear_scheduled_hook('kaco_automation_event');
        wp_clear_scheduled_hook('kaco_generator_queue_event');
    }

    private function legacy_update_template() {
        return "## Why you'll love {{post_title}}\n\n{{ai_intro}}\n\n## Visual character\n\n{{visual_analysis}}\n\n## Best for\n\n{{best_for}}\n\n## Pairing suggestions\n\n{{pairing_notes}}\n\n## Font Features\n\n{{font_features}}\n\n## What's Included\n\n{{whats_included}}\n\n## Pricing\n\n{{pricing_details}}\n\n## Verified details\n\n{{verified_details}}\n";
    }

    private function default_update_template() {
        return "<h2>Why you should consider {{post_title}}</h2>\n<p>{{ai_intro}}</p>\n\n<h2>Visual character</h2>\n<p>{{visual_analysis}}</p>\n\n<h2>Best use cases</h2>\n{{best_for}}\n\n<h2>Font pairing ideas</h2>\n{{pairing_notes}}\n\n<h2>Font Features</h2>\n{{font_features}}\n\n<h2>What's Included</h2>\n{{whats_included}}\n\n<h2>Pricing</h2>\n{{pricing_details}}\n\n{{font_details}}\n";
    }

    private function migrate_update_template_default() {
        $current = (string) get_option('kaco_update_template', '');
        if ($current === '' || $current === $this->legacy_update_template()) {
            update_option('kaco_update_template', $this->default_update_template(), false);
        }
    }

    private function migrate_default_openai_settings() {
        $current_model = get_option('kaco_openai_model', '');
        if ($current_model === '' || $current_model === 'gpt-4.1-mini') {
            update_option('kaco_openai_model', self::OPENAI_MODEL, false);
        }

        $current_endpoint = get_option('kaco_openai_endpoint', '');
        if ($current_endpoint === '' || $current_endpoint === 'https://api.openai.com/v1/chat/completions') {
            update_option('kaco_openai_endpoint', self::OPENAI_ENDPOINT, false);
        }
    }

    private function sanitize_automation_frequency($value) {
        $allowed = array('hourly', 'twicedaily', 'daily');
        return in_array($value, $allowed, true) ? $value : 'daily';
    }

    private function sanitize_openai_model($value) {
        $value = sanitize_text_field((string) $value);
        if (in_array($value, self::OPENAI_ALLOWED_MODELS, true)) {
            return $value;
        }
        return self::OPENAI_MODEL;
    }

    private function allowed_openai_models() {
        return self::OPENAI_ALLOWED_MODELS;
    }

    private function automation_operating_modes() {
        return array(
            'conservative' => array(
                'label' => 'Conservative',
                'description' => 'Slowest and safest. Review stays heavy and automation writes very little without confirmation.',
                'settings' => array(
                    'kaco_automation_queue_urls_per_run' => 1,
                    'kaco_automation_queue_delay_minutes' => 15,
                    'kaco_automation_auto_approve' => '0',
                    'kaco_automation_approve_confidence' => 0.90,
                    'kaco_automation_auto_apply' => '0',
                    'kaco_automation_apply_confidence' => 0.96,
                    'kaco_automation_auto_create_drafts' => '1',
                    'kaco_automation_generator_create_confidence' => 0.94,
                    'kaco_debug_mode' => '1',
                ),
            ),
            'balanced' => array(
                'label' => 'Balanced',
                'description' => 'Recommended default. Keeps pacing safe while still letting strong items move automatically.',
                'settings' => array(
                    'kaco_automation_queue_urls_per_run' => 1,
                    'kaco_automation_queue_delay_minutes' => 10,
                    'kaco_automation_auto_approve' => '1',
                    'kaco_automation_approve_confidence' => 0.85,
                    'kaco_automation_auto_apply' => '0',
                    'kaco_automation_apply_confidence' => 0.93,
                    'kaco_automation_auto_create_drafts' => '1',
                    'kaco_automation_generator_create_confidence' => 0.90,
                    'kaco_debug_mode' => '0',
                ),
            ),
            'aggressive' => array(
                'label' => 'Aggressive',
                'description' => 'Fastest throughput. Use only when parser quality is stable and you accept more automated writes.',
                'settings' => array(
                    'kaco_automation_queue_urls_per_run' => 2,
                    'kaco_automation_queue_delay_minutes' => 5,
                    'kaco_automation_auto_approve' => '1',
                    'kaco_automation_approve_confidence' => 0.80,
                    'kaco_automation_auto_apply' => '1',
                    'kaco_automation_apply_confidence' => 0.90,
                    'kaco_automation_auto_create_drafts' => '1',
                    'kaco_automation_generator_create_confidence' => 0.86,
                    'kaco_debug_mode' => '0',
                ),
            ),
        );
    }

    private function sanitize_automation_operating_mode($value) {
        $value = sanitize_key((string) $value);
        $modes = $this->automation_operating_modes();
        return isset($modes[$value]) ? $value : 'balanced';
    }

    private function apply_automation_operating_mode($mode) {
        $mode = $this->sanitize_automation_operating_mode($mode);
        $modes = $this->automation_operating_modes();
        $settings = (array) ($modes[$mode]['settings'] ?? array());
        foreach ($settings as $option_name => $option_value) {
            update_option($option_name, $option_value);
        }
        update_option('kaco_automation_operating_mode', $mode);
    }

    private function automation_post_type() {
        $post_type = sanitize_key((string) get_option('kaco_automation_post_type', 'post'));
        if ($post_type === '' || !post_type_exists($post_type)) {
            return 'post';
        }
        return $post_type;
    }
}
