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

    const VERSION = '1.0.0';
    const TABLE = 'kaco_suggestions';
    const NONCE_ACTION = 'kaco_admin_action';
    const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const OPENAI_MODEL = 'gpt-4.1-mini';

    private $plugin_file;

    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        register_activation_hook($this->plugin_file, array($this, 'activate'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_post_kaco_generate_font_previews', array($this, 'handle_generate_font_previews'));
        add_action('admin_post_kaco_create_generated_drafts', array($this, 'handle_create_generated_drafts'));
        add_action('admin_post_kaco_run_audit', array($this, 'handle_run_audit'));
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
        add_option('kaco_update_template', "## Why you'll love {{post_title}}\n\n{{ai_intro}}\n\n## Visual character\n\n{{visual_analysis}}\n\n## Best for\n\n{{best_for}}\n\n## Pairing suggestions\n\n{{pairing_notes}}\n\n## Font Features\n\n{{font_features}}\n\n## What's Included\n\n{{whats_included}}\n\n## Pricing\n\n{{pricing_details}}\n\n## Verified details\n\n{{verified_details}}\n");
        add_option('kaco_openai_api_key', '');
        add_option('kaco_openai_endpoint', self::OPENAI_ENDPOINT);
        add_option('kaco_openai_model', self::OPENAI_MODEL);
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
        add_option('kaco_generator_preview_store', array());
    }

    public function register_admin_menu() {
        add_menu_page(
            'KREATIV AI Content Optimizer',
            'KREATIV AI',
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

        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : 'audit';
        $notice = isset($_GET['kaco_notice']) ? sanitize_text_field(wp_unslash($_GET['kaco_notice'])) : '';

        echo '<div class="wrap">';
        echo '<h1>KREATIV AI Content Optimizer</h1>';

        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<nav class="nav-tab-wrapper">';
        $this->tab_link('generator', 'Generator', $view);
        $this->tab_link('audit', 'Audit & Queue', $view);
        $this->tab_link('categories', 'Categories', $view);
        $this->tab_link('tags', 'Tags', $view);
        $this->tab_link('suggestions', 'Suggestions', $view);
        $this->tab_link('settings', 'Settings', $view);
        echo '</nav>';

        if ($view === 'generator') {
            $this->render_generator_view();
        } elseif ($view === 'categories') {
            $this->render_categories_view();
        } elseif ($view === 'tags') {
            $this->render_tags_view();
        } elseif ($view === 'suggestions') {
            $this->render_suggestions_view();
        } elseif ($view === 'settings') {
            $this->render_settings_view();
        } else {
            $this->render_audit_view();
        }

        echo '</div>';
    }
}
