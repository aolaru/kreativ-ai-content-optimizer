<?php

trait KACO_AI_Generator_Trait {
    private function generator_manual_preview_limit() {
        return 1;
    }

    private function diagnostics_enabled() {
        return get_option('kaco_debug_mode', '0') === '1';
    }

    private function build_error_result($code, $message, $debug = array()) {
        return new WP_Error((string) $code, (string) $message, array('debug' => is_array($debug) ? $debug : array()));
    }

    private function extract_error_debug($error) {
        if ($error instanceof WP_Error) {
            $data = $error->get_error_data();
            if (is_array($data)) {
                return $data['debug'] ?? $data;
            }
        }
        return array();
    }

    public function handle_retry_generator_review_item() {
        $this->require_admin_request();
        $queue_key = (int) ($_POST['queue_key'] ?? -1);
        $items = $this->get_generator_automation_review();
        if (!isset($items[$queue_key])) {
            $this->redirect_with_notice('Generator review item not found.', 'review');
        }

        $url = esc_url_raw((string) ($items[$queue_key]['url'] ?? ''));
        if ($url === '') {
            $this->redirect_with_notice('Generator review item is missing a source URL.', 'review');
        }

        $preview = $this->request_generator_preview($url);
        if (is_wp_error($preview)) {
            $items[$queue_key]['automation_error'] = $preview->get_error_message();
            $items[$queue_key]['diagnostics'] = $this->extract_error_debug($preview);
            $this->set_generator_automation_review($items);
            $this->redirect_with_notice('Generator preview retry failed: ' . $preview->get_error_message(), 'review');
        }

        $preview['preview_source'] = 'automation';
        $items[$queue_key] = $preview;
        $this->set_generator_automation_review($items);
        $this->redirect_with_notice('Generator review item retried successfully.', 'review');
    }

    public function handle_discard_generator_review_item() {
        $this->require_admin_request();
        $queue_key = (int) ($_POST['queue_key'] ?? -1);
        $items = $this->get_generator_automation_review();
        if (!isset($items[$queue_key])) {
            $this->redirect_with_notice('Generator review item not found.', 'review');
        }

        unset($items[$queue_key]);
        $this->set_generator_automation_review(array_values($items));
        $this->redirect_with_notice('Generator review item removed.', 'review');
    }

    public function handle_create_generator_review_item() {
        $this->require_admin_request();
        $queue_key = (int) ($_POST['queue_key'] ?? -1);
        $items = $this->get_generator_automation_review();
        if (!isset($items[$queue_key])) {
            $this->redirect_with_notice('Generator review item not found.', 'review');
        }

        $post_id = $this->create_generated_draft_from_preview($items[$queue_key]);
        if (is_wp_error($post_id) || (int) $post_id <= 0) {
            $items[$queue_key]['automation_error'] = is_wp_error($post_id) ? $post_id->get_error_message() : 'Draft creation failed.';
            $this->set_generator_automation_review($items);
            $message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Draft creation failed.';
            $this->redirect_with_notice('Generator review item could not be created: ' . $message, 'review');
        }

        unset($items[$queue_key]);
        $this->set_generator_automation_review(array_values($items));
        $this->redirect_with_notice('Generator review item created successfully.', 'review');
    }

    public function handle_add_generator_urls_to_inbox() {
        $this->require_admin_request();

        $raw_urls = (string) wp_unslash($_POST['kaco_generator_inbox_urls'] ?? '');
        if ($raw_urls === '') {
            $raw_urls = (string) wp_unslash($_POST['kaco_generator_urls'] ?? '');
        }
        $urls = $this->normalize_generator_urls($raw_urls);
        if (empty($urls)) {
            $this->redirect_with_notice('No marketplace URLs were provided for the inbox.', 'generator');
        }

        $inbox = $this->get_generator_url_inbox();
        $map = array_fill_keys($inbox, true);
        $added = 0;
        foreach ($urls as $url) {
            if (isset($map[$url])) {
                continue;
            }
            $inbox[] = $url;
            $map[$url] = true;
            $added++;
        }
        $this->set_generator_url_inbox($inbox);
        $this->redirect_with_notice($added . ' URL(s) added to the generator inbox.', 'generator');
    }

    private function generate_ai_for_row($row) {
        $post_id = (int) $row['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $audit = json_decode($row['audit_data'], true);
        $suggestion = json_decode($row['suggestion_data'], true);
        if (!is_array($suggestion)) {
            $suggestion = array();
        }

        $ai = $this->request_ai_suggestion($post, is_array($audit) ? $audit : array());
        if (is_wp_error($ai)) {
            return $ai;
        }
        if (!$ai) {
            return $this->build_error_result('optimizer_ai_failed', 'AI generation returned no usable payload.', array(
                'stage' => 'optimizer_ai',
                'post_id' => $post_id,
            ));
        }

        $suggestion['ai'] = $ai;
        $suggestion['ai_generated_at'] = gmdate('Y-m-d H:i:s');

        $this->update_suggestion_payload((int) $row['id'], $suggestion);
        $this->update_suggestion_status((int) $row['id'], $this->passes_ai_confidence($ai) ? 'pending' : 'needs_review', get_current_user_id());
        return true;
    }

    private function request_ai_suggestion($post, $audit) {
        $api_key = (string) get_option('kaco_openai_api_key', '');
        $endpoint = (string) get_option('kaco_openai_endpoint', self::OPENAI_ENDPOINT);
        $model = (string) get_option('kaco_openai_model', self::OPENAI_MODEL);
        $editorial_style_guide = (string) get_option('kaco_editorial_style_guide', '');

        if ($api_key === '' || $endpoint === '' || $model === '') {
            return false;
        }

        $system_prompt = 'You are an SEO content optimizer for a font marketplace. Return valid minified JSON only. No markdown. Preserve factual safety.';
        $user_prompt = wp_json_encode(array(
            'task' => 'Create safe suggested improvements for a WordPress font post.',
            'output_schema' => array(
                'title' => 'string in format: Font Name - four word descriptor',
                'refreshed_intro' => 'string',
                'visual_analysis' => 'string',
                'best_for' => array('3 to 5 specific use cases'),
                'pairing_notes' => array('2 to 4 specific pairing recommendations'),
                'font_features' => array('verified feature bullets only'),
                'whats_included' => array('verified included items only'),
                'pricing_details' => array('verified pricing bullets only'),
                'verified_details' => array('verified fact bullets only from supplied content'),
                'content_append' => 'string',
                'excerpt' => 'string',
                'term_descriptions' => array(
                    'category' => array(
                        array(
                            'term' => 'string',
                            'description' => 'string',
                        ),
                    ),
                ),
                'font_category_hierarchy' => array(
                    'designer_names' => array('1 or more designer names'),
                    'foundry_name' => 'string',
                    'font_style_name' => 'one of the fixed font styles only',
                    'font_mood_names' => array('1 or more fixed font moods'),
                    'font_use_case_names' => array('1 or more fixed font use cases'),
                    'notes' => 'string',
                ),
                'evidence' => array(
                    'designer' => 'string',
                    'foundry' => 'string',
                    'font_style' => 'string',
                    'font_mood' => 'string',
                    'font_use_case' => 'string',
                ),
                'internal_links' => array(
                    array(
                        'url' => 'string',
                        'anchor' => 'string',
                        'reason' => 'string',
                    ),
                ),
                'confidence' => 'number 0..1',
                'notes' => 'string',
            ),
            'post' => array(
                'id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'excerpt' => (string) $post->post_excerpt,
                'content' => substr((string) $post->post_content, 0, 8000),
                'url' => get_permalink((int) $post->ID),
            ),
            'audit' => $audit,
            'category_gaps' => !empty($audit['category_desc_gaps']) ? $audit['category_desc_gaps'] : array(),
            'duplicate_candidates' => !empty($audit['duplicate_candidates']) ? $audit['duplicate_candidates'] : array(),
            'font_category_targets' => $this->font_category_parent_targets(),
            'fixed_font_styles' => $this->fixed_font_styles(),
            'fixed_font_moods' => $this->fixed_font_moods(),
            'fixed_font_use_cases' => $this->fixed_font_use_cases(),
            'font_category_hierarchy' => !empty($audit['font_category_hierarchy']) ? $audit['font_category_hierarchy'] : array(),
            'latest_related_links' => $this->suggest_related_links((int) $post->ID),
            'editorial_style_guide' => $editorial_style_guide,
            'constraints' => array(
                'no_html_script',
                'no_keyword_stuffing',
                'add_internal_links_that_are_relevant_only',
                'tone_clear_and_commercial_but_not_hype',
                'intro_must_be_2_to_4_sentences',
                'category_descriptions_must_be_original_and_specific',
                'for_fonts_posts_extract_designers_foundry_font_style_if_confident',
                'for_fonts_posts_propose_child_categories_under_designer_foundry_font_style',
                'font_name_must_match_the_existing_post_title_font_name',
                'if_designer_or_foundry_is_uncertain_leave_it_blank_do_not_guess',
                'font_style_name_must_be_exactly_one_of_the_fixed_font_styles',
                'infer_font_style_from_description_and_post_presentation_when_possible',
                'font_mood_names_must_be_one_or_more_items_from_the_fixed_font_moods',
                'infer_one_or_more_font_moods_from_description_and_post_presentation_when_possible',
                'font_use_case_names_must_be_one_or_more_items_from_the_fixed_font_use_cases',
                'infer_one_or_more_font_use_cases_from_description_and_post_presentation_when_possible',
                'if_title_is_regenerated_keep_original_font_name_and_add_exactly_four_descriptive_words_after_dash',
                'avoid_generic_marketing_filler',
                'use_at_least_two_concrete_visual_observations',
                'best_for_must_be_specific_not_generic',
                'pairing_notes_must_recommend_contrasting_or_supporting_font_directions_and_explain_why_each_match_works',
                'font_features_whats_included_and_pricing_must_only_use_explicitly_supported_facts',
                'verified_details_must_only_use_explicitly_supported_facts',
            ),
        ));

        $decoded = $this->request_openai_json_payload($api_key, $endpoint, $model, $system_prompt, $user_prompt);
        if (is_wp_error($decoded)) {
            return $decoded;
        }
        if (!is_array($decoded)) {
            return $this->build_error_result('optimizer_ai_invalid_payload', 'AI generation did not return the expected payload.', array(
                'stage' => 'optimizer_ai',
                'post_id' => (int) $post->ID,
            ));
        }
        return $this->sanitize_ai_payload($decoded);
    }

    public function handle_generate_font_previews() {
        $this->require_admin_request();

        $urls = $this->normalize_generator_urls((string) wp_unslash($_POST['kaco_generator_urls'] ?? ''));

        if (empty($urls)) {
            $this->redirect_with_notice('No marketplace URLs were provided.', 'generator');
        }

        $manual_limit = $this->generator_manual_preview_limit();
        if (count($urls) > $manual_limit) {
            $this->redirect_with_notice(
                'Generate now accepts up to ' . $manual_limit . ' URL(s) at a time. Use Automation Queue for larger batches.',
                'generator'
            );
        }

        $previews = array();
        foreach ($urls as $url) {
            $preview = $this->request_generator_preview($url, 'fast');
            if (is_wp_error($preview)) {
                $previews[] = array(
                    'url' => esc_url_raw($url),
                    'title' => '',
                    'image_url' => '',
                    'designer_names' => array(),
                    'foundry_name' => '',
                    'font_style_name' => '',
                    'font_mood_names' => array(),
                    'font_use_case_names' => array(),
                    'tags' => array(),
                    'content' => '<p>Generation failed for this URL. Check the OpenAI settings or edit this draft manually.</p>',
                    'automation_error' => $preview->get_error_message(),
                    'diagnostics' => $this->extract_error_debug($preview),
                );
                continue;
            }
            $previews[] = $preview;
        }

        $this->set_generator_previews($previews);
        $this->redirect_with_notice(
            count($previews) . ' preview(s) generated. High-confidence items can be created immediately; failed items will stay here for review.',
            'generator'
        );
    }

    public function handle_create_generated_drafts() {
        $this->require_admin_request();

        $previews = !empty($_POST['previews']) && is_array($_POST['previews']) ? (array) wp_unslash($_POST['previews']) : array();
        if (empty($previews)) {
            $this->redirect_with_notice('No previews were submitted.', 'generator');
        }

        $created = 0;
        $skipped = 0;
        $discarded = 0;
        $errors = array();
        $remaining_manual = array();
        $remaining_automation = array();
        foreach ($previews as $preview) {
            $source = sanitize_key((string) ($preview['preview_source'] ?? 'manual'));
            if (!empty($preview['discard'])) {
                $discarded++;
                continue;
            }
            if (empty($preview['create'])) {
                if ($source === 'automation') {
                    $remaining_automation[] = $preview;
                } else {
                    $remaining_manual[] = $preview;
                }
                continue;
            }

            $post_id = $this->create_generated_draft_from_preview($preview);
            if (is_wp_error($post_id)) {
                $errors[] = $post_id->get_error_message();
                $skipped++;
                $preview['automation_error'] = $post_id->get_error_message();
                if ($source === 'automation') {
                    $remaining_automation[] = $preview;
                } else {
                    $remaining_manual[] = $preview;
                }
            } elseif ($post_id > 0) {
                $created++;
            } else {
                $skipped++;
                if ($source === 'automation') {
                    $remaining_automation[] = $preview;
                } else {
                    $remaining_manual[] = $preview;
                }
            }
        }

        $this->set_generator_previews($remaining_manual);
        $this->set_generator_automation_review($remaining_automation);
        $remaining_review = count($remaining_manual) + count($remaining_automation);
        $notice = $created . ' draft(s) created';
        if ($remaining_review > 0) {
            $notice .= ', ' . $remaining_review . ' still need review';
        }
        if ($discarded > 0) {
            $notice .= ', ' . $discarded . ' removed from queue';
        }
        if ($skipped > 0 && $remaining_review === 0) {
            $notice .= ', ' . $skipped . ' skipped';
        }
        $notice .= '.';
        if (!empty($errors)) {
            $notice .= ' Last error: ' . $errors[0];
        }
        $this->redirect_with_notice($notice, 'generator');
    }

    private function normalize_generator_urls($raw_urls) {
        $urls = preg_split('/\r\n|\r|\n/', (string) $raw_urls);
        $urls = array_values(array_filter(array_map('trim', (array) $urls)));
        $normalized = array();
        foreach ($urls as $url) {
            $url = esc_url_raw((string) $url);
            if ($url !== '' && !in_array($url, $normalized, true)) {
                $normalized[] = $url;
            }
        }
        return $normalized;
    }

    private function request_generator_preview($url, $mode = 'full') {
        $api_key = (string) get_option('kaco_openai_api_key', '');
        $endpoint = (string) get_option('kaco_openai_endpoint', self::OPENAI_ENDPOINT);
        $model = (string) get_option('kaco_openai_model', self::OPENAI_MODEL);
        $editorial_style_guide = (string) get_option('kaco_editorial_style_guide', '');
        $mode = $mode === 'fast' ? 'fast' : 'full';

        $url = esc_url_raw((string) $url);
        if ($api_key === '' || $endpoint === '' || $model === '' || $url === '') {
            return $this->build_error_result('missing_generator_config', 'OpenAI configuration or source URL is missing.', array(
                'stage' => 'config',
                'url' => $url,
                'has_api_key' => $api_key !== '',
                'endpoint' => $endpoint,
                'model' => $model,
            ));
        }

        $targets = $this->font_category_parent_targets();
        $source_result = $this->fetch_source_context($url);
        if (is_wp_error($source_result)) {
            return $source_result;
        }
        $source_context = is_array($source_result) ? $source_result : array();
        $font_name_hint = $this->infer_font_name_from_source_url($url, $source_context);
        $foundry_hint = $this->infer_foundry_name_from_source_url($url, $source_context);
        $source_entity_hints = $this->extract_source_entity_hints($url, $source_context, $font_name_hint, $foundry_hint);
        $system_prompt = $mode === 'fast'
            ? 'You generate fast structured preview proposals for commercial font review posts. Return valid minified JSON only. No markdown code fences. Keep the output lean and factual.'
            : 'You generate structured draft proposals for commercial font review posts. Return valid minified JSON only. No markdown code fences. Do not invent technical features that are not clearly supported.';
        $user_prompt = wp_json_encode(array(
            'task' => $mode === 'fast'
                ? 'Generate a fast preview proposal from a marketplace URL. Prioritize title, core entities, short summary, and one concise intro paragraph.'
                : 'Generate a draft-ready font post proposal from a marketplace URL.',
            'generation_mode' => $mode,
            'source_url' => $url,
            'source_hints' => array(
                'font_name_hint' => $font_name_hint,
                'foundry_hint' => $foundry_hint,
                'designer_hints' => $source_entity_hints['designer_names'],
                'foundry_context_hint' => $source_entity_hints['foundry_name'],
            ),
            'source_context' => $source_context,
            'output_schema' => $mode === 'fast'
                ? array(
                    'title' => 'string',
                    'image_url' => 'string',
                    'designer_names' => array('1 or more designer names'),
                    'foundry_name' => 'string',
                    'font_style_name' => 'one of the fixed font styles only',
                    'font_mood_names' => array('1 or more fixed font moods'),
                    'font_use_case_names' => array('1 or more fixed font use cases'),
                    'tags' => array('3 to 8 specific tag strings'),
                    'summary_excerpt' => 'single sentence, 120 to 200 characters, factual and natural',
                    'refreshed_intro' => '1 concise paragraph',
                    'evidence' => array(
                        'designer' => 'string',
                        'foundry' => 'string',
                        'font_style' => 'string',
                        'font_mood' => 'string',
                        'font_use_case' => 'string',
                    ),
                    'confidence' => 'number 0..1',
                )
                : array(
                    'title' => 'string',
                    'image_url' => 'string',
                    'designer_names' => array('1 or more designer names'),
                    'foundry_name' => 'string',
                    'font_style_name' => 'one of the fixed font styles only',
                    'font_mood_names' => array('1 or more fixed font moods'),
                    'font_use_case_names' => array('1 or more fixed font use cases'),
                    'tags' => array('5 to 10 specific tag strings'),
                    'summary_excerpt' => 'single sentence, 150 to 220 characters, factual and natural',
                    'refreshed_intro' => 'string',
                    'visual_analysis' => 'string',
                    'best_for' => array('3 to 5 specific use cases'),
                    'pairing_notes' => array('2 to 4 specific pairing recommendations'),
                    'font_features' => array('verified feature bullets only'),
                    'whats_included' => array('verified included items only'),
                    'pricing_details' => array('verified pricing bullets only'),
                    'verified_details' => array('verified fact bullets only'),
                    'evidence' => array(
                        'designer' => 'string',
                        'foundry' => 'string',
                        'font_style' => 'string',
                        'font_mood' => 'string',
                        'font_use_case' => 'string',
                    ),
                    'confidence' => 'number 0..1',
                ),
            'required_sections' => $mode === 'fast'
                ? array(
                    'single-sentence summary',
                    'one concise intro paragraph',
                )
                : array(
                    '2 to 4 sentence intro',
                    'visual analysis paragraph',
                    'specific best-for items',
                    'pairing notes paragraph',
                    'verified details list',
                ),
            'house_rules' => $mode === 'fast'
                ? array(
                    'keep the marketplace purchase link in the CTA and first mention',
                    'summary_excerpt must be exactly one sentence and about 120 to 200 characters',
                    'summary_excerpt must feel natural and editorial, not templated',
                    'summary_excerpt must not include URLs, marketplace boilerplate, or free-download warnings',
                    'write like an editorial font reviewer, not a generic marketplace summary',
                    'do not repeat the foundry name as the font name or the font name as the foundry',
                    'use source_context and source_hints as primary evidence, do not infer entities from brand names loosely',
                    'do not swap font name and foundry name',
                    'title_and_cta_must_use_the_font_name_hint_if_it_is_present',
                    'if_designer_or_foundry_is_uncertain_leave_it_blank_do_not_guess',
                    'do not claim free download availability',
                    'title should be in format Font Name - four word descriptor when possible',
                    'keep the response lean for speed',
                )
                : array(
                    'keep the marketplace purchase link in the CTA and first mention',
                    'summary_excerpt must be exactly one sentence and about 150 to 220 characters',
                    'summary_excerpt must feel natural and editorial, not templated',
                    'summary_excerpt must not include URLs, marketplace boilerplate, or free-download warnings',
                    'write like an editorial font reviewer, not a generic marketplace summary',
                    'avoid filler phrases like versatile, unique touch, reliable choice, and suitable for various projects',
                    'use at least two concrete visual observations',
                    'best use cases must be specific to the font style',
                    'avoid generic use case bullets such as branding and logos, editorial and social media, and packaging and display unless the source clearly justifies them',
                    'do not repeat the foundry name as the font name or the font name as the foundry',
                    'use source_context and source_hints as primary evidence, do not infer entities from brand names loosely',
                    'do not swap font name and foundry name',
                    'pairing notes must recommend 2 to 4 contrasting or supporting font directions and explain why each pairing works',
                    'title_and_cta_must_use_the_font_name_hint_if_it_is_present',
                    'if_designer_or_foundry_is_uncertain_leave_it_blank_do_not_guess',
                    'font_features_whats_included_and_pricing_must_only_use_explicitly_supported_facts',
                    'verified details must only include facts that are likely explicit on the source page',
                    'do not claim free download availability',
                    'title should be in format Font Name - four word descriptor when possible',
                ),
            'category_targets' => $targets,
            'fixed_font_styles' => $this->fixed_font_styles(),
            'fixed_font_moods' => $this->fixed_font_moods(),
            'fixed_font_use_cases' => $this->fixed_font_use_cases(),
            'editorial_style_guide' => $editorial_style_guide,
        ));

        $decoded = $this->request_openai_json_payload($api_key, $endpoint, $model, $system_prompt, $user_prompt);
        if (is_wp_error($decoded)) {
            return $decoded;
        }

        return $this->sanitize_generator_preview($decoded, $url, $source_context);
    }

    private function sanitize_generator_preview($payload, $url, $source_context = array()) {
        $tags = array();
        if (!empty($payload['tags']) && is_array($payload['tags'])) {
            foreach ($payload['tags'] as $tag) {
                $tag = sanitize_text_field((string) $tag);
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        $best_for = array();
        if (!empty($payload['best_for']) && is_array($payload['best_for'])) {
            foreach ($payload['best_for'] as $item) {
                $item = sanitize_text_field((string) $item);
                if ($item !== '') {
                    $best_for[] = $item;
                }
            }
        }

        $verified_details = array();
        if (!empty($payload['verified_details']) && is_array($payload['verified_details'])) {
            foreach ($payload['verified_details'] as $item) {
                $item = sanitize_text_field((string) $item);
                if ($item !== '') {
                    $verified_details[] = $item;
                }
            }
        }

        $pairing_notes = $this->sanitize_pairing_notes($payload['pairing_notes'] ?? array());
        $font_features = $this->sanitize_simple_bullets($payload['font_features'] ?? array(), 8);
        $whats_included = $this->sanitize_simple_bullets($payload['whats_included'] ?? array(), 8);
        $pricing_details = $this->sanitize_simple_bullets($payload['pricing_details'] ?? array(), 5);

        if (empty($source_context)) {
            $source_result = $this->fetch_source_context($url);
            if (is_wp_error($source_result)) {
                return $source_result;
            }
            $source_context = is_array($source_result) ? $source_result : array();
        }
        $font_name_hint = $this->infer_font_name_from_source_url($url, $source_context);
        $foundry_hint = $this->infer_foundry_name_from_source_url($url, $source_context);
        $source_entity_hints = $this->extract_source_entity_hints($url, $source_context, $font_name_hint, $foundry_hint);
        $source_text = $this->build_source_text_blob($source_context);
        $fact_index = $this->build_source_fact_index($source_context);
        $title = $this->sanitize_generated_title((string) ($payload['title'] ?? ''), $font_name_hint);
        $font_name = $font_name_hint !== '' ? $font_name_hint : $this->extract_font_name_from_title($title);
        if ($font_name === '') {
            $font_name = $title;
        }
        $summary_excerpt = $this->sanitize_generated_summary_excerpt((string) ($payload['summary_excerpt'] ?? ''), $font_name);

        $ai_designer_names = $this->sanitize_name_list($payload['designer_names'] ?? ($payload['designer_name'] ?? array()));
        $designer_resolution = $this->resolve_designer_names(
            $ai_designer_names,
            $source_entity_hints,
            (array) ($payload['evidence'] ?? array())
        );
        $designer_names = $designer_resolution['designer_names'];
        $foundry_name = sanitize_text_field((string) ($payload['foundry_name'] ?? ''));
        if ($foundry_name === '' && !empty($source_entity_hints['foundry_name'])) {
            $foundry_name = sanitize_text_field((string) $source_entity_hints['foundry_name']);
        }
        if ($foundry_name === '' && $foundry_hint !== '') {
            $foundry_name = $foundry_hint;
        }
        if ($foundry_name !== '' && $font_name !== '' && strcasecmp($foundry_name, $font_name) === 0 && $foundry_hint !== '' && strcasecmp($foundry_hint, $font_name) !== 0) {
            $foundry_name = $foundry_hint;
        }
        $font_mood_names = $this->sanitize_canonical_name_list($payload['font_mood_names'] ?? ($payload['font_mood_name'] ?? array()), 'canonical_font_mood_name');
        $font_use_case_names = $this->sanitize_canonical_name_list($payload['font_use_case_names'] ?? ($payload['font_use_case_name'] ?? array()), 'canonical_font_use_case_name');
        $font_features = $this->filter_supported_fact_bullets($font_features, $fact_index, array('style', 'weight', 'italic', 'condensed', 'ligature', 'alternate', 'glyph', 'multilingual', 'desktop', 'webfont', 'woff', 'otf', 'ttf'));
        $whats_included = $this->filter_supported_fact_bullets($whats_included, $fact_index, array('family', 'styles', 'font', 'desktop', 'webfont', 'woff', 'otf', 'ttf', 'package'));
        $pricing_details = $this->filter_supported_fact_bullets($pricing_details, $fact_index, array('$', 'usd', 'price', 'pricing', 'from', 'sale', 'off', 'family pack'));
        $verified_details = $this->filter_supported_fact_bullets($verified_details, $fact_index, array('designed', 'published', 'style', 'weights', 'italic', 'condensed', 'desktop', 'webfont', '$', 'usd', 'family', 'styles'));
        $fact_evidence = $this->build_fact_evidence_map(array_merge($font_features, $whats_included, $pricing_details, $verified_details), $fact_index);
        $refreshed_intro = $this->polish_generator_intro((string) ($payload['refreshed_intro'] ?? ''), $font_name, $foundry_name);

        $font_name = $this->normalize_marketplace_label_case($font_name, $source_context);
        $title = $this->sanitize_generated_title($title, $font_name);
        $foundry_name = $this->normalize_marketplace_label_case($foundry_name, $source_context);
        $designer_names = array_values(array_filter(array_map(function($name) use ($source_context) {
            return $this->normalize_marketplace_label_case((string) $name, $source_context);
        }, $designer_names)));
        $font_style_name = $this->canonical_font_style_name((string) ($payload['font_style_name'] ?? ''));
        $designer_evidence = $designer_resolution['evidence'];
        if ($designer_evidence === '' && !empty($payload['evidence']['designer'])) {
            $designer_evidence = sanitize_text_field((string) $payload['evidence']['designer']);
        }
        $model_confidence = max(0, min(1, (float) ($payload['confidence'] ?? 0)));
        $confidence = $this->calculate_generator_confidence(
            $model_confidence,
            $font_name,
            $font_name_hint,
            $foundry_name,
            $foundry_hint,
            $designer_resolution,
            $font_style_name,
            $font_mood_names,
            $font_use_case_names,
            $verified_details,
            $fact_evidence
        );
        if ($summary_excerpt === '') {
            $summary_excerpt = $this->build_generated_summary_excerpt(array(
                'title' => $title,
                'font_style_name' => $font_style_name,
                'designer_names' => $designer_names,
                'foundry_name' => $foundry_name,
                'font_mood_names' => $font_mood_names,
                'font_use_case_names' => $font_use_case_names,
            ));
        }

        $result = array(
            'url' => esc_url_raw($url),
            'title' => $title,
            'image_url' => esc_url_raw((string) ($payload['image_url'] ?? ($source_context['image_url'] ?? ''))),
            'designer_names' => $designer_names,
            'foundry_name' => $foundry_name,
            'font_style_name' => $font_style_name,
            'font_mood_names' => $font_mood_names,
            'font_use_case_names' => $font_use_case_names,
            'tags' => array_slice(array_values(array_unique($tags)), 0, 12),
            'summary_excerpt' => $summary_excerpt,
            'refreshed_intro' => wp_kses_post($refreshed_intro),
            'visual_analysis' => wp_kses_post((string) ($payload['visual_analysis'] ?? '')),
            'best_for' => array_slice(array_values(array_unique($best_for)), 0, 5),
            'pairing_notes' => $pairing_notes,
            'font_features' => $font_features,
            'whats_included' => $whats_included,
            'pricing_details' => $pricing_details,
            'verified_details' => array_slice(array_values(array_unique($verified_details)), 0, 8),
            'fact_evidence' => $fact_evidence,
            'evidence' => array(
                'designer' => $designer_evidence,
                'foundry' => sanitize_text_field((string) (($payload['evidence']['foundry'] ?? ''))),
                'font_style' => sanitize_text_field((string) (($payload['evidence']['font_style'] ?? ''))),
                'font_mood' => sanitize_text_field((string) (($payload['evidence']['font_mood'] ?? ''))),
                'font_use_case' => sanitize_text_field((string) (($payload['evidence']['font_use_case'] ?? ''))),
            ),
            'confidence' => $confidence,
            'content' => $this->build_generated_font_content(array(
                'title' => $title,
                'font_name' => $font_name,
                'url' => esc_url_raw($url),
                'image_url' => esc_url_raw((string) ($payload['image_url'] ?? ($source_context['image_url'] ?? ''))),
                'designer_names' => $designer_names,
                'foundry_name' => $foundry_name,
                'font_style_name' => $font_style_name,
                'font_mood_names' => $font_mood_names,
                'font_use_case_names' => $font_use_case_names,
                'refreshed_intro' => wp_kses_post($refreshed_intro),
                'visual_analysis' => wp_kses_post((string) ($payload['visual_analysis'] ?? '')),
                'best_for' => array_slice(array_values(array_unique($best_for)), 0, 5),
                'pairing_notes' => $pairing_notes,
                'font_features' => $font_features,
                'whats_included' => $whats_included,
                'pricing_details' => $pricing_details,
                'verified_details' => array_slice(array_values(array_unique($verified_details)), 0, 8),
                'fact_evidence' => $fact_evidence,
            )),
        );
        if ($this->diagnostics_enabled()) {
            $result['diagnostics'] = array(
                'stage' => 'preview_ready',
                'source_summary' => array(
                    'title' => (string) ($source_context['title'] ?? ''),
                    'has_description' => !empty($source_context['description']),
                    'has_text_excerpt' => !empty($source_context['text_excerpt']),
                    'has_image_url' => !empty($source_context['image_url']),
                    'http_code' => isset($source_context['http_code']) ? (int) $source_context['http_code'] : 0,
                    'degraded_fetch' => !empty($source_context['degraded_fetch']),
                ),
                'entity_hints' => array(
                    'font_name_hint' => $font_name_hint,
                    'foundry_hint' => $foundry_hint,
                    'source_designer_names' => (array) ($source_entity_hints['designer_names'] ?? array()),
                ),
                'fact_counts' => array(
                    'verified_details' => count((array) $verified_details),
                    'fact_evidence' => count((array) $fact_evidence),
                ),
            );
        }
        return $result;
    }

    private function resolve_designer_names($ai_designer_names, $source_entity_hints, $evidence) {
        $source_designer_names = !empty($source_entity_hints['designer_names']) ? $this->sanitize_name_list($source_entity_hints['designer_names']) : array();
        $source_evidence = sanitize_text_field((string) ($source_entity_hints['designer_evidence'] ?? ''));
        $ai_evidence = sanitize_text_field((string) ($evidence['designer'] ?? ''));

        if (!empty($source_designer_names)) {
            return array(
                'designer_names' => $source_designer_names,
                'confidence' => 0.95,
                'evidence' => $source_evidence !== '' ? $source_evidence : $ai_evidence,
            );
        }

        if (!empty($ai_designer_names) && $this->designer_evidence_is_explicit($ai_evidence, $ai_designer_names)) {
            return array(
                'designer_names' => $ai_designer_names,
                'confidence' => 0.75,
                'evidence' => $ai_evidence,
            );
        }

        return array(
            'designer_names' => array(),
            'confidence' => 0.35,
            'evidence' => $source_evidence !== '' ? $source_evidence : $ai_evidence,
        );
    }

    private function calculate_generator_confidence($model_confidence, $font_name, $font_name_hint, $foundry_name, $foundry_hint, $designer_resolution, $font_style_name, $font_mood_names, $font_use_case_names, $verified_details, $fact_evidence) {
        $font_name_confidence = 0.25;
        if ($font_name !== '' && $font_name_hint !== '') {
            $font_name_confidence = strcasecmp($font_name, $font_name_hint) === 0 ? 1.0 : 0.65;
        } elseif ($font_name !== '') {
            $font_name_confidence = 0.7;
        }

        $foundry_confidence = 0.25;
        if ($foundry_name !== '' && $foundry_hint !== '') {
            $foundry_confidence = strcasecmp($foundry_name, $foundry_hint) === 0 ? 1.0 : 0.65;
        } elseif ($foundry_name !== '') {
            $foundry_confidence = 0.65;
        }

        $style_confidence = $font_style_name !== '' ? 1.0 : 0.2;
        $mood_confidence = !empty($font_mood_names) ? min(1.0, 0.55 + (0.15 * count((array) $font_mood_names))) : 0.2;
        $use_case_confidence = !empty($font_use_case_names) ? min(1.0, 0.55 + (0.10 * count((array) $font_use_case_names))) : 0.2;

        $fact_total = max(1, count((array) $verified_details));
        $fact_supported = count((array) $fact_evidence);
        $fact_confidence = !empty($verified_details)
            ? max(0.2, min(1.0, $fact_supported / $fact_total))
            : 0.45;

        $score =
            (0.22 * max(0, min(1, (float) $model_confidence))) +
            (0.24 * max(0, min(1, (float) ($designer_resolution['confidence'] ?? 0)))) +
            (0.16 * $font_name_confidence) +
            (0.14 * $foundry_confidence) +
            (0.08 * $style_confidence) +
            (0.06 * $mood_confidence) +
            (0.05 * $use_case_confidence) +
            (0.05 * $fact_confidence);

        if (empty($designer_resolution['designer_names'])) {
            $score -= 0.05;
        }
        if ($foundry_name === '') {
            $score -= 0.03;
        }

        return max(0, min(1, round($score, 2)));
    }

    private function sanitize_pairing_notes($value) {
        $notes = array();
        if (is_array($value)) {
            foreach ($value as $item) {
                $item = sanitize_text_field((string) $item);
                if ($item !== '') {
                    $notes[] = $item;
                }
            }
        } else {
            $item = sanitize_text_field((string) $value);
            if ($item !== '') {
                $notes[] = $item;
            }
        }
        return array_slice(array_values(array_unique($notes)), 0, 4);
    }

    private function sanitize_simple_bullets($value, $limit) {
        $items = array();
        if (is_array($value)) {
            foreach ($value as $item) {
                $item = sanitize_text_field((string) $item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
        } else {
            $item = sanitize_text_field((string) $value);
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return array_slice(array_values(array_unique($items)), 0, (int) $limit);
    }

    private function sanitize_name_list($value) {
        $items = array();
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        foreach ((array) $value as $item) {
            $item = sanitize_text_field((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return array_slice(array_values(array_unique($items)), 0, 8);
    }

    private function sanitize_canonical_name_list($value, $callback) {
        $items = array();
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        foreach ((array) $value as $item) {
            $item = method_exists($this, $callback) ? $this->{$callback}((string) $item) : '';
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return array_slice(array_values(array_unique($items)), 0, 8);
    }

    private function build_generated_font_content($data) {
        $font_name = sanitize_text_field((string) ($data['font_name'] ?? ''));
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        $url = esc_url((string) ($data['url'] ?? ''));
        $image_url = esc_url((string) ($data['image_url'] ?? ''));
        $designer_names = !empty($data['designer_names']) && is_array($data['designer_names']) ? (array) $data['designer_names'] : array();
        $foundry_name = sanitize_text_field((string) ($data['foundry_name'] ?? ''));
        $font_style_name = sanitize_text_field((string) ($data['font_style_name'] ?? ''));
        $font_mood_names = !empty($data['font_mood_names']) && is_array($data['font_mood_names']) ? (array) $data['font_mood_names'] : array();
        $font_use_case_names = !empty($data['font_use_case_names']) && is_array($data['font_use_case_names']) ? (array) $data['font_use_case_names'] : array();
        $refreshed_intro = wp_kses_post((string) ($data['refreshed_intro'] ?? ''));
        $visual_analysis = wp_kses_post((string) ($data['visual_analysis'] ?? ''));
        $pairing_notes = !empty($data['pairing_notes']) && is_array($data['pairing_notes']) ? (array) $data['pairing_notes'] : array();
        $font_features = !empty($data['font_features']) && is_array($data['font_features']) ? (array) $data['font_features'] : array();
        $whats_included = !empty($data['whats_included']) && is_array($data['whats_included']) ? (array) $data['whats_included'] : array();
        $pricing_details = !empty($data['pricing_details']) && is_array($data['pricing_details']) ? (array) $data['pricing_details'] : array();
        $best_for = !empty($data['best_for']) && is_array($data['best_for']) ? (array) $data['best_for'] : array();
        $verified_details = !empty($data['verified_details']) && is_array($data['verified_details']) ? (array) $data['verified_details'] : array();
        $fact_evidence = !empty($data['fact_evidence']) && is_array($data['fact_evidence']) ? (array) $data['fact_evidence'] : array();

        $parts = array();

        if ($image_url !== '') {
            $parts[] = '<img src="' . $image_url . '" alt="' . esc_attr($title !== '' ? $title : $font_name) . '" style="max-width:100%; height:auto;" />';
        }

        if ($title !== '') {
            $parts[] = '<p>' . esc_html($title) . '</p>';
        }

        if ($url !== '' && $font_name !== '') {
            $parts[] = '<p style="text-align: center"><a href="' . $url . '" class="btn btn-primary" target="_blank" rel="noopener">View &amp; Purchase ' . esc_html($font_name) . '</a></p>';
        }

        if ($font_name !== '') {
            $parts[] = '<p style="background:#f7f7f7;padding:12px;border-left:4px solid #FF3366;font-size:14px;line-height:1.6"><strong>Important Notice</strong><br> ' . esc_html($font_name) . ' is a <strong>premium commercial font</strong> and is <strong>not available for free download</strong> on Kreativ Font. To use it legally in personal or commercial projects, purchase it from an official marketplace.</p>';
        }

        $parts[] = '<p style="background:#f7f7f7;padding:12px;border-left:4px solid #00C2FF;font-size:14px;line-height:1.6;margin-top:10px">Looking for <strong>free fonts instead</strong>? Kreativ Font curates <strong>legitimately free fonts</strong> and selected free alternatives with proper licenses.<br><a href="https://kreativfont.com/free">Discover free fonts &amp; alternatives</a> OR <a href="https://www.patreon.com/cw/kreativfont" style="font-size:14px">join the Kreativ Font Free Tier</a></p>';

        if ($url !== '' && $font_name !== '') {
            $parts[] = $this->build_generated_intro_paragraph(
                $url,
                $font_name,
                $font_style_name,
                $designer_names,
                $foundry_name
            );
        }

        $tldr = $this->build_generated_tldr_block($font_name, $font_style_name, $designer_names, $foundry_name);
        if ($tldr !== '') {
            $parts[] = $tldr;
        }

        if ($refreshed_intro !== '') {
            $parts[] = '<h2>Why you should consider ' . esc_html($font_name !== '' ? $font_name : $title) . '</h2><p>' . wp_kses_post($refreshed_intro) . '</p>';
        }

        if ($visual_analysis !== '') {
            $parts[] = '<h2>Visual character</h2><p>' . wp_kses_post($visual_analysis) . '</p>';
        }

        if (!empty($best_for)) {
            $parts[] = '<h2>Best use cases</h2>' . $this->html_list($best_for);
        }

        if (!empty($pairing_notes)) {
            $parts[] = '<h2>Font pairing ideas</h2>' . $this->html_list($pairing_notes);
        }

        if (!empty($font_features)) {
            $parts[] = '<h2>Font Features</h2>' . $this->html_list($font_features);
        }

        if (!empty($whats_included)) {
            $parts[] = '<h2>What\'s Included</h2>' . $this->html_list($whats_included);
        }

        if (!empty($pricing_details)) {
            $parts[] = '<h2>Pricing</h2>' . $this->html_list($pricing_details);
        }

        $font_details = $this->build_generated_font_details_section(
            $font_style_name,
            $font_mood_names,
            $font_use_case_names,
            $designer_names,
            $foundry_name,
            $verified_details,
            $fact_evidence
        );
        if ($font_details !== '') {
            $parts[] = $font_details;
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    private function build_generated_intro_paragraph($url, $font_name, $font_style_name, $designer_names, $foundry_name) {
        $sentence = '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($font_name) . '</a>';
        if ($font_style_name !== '') {
            $sentence .= ' is a ' . esc_html(strtolower($font_style_name)) . ' typeface';
        } else {
            $sentence .= ' is a commercial typeface';
        }

        if (!empty($designer_names)) {
            $sentence .= ' from ' . esc_html($this->compact_designer_credit($designer_names));
        }
        if ($foundry_name !== '') {
            $sentence .= !empty($designer_names) ? ', published by ' : ' from ';
            $sentence .= esc_html($foundry_name);
        }
        $sentence .= '.</p>';

        return $sentence;
    }

    private function build_generated_tldr_block($font_name, $font_style_name, $designer_names, $foundry_name) {
        $font_name = sanitize_text_field((string) $font_name);
        $font_style_name = sanitize_text_field((string) $font_style_name);
        $foundry_name = sanitize_text_field((string) $foundry_name);
        $designer_names = array_values(array_filter(array_map('sanitize_text_field', (array) $designer_names)));

        if ($font_name === '') {
            return '';
        }

        $sentence = $font_name;
        if ($font_style_name !== '') {
            $sentence .= ' is a ' . $font_style_name . ' typeface';
        } else {
            $sentence .= ' is a commercial typeface';
        }
        if (!empty($designer_names)) {
            $sentence .= ' from ' . $this->compact_designer_credit($designer_names);
        }
        if ($foundry_name !== '') {
            $sentence .= !empty($designer_names) ? ', published by ' : ' from ';
            $sentence .= $foundry_name;
        }
        $sentence .= '.';

        return '<div style="background:#f7f7f7;padding:12px 14px;border-left:4px solid #111;font-size:14px;line-height:1.6"><strong>TL;DR</strong><br>' . esc_html($sentence) . '</div>';
    }

    private function build_generated_font_details_section($font_style_name, $font_mood_names, $font_use_case_names, $designer_names, $foundry_name, $verified_details, $fact_evidence) {
        $items = array();
        $seen = array();

        $add_item = function($html, $key) use (&$items, &$seen) {
            $key = strtolower(trim((string) $key));
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $items[] = $html;
        };

        if ($font_style_name !== '') {
            $add_item('<li><strong>Font Style:</strong> ' . esc_html($font_style_name) . '</li>', 'font style:' . $font_style_name);
        }
        if (!empty($font_mood_names)) {
            $add_item('<li><strong>Font Mood:</strong> ' . esc_html($this->natural_language_list($font_mood_names)) . '</li>', 'font mood:' . implode('|', $font_mood_names));
        }
        if (!empty($font_use_case_names)) {
            $add_item('<li><strong>Font Use Case:</strong> ' . esc_html($this->natural_language_list($font_use_case_names)) . '</li>', 'font use case:' . implode('|', $font_use_case_names));
        }
        if (!empty($designer_names)) {
            $add_item('<li><strong>Designer:</strong> ' . esc_html($this->natural_language_list($designer_names)) . '</li>', 'designer:' . implode('|', $designer_names));
        }
        if ($foundry_name !== '') {
            $add_item('<li><strong>Foundry:</strong> ' . esc_html($foundry_name) . '</li>', 'foundry:' . $foundry_name);
        }

        foreach ((array) $verified_details as $detail) {
            $detail = sanitize_text_field((string) $detail);
            if ($detail === '') {
                continue;
            }

            $detail_key = $this->normalize_font_detail_key($detail);
            if ($font_style_name !== '' && stripos($detail, $font_style_name) !== false && stripos($detail, 'style') !== false) {
                continue;
            }
            if ($foundry_name !== '' && stripos($detail, $foundry_name) !== false && stripos($detail, 'published') !== false) {
                continue;
            }
            if (!empty($designer_names) && $this->detail_mentions_any_name($detail, $designer_names) && (stripos($detail, 'designed') !== false || stripos($detail, 'designer') !== false)) {
                continue;
            }
            $evidence_note = '';
            if (!empty($fact_evidence[$detail])) {
                $evidence_note = ' <small style="opacity:.8;">(Source: ' . esc_html((string) $fact_evidence[$detail]) . ')</small>';
            }
            $add_item('<li>' . esc_html($detail) . $evidence_note . '</li>', $detail_key);
        }

        if (empty($items)) {
            return '';
        }

        return '<h2>Font details</h2><ul>' . implode('', $items) . '</ul>';
    }

    private function html_list($items) {
        $lines = array();
        foreach ((array) $items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $lines[] = '<li>' . esc_html($item) . '</li>';
            }
        }
        return !empty($lines) ? '<ul>' . implode('', $lines) . '</ul>' : '';
    }

    private function natural_language_list($items) {
        $items = array_values(array_filter(array_map('sanitize_text_field', (array) $items)));
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0] . ' and ' . $items[1];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ', and ' . $last;
    }

    private function smart_truncate_summary($text, $limit) {
        $cleaned = preg_replace('/\s+/', ' ', trim((string) $text));
        if (strlen($cleaned) <= (int) $limit) {
            return $cleaned;
        }
        $shortened = substr($cleaned, 0, (int) $limit);
        $shortened = preg_replace('/\s+\S*$/', '', (string) $shortened);
        return rtrim((string) $shortened, " ,;:-.") . '...';
    }

    private function sanitize_generated_summary_excerpt($value, $font_name = '') {
        $cleaned = wp_strip_all_tags((string) $value);
        $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);
        $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B.");
        if ($cleaned === '') {
            return '';
        }
        if ($font_name !== '') {
            $cleaned = preg_replace('/^' . preg_quote($font_name, '/') . '\s+is available on [^.]+\.\s*/i', '', (string) $cleaned);
        }
        $cleaned = preg_replace('/^[^.]*available on MyFonts[^.]*\.\s*/i', '', (string) $cleaned);
        $cleaned = preg_replace('/^[^.]*https?:\/\/\S+\.\s*/i', '', (string) $cleaned);
        $lowered = strtolower((string) $cleaned);
        foreach (array('available on myfonts', 'premium commercial font', 'not available for free download', 'http://', 'https://') as $banned) {
            if (strpos($lowered, $banned) !== false) {
                return '';
            }
        }
        if (substr_count($cleaned, '.') > 1) {
            return '';
        }
        if (strlen($cleaned) < 110) {
            return '';
        }
        $cleaned = $this->smart_truncate_summary($cleaned, 220);
        if (substr($cleaned, -1) !== '.') {
            $cleaned .= '.';
        }
        return $cleaned;
    }

    private function build_generated_summary_excerpt($preview) {
        $title = (string) ($preview['title'] ?? '');
        $font_name = strpos($title, ' - ') !== false ? trim((string) strstr($title, ' - ', true)) : $title;
        $style = strtolower((string) ($preview['font_style_name'] ?? ''));
        $designers = !empty($preview['designer_names']) ? (array) $preview['designer_names'] : array();
        $foundry = trim((string) ($preview['foundry_name'] ?? ''));
        $moods = array_slice(array_map('strtolower', (array) ($preview['font_mood_names'] ?? array())), 0, 3);
        $use_cases = array_slice((array) ($preview['font_use_case_names'] ?? array()), 0, 3);

        $sentence = $font_name . ' is ';
        $sentence .= $style !== '' ? 'a ' . $style . ' typeface' : 'a commercial typeface';
        if (!empty($designers)) {
            $sentence .= ' by ' . $this->compact_designer_credit($designers);
        }
        if ($foundry !== '') {
            $sentence .= ' for ' . $foundry;
        }
        if (!empty($moods)) {
            $sentence .= ' with a ' . $this->natural_language_list($moods) . ' mood';
        }
        if (!empty($use_cases)) {
            $sentence .= ' suited to ' . $this->natural_language_list($use_cases);
        }
        $sentence .= '.';

        return $this->smart_truncate_summary($sentence, 220);
    }

    private function compact_designer_credit($designer_names) {
        $designer_names = array_values(array_filter(array_map('sanitize_text_field', (array) $designer_names)));
        $count = count($designer_names);
        if ($count <= 2) {
            return $this->natural_language_list($designer_names);
        }
        return $designer_names[0] . ' and collaborators';
    }

    private function build_source_text_blob($source_context) {
        $parts = array();
        foreach (array('title', 'og_title', 'description', 'text_excerpt') as $key) {
            if (!empty($source_context[$key])) {
                $parts[] = strtolower((string) $source_context[$key]);
            }
        }
        return implode(' ', $parts);
    }

    private function filter_supported_fact_bullets($items, $fact_index, $keywords) {
        $items = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $items))));
        if (empty($fact_index['sentences'])) {
            return array();
        }

        $filtered = array();
        foreach ($items as $item) {
            $evidence = $this->find_fact_evidence($item, $fact_index, $keywords);
            if ($evidence !== '') {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    private function polish_generator_intro($intro, $font_name, $foundry_name) {
        $intro = trim(wp_strip_all_tags((string) $intro));
        if ($intro === '') {
            return '';
        }

        $intro = preg_replace('/\\s+Available for purchase on [^.]+\\.?/i', '', $intro);
        $intro = preg_replace('/\\s+available for purchase on [^.]+\\.?/i', '', $intro);
        $intro = preg_replace('/\\s+This font is available on [^.]+\\.?/i', '', $intro);
        $intro = preg_replace('/\\s+making it a comprehensive type system\\.?/i', '.', $intro);
        $intro = preg_replace('/\\s+It offers\\s+/i', ' It includes ', $intro);
        $intro = preg_replace('/\\s+/', ' ', (string) $intro);
        $intro = trim((string) $intro);

        if ($font_name !== '' && $foundry_name !== '' && stripos($intro, $font_name . ' by ' . $foundry_name) !== false) {
            $intro = preg_replace('/\\bby\\s+' . preg_quote($foundry_name, '/') . '\\b/i', '', $intro);
            $intro = preg_replace('/\\s+/', ' ', (string) $intro);
            $intro = trim((string) $intro);
        }

        return $intro;
    }

    private function normalize_font_detail_key($value) {
        $value = strtolower((string) $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        return trim((string) $value);
    }

    private function detail_mentions_any_name($detail, $names) {
        $detail = strtolower((string) $detail);
        foreach ((array) $names as $name) {
            $name = strtolower((string) $name);
            if ($name !== '' && strpos($detail, $name) !== false) {
                return true;
            }
        }
        return false;
    }

    private function build_fact_evidence_map($facts, $fact_index) {
        $map = array();
        if (empty($fact_index['sentences'])) {
            return $map;
        }
        foreach ((array) $facts as $fact) {
            $fact = sanitize_text_field((string) $fact);
            if ($fact === '') {
                continue;
            }
            $snippet = $this->find_fact_evidence($fact, $fact_index, array());
            if ($snippet !== '') {
                $map[$fact] = $snippet;
            }
        }
        return $map;
    }

    private function build_source_fact_index($source_context) {
        $source_text = trim(implode(' ', array_filter(array(
            (string) ($source_context['title'] ?? ''),
            (string) ($source_context['og_title'] ?? ''),
            (string) ($source_context['description'] ?? ''),
            (string) ($source_context['text_excerpt'] ?? ''),
        ))));
        $source_text = preg_replace('/\s+/', ' ', (string) $source_text);
        $sentences = preg_split('/(?<=[\.\!\?\:\;])\s+|\s+\|\s+|\s+[-]{2,}\s+/u', (string) $source_text);
        $indexed = array();
        foreach ((array) $sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }
            $indexed[] = array(
                'text' => $sentence,
                'normalized' => strtolower($sentence),
                'tokens' => $this->fact_tokens($sentence),
            );
        }
        return array(
            'source_text' => $source_text,
            'sentences' => $indexed,
        );
    }

    private function fact_tokens($value) {
        $tokens = preg_split('/[^a-z0-9\$\.\-]+/i', strtolower((string) $value));
        $tokens = array_values(array_filter((array) $tokens, function($token) {
            return strlen((string) $token) >= 3 || preg_match('/^\$?\d+(?:\.\d+)?$/', (string) $token);
        }));
        return array_values(array_unique($tokens));
    }

    private function find_fact_evidence($fact, $fact_index, $keywords = array()) {
        $fact = sanitize_text_field((string) $fact);
        if ($fact === '' || empty($fact_index['sentences'])) {
            return '';
        }

        $fact_normalized = strtolower($fact);
        $fact_tokens = $this->fact_tokens($fact);
        if (empty($fact_tokens)) {
            return '';
        }

        $required_keywords = array_values(array_filter(array_map('strtolower', (array) $keywords)));
        $best = '';
        $best_score = 0;
        foreach ((array) $fact_index['sentences'] as $sentence) {
            $sentence_text = (string) ($sentence['text'] ?? '');
            $sentence_normalized = (string) ($sentence['normalized'] ?? '');
            $sentence_tokens = !empty($sentence['tokens']) ? (array) $sentence['tokens'] : array();
            if ($sentence_text === '' || empty($sentence_tokens)) {
                continue;
            }

            $overlap = array_values(array_intersect($fact_tokens, $sentence_tokens));
            $score = count($overlap);
            if ($score === 0) {
                continue;
            }

            if (preg_match_all('/\$?\d+(?:\.\d+)?/', $fact, $fact_numbers) && !empty($fact_numbers[0])) {
                $number_match = false;
                foreach ((array) $fact_numbers[0] as $number) {
                    if (strpos($sentence_text, (string) $number) !== false) {
                        $number_match = true;
                        $score += 3;
                    }
                }
                if (!$number_match && preg_match('/\$|\busd\b|\beur\b|\bgbp\b/i', $fact)) {
                    continue;
                }
            }

            foreach ($required_keywords as $keyword) {
                if ($keyword !== '' && strpos($fact_normalized, $keyword) !== false && strpos($sentence_normalized, $keyword) !== false) {
                    $score += 1;
                }
            }

            if (count($fact_tokens) >= 3 && count($overlap) < 2 && !preg_match('/\$|\d/', $fact)) {
                continue;
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best = $sentence_text;
            }
        }

        return $best_score >= 2 ? $best : '';
    }

    private function extract_font_name_from_title($title) {
        $title = trim((string) $title);
        if ($title === '') {
            return '';
        }

        if (strpos($title, ' - ') !== false) {
            return trim((string) strstr($title, ' - ', true));
        }

        return $title;
    }

    private function sanitize_generated_title($title, $font_name_hint) {
        $font_name_hint = sanitize_text_field((string) $font_name_hint);
        $title = sanitize_text_field((string) $title);
        if ($font_name_hint === '') {
            return $title;
        }
        if ($title === '') {
            return $font_name_hint;
        }
        if (stripos($title, $font_name_hint . ' - ') === 0) {
            return $title;
        }
        return $font_name_hint;
    }

    private function infer_font_name_from_source_url($url, $source_context = array()) {
        $parsed = $this->extract_marketplace_source_fields($url, $source_context);
        if (!empty($parsed['font_name'])) {
            return $this->normalize_marketplace_label_case((string) $parsed['font_name'], $source_context);
        }
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $last = !empty($segments) ? end($segments) : '';
        $last = is_string($last) ? $last : '';
        if ($last === '') {
            return '';
        }
        if (strpos($last, '-font-') !== false) {
            $parts = explode('-font-', $last);
            $candidate = reset($parts);
            return $this->normalize_marketplace_label_case($this->humanize_slug($candidate), $source_context);
        }
        return '';
    }

    private function infer_foundry_name_from_source_url($url, $source_context = array()) {
        $parsed = $this->extract_marketplace_source_fields($url, $source_context);
        if (!empty($parsed['foundry_name'])) {
            return $this->normalize_marketplace_label_case((string) $parsed['foundry_name'], $source_context);
        }
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $last = !empty($segments) ? end($segments) : '';
        $last = is_string($last) ? $last : '';
        if ($last === '') {
            return '';
        }
        if (strpos($last, '-font-') !== false) {
            $parts = explode('-font-', $last);
            $candidate = end($parts);
            return $this->normalize_marketplace_label_case($this->humanize_slug($candidate), $source_context);
        }
        return '';
    }

    private function fetch_source_context($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return $this->build_error_result('invalid_source_url', 'Source URL is empty or invalid.', array(
                'stage' => 'source_fetch',
                'url' => $url,
            ));
        }

        $marketplace = $this->detect_marketplace_name($url);
        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'redirection' => 5,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Referer' => home_url('/'),
            ),
        ));
        if (is_wp_error($response)) {
            return $this->build_error_result('source_fetch_failed', 'Source fetch failed: ' . $response->get_error_message(), array(
                'stage' => 'source_fetch',
                'url' => $url,
            ));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return $this->build_error_result('source_fetch_http_error', 'Source fetch returned HTTP ' . $code . ' with an empty response body.', array(
                'stage' => 'source_fetch',
                'url' => $url,
                'http_code' => $code,
                'body_length' => 0,
            ));
        }

        $allow_degraded_parse = in_array($code, array(401, 403, 429), true) && $this->source_html_looks_usable($html, $marketplace);
        if (($code < 200 || $code >= 300) && !$allow_degraded_parse) {
            return $this->build_error_result('source_fetch_http_error', 'Source fetch returned HTTP ' . $code . '.', array(
                'stage' => 'source_fetch',
                'url' => $url,
                'http_code' => $code,
                'body_length' => strlen($html),
            ));
        }

        $og_image = $this->extract_meta_content($html, array('og:image', 'twitter:image'));
        $specimen_image = '';
        if ($marketplace === 'MyFonts') {
            $specimen_image = $this->extract_myfonts_specimen_image($html);
        }

        return array(
            'title' => $this->extract_html_title($html),
            'og_title' => $this->extract_meta_content($html, array('og:title', 'twitter:title')),
            'description' => $this->extract_meta_content($html, array('description', 'og:description', 'twitter:description')),
            'text_excerpt' => $this->extract_html_text_excerpt($html, 2500),
            'image_url' => $specimen_image !== '' ? $specimen_image : $og_image,
            'http_code' => $code,
            'degraded_fetch' => $allow_degraded_parse,
        );
    }

    private function source_html_looks_usable($html, $marketplace) {
        $html = (string) $html;
        if ($html === '') {
            return false;
        }

        $title = $this->extract_html_title($html);
        $og_title = $this->extract_meta_content($html, array('og:title', 'twitter:title'));
        $description = $this->extract_meta_content($html, array('description', 'og:description', 'twitter:description'));
        $text_excerpt = $this->extract_html_text_excerpt($html, 1200);
        $blob = strtolower(trim(implode(' ', array_filter(array($title, $og_title, $description, $text_excerpt)))));

        if ($blob === '') {
            return false;
        }

        if ($marketplace === 'Creative Market') {
            if (strpos($blob, 'creative market') !== false && (strpos($blob, 'font') !== false || strpos($blob, 'typeface') !== false)) {
                return true;
            }
        }

        if (strpos($blob, 'font') !== false || strpos($blob, 'typeface') !== false) {
            return true;
        }

        return strlen($blob) > 120;
    }

    private function extract_source_entity_hints($url, $source_context, $font_name_hint, $foundry_hint) {
        $parsed = $this->extract_marketplace_source_fields($url, $source_context);
        $text = '';
        if (!empty($source_context['title'])) {
            $text .= ' ' . (string) $source_context['title'];
        }
        if (!empty($source_context['og_title'])) {
            $text .= ' ' . (string) $source_context['og_title'];
        }
        if (!empty($source_context['description'])) {
            $text .= ' ' . (string) $source_context['description'];
        }
        if (!empty($source_context['text_excerpt'])) {
            $text .= ' ' . (string) $source_context['text_excerpt'];
        }
        $text = preg_replace('/\s+/', ' ', (string) $text);

        $designer_names = !empty($parsed['designer_names']) ? $this->sanitize_name_list($parsed['designer_names']) : array();
        $designer_evidence = !empty($parsed['designer_evidence']) ? sanitize_text_field((string) $parsed['designer_evidence']) : '';
        if (preg_match('/designed by\s+([^.;|]+?)(?:\s+and\s+published by|\s+published by|[.;|]|$)/i', $text, $matches)) {
            $designer_names = $this->sanitize_name_list($matches[1]);
            $designer_evidence = sanitize_text_field('Designed by ' . trim((string) $matches[1]));
        } elseif (preg_match('/designer\s*:\s*([^.;|]+?)(?:\s+foundry\s*:|\s+publisher\s*:|[.;|]|$)/i', $text, $matches)) {
            $designer_names = $this->sanitize_name_list($matches[1]);
            $designer_evidence = sanitize_text_field('Designer: ' . trim((string) $matches[1]));
        }
        $designer_names = array_values(array_filter(array_map(function($name) use ($source_context) {
            return $this->normalize_marketplace_label_case((string) $name, $source_context);
        }, $designer_names)));

        $foundry_name = !empty($parsed['foundry_name']) ? sanitize_text_field((string) $parsed['foundry_name']) : '';
        if (preg_match('/published by\s+([^.;|]+?)(?:\s+on\s+|[.;|]|$)/i', $text, $matches)) {
            $foundry_name = sanitize_text_field((string) $matches[1]);
        }
        if ($foundry_name === '' && $foundry_hint !== '') {
            $foundry_name = $foundry_hint;
        }

        if ($font_name_hint !== '' && $foundry_name !== '' && strcasecmp($font_name_hint, $foundry_name) === 0 && $foundry_hint !== '') {
            $foundry_name = $foundry_hint;
        }
        $foundry_name = $this->normalize_marketplace_label_case($foundry_name, $source_context);

        return array(
            'designer_names' => $designer_names,
            'designer_evidence' => $designer_evidence,
            'foundry_name' => $foundry_name,
        );
    }

    private function extract_marketplace_source_fields($url, $source_context) {
        $marketplace = $this->detect_marketplace_name($url);
        if ($marketplace === 'MyFonts') {
            return $this->extract_myfonts_source_fields($url, $source_context);
        }
        if ($marketplace === 'Creative Market') {
            return $this->extract_creative_market_source_fields($url, $source_context);
        }
        return array(
            'font_name' => '',
            'foundry_name' => '',
            'designer_names' => array(),
            'designer_evidence' => '',
        );
    }

    private function extract_myfonts_source_fields($url, $source_context) {
        $fields = array(
            'font_name' => '',
            'foundry_name' => '',
            'designer_names' => array(),
            'designer_evidence' => '',
        );

        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $last = !empty($segments) ? (string) end($segments) : '';
        if ($last !== '' && strpos($last, '-font-') !== false) {
            list($font_slug, $foundry_slug) = array_pad(explode('-font-', $last, 2), 2, '');
            $fields['font_name'] = $this->humanize_slug($font_slug);
            $fields['foundry_name'] = $this->humanize_slug($foundry_slug);
        }

        $title_blob = trim(implode(' | ', array_filter(array(
            (string) ($source_context['title'] ?? ''),
            (string) ($source_context['og_title'] ?? ''),
        ))));
        if ($title_blob !== '') {
            if (preg_match('/^\s*(.+?)\s+by\s+(.+?)(?:\s*\||\s*-\s*MyFonts|\s*$)/i', $title_blob, $matches)) {
                $fields['font_name'] = trim((string) $matches[1]);
                $fields['foundry_name'] = trim((string) $matches[2]);
            } elseif (preg_match('/^\s*(.+?)(?:\s+font family|\s+font)?\s*\|\s*(.+?)\s*\|\s*MyFonts/i', $title_blob, $matches)) {
                $fields['font_name'] = trim((string) $matches[1]);
                $fields['foundry_name'] = trim((string) $matches[2]);
            }
        }

        $text = trim(implode(' ', array_filter(array(
            (string) ($source_context['description'] ?? ''),
            (string) ($source_context['text_excerpt'] ?? ''),
        ))));
        if ($text !== '') {
            if (preg_match('/(?:font family|typeface|font)\s+by\s+([A-Z][A-Za-z0-9 .,&-]+?)(?:\s+and\s+published by|\s+published by|\.|,|\||$)/', $text, $matches)) {
                $fields['foundry_name'] = trim((string) $matches[1]);
            }
            if (preg_match('/designed by\s+([^.;|]+?)(?:\s+and\s+published by|\s+published by|[.;|]|$)/i', $text, $matches)) {
                $fields['designer_names'] = $this->sanitize_name_list($matches[1]);
                $fields['designer_evidence'] = 'Designed by ' . trim((string) $matches[1]);
            }
        }

        $fields['font_name'] = $this->normalize_marketplace_label_case((string) $fields['font_name'], $source_context);
        $fields['foundry_name'] = $this->normalize_marketplace_label_case((string) $fields['foundry_name'], $source_context);
        $fields['designer_names'] = array_values(array_filter(array_map(function($name) use ($source_context) {
            return $this->normalize_marketplace_label_case((string) $name, $source_context);
        }, (array) $fields['designer_names'])));

        return $fields;
    }

    private function extract_creative_market_source_fields($url, $source_context) {
        $fields = array(
            'font_name' => '',
            'foundry_name' => '',
            'designer_names' => array(),
            'designer_evidence' => '',
        );

        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (count($segments) >= 2) {
            $fields['foundry_name'] = $this->humanize_slug((string) $segments[0]);
            $product_segment = (string) $segments[1];
            $product_segment = preg_replace('/^\d+-/', '', $product_segment);
            $fields['font_name'] = $this->humanize_slug($product_segment);
        }

        $title_blob = trim(implode(' | ', array_filter(array(
            (string) ($source_context['title'] ?? ''),
            (string) ($source_context['og_title'] ?? ''),
        ))));
        if ($title_blob !== '') {
            if (preg_match('/^\s*(.+?)\s+by\s+(.+?)(?:\s*\||\s*-\s*Creative Market|\s*$)/i', $title_blob, $matches)) {
                $fields['font_name'] = trim((string) $matches[1]);
                $fields['foundry_name'] = trim((string) $matches[2]);
            } elseif (preg_match('/^\s*(.+?)\s*\|\s*Creative Market/i', $title_blob, $matches)) {
                $fields['font_name'] = trim((string) $matches[1]);
            }
        }

        $text = trim(implode(' ', array_filter(array(
            (string) ($source_context['description'] ?? ''),
            (string) ($source_context['text_excerpt'] ?? ''),
        ))));
        if ($text !== '') {
            if (preg_match('/by\s+([A-Z][A-Za-z0-9 .,&-]+?)(?:\s+on\s+Creative Market|\.|,|\||$)/', $text, $matches)) {
                $fields['foundry_name'] = trim((string) $matches[1]);
            }
            if (preg_match('/designed by\s+([^.;|]+?)(?:\s+and\s+published by|\s+published by|[.;|]|$)/i', $text, $matches)) {
                $fields['designer_names'] = $this->sanitize_name_list($matches[1]);
                $fields['designer_evidence'] = 'Designed by ' . trim((string) $matches[1]);
            }
        }

        if (empty($fields['designer_names']) && $fields['foundry_name'] !== '') {
            $fields['designer_names'] = array($fields['foundry_name']);
            $fields['designer_evidence'] = 'Marketplace seller matched the source page byline.';
        }

        $fields['font_name'] = $this->normalize_marketplace_label_case((string) $fields['font_name'], $source_context);
        $fields['foundry_name'] = $this->normalize_marketplace_label_case((string) $fields['foundry_name'], $source_context);
        $fields['designer_names'] = array_values(array_filter(array_map(function($name) use ($source_context) {
            return $this->normalize_marketplace_label_case((string) $name, $source_context);
        }, (array) $fields['designer_names'])));

        return $fields;
    }

    private function designer_evidence_is_explicit($evidence, $designer_names) {
        $evidence = strtolower((string) $evidence);
        if ($evidence === '') {
            return false;
        }
        if (strpos($evidence, 'designed by') === false && strpos($evidence, 'designer:') === false) {
            return false;
        }
        foreach ((array) $designer_names as $name) {
            $name = strtolower((string) $name);
            if ($name !== '' && strpos($evidence, $name) !== false) {
                return true;
            }
        }
        return false;
    }

    private function extract_html_title($html) {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', (string) $html, $matches)) {
            return sanitize_text_field(wp_strip_all_tags(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        return '';
    }

    private function normalize_myfonts_image_url($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '' || strpos($url, 'cdn.myfonts.net/cdn-cgi/image/') === false) {
            return $url;
        }

        return preg_replace(
            '#(https://cdn\.myfonts\.net/cdn-cgi/image/)(?:width=\d+,height=\d+,fit=contain,)?format=auto/#i',
            '$1format=auto/',
            $url
        );
    }

    private function extract_myfonts_specimen_image($html) {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        if (preg_match('#(https://cdn\.myfonts\.net/cdn-cgi/image/[^"\']+/images/pim/[^"\']+\.(?:jpg|jpeg|png|webp))#i', $html, $matches)) {
            return $this->normalize_myfonts_image_url((string) $matches[1]);
        }

        return '';
    }

    private function extract_meta_content($html, $names) {
        foreach ((array) $names as $name) {
            $quoted = preg_quote((string) $name, '/');
            if (preg_match('/<meta[^>]+(?:name|property)=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']+)["\']/i', (string) $html, $matches)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\']' . $quoted . '["\']/i', (string) $html, $matches)) {
                return sanitize_text_field(html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return '';
    }

    private function extract_html_text_excerpt($html, $limit) {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', (string) $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', (string) $html);
        $text = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', (string) $text);
        $text = trim((string) $text);
        return $limit > 0 ? substr($text, 0, (int) $limit) : $text;
    }

    private function humanize_slug($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = str_replace(array('-', '_'), ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return ucwords(trim((string) $value));
    }

    private function normalize_marketplace_label_case($value, $source_context = array()) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $source_blob = trim(implode(' ', array_filter(array(
            (string) ($source_context['title'] ?? ''),
            (string) ($source_context['og_title'] ?? ''),
            (string) ($source_context['description'] ?? ''),
        ))));

        if ($source_blob !== '') {
            $match = $this->match_source_case_variant($value, $source_blob);
            if ($match !== '') {
                return $match;
            }
        }

        $tokens = preg_split('/\s+/', $value);
        $tokens = array_map(function($token) {
            if (preg_match('/^[A-Za-z]{2,4}$/', $token)) {
                return strtoupper($token);
            }
            if (preg_match('/^[A-Za-z]{1,3}[0-9]{1,3}$/', $token)) {
                return strtoupper($token);
            }
            if (preg_match('/^[A-Za-z0-9]+$/', $token)) {
                return ucwords(strtolower($token));
            }
            return $token;
        }, (array) $tokens);

        return trim(implode(' ', $tokens));
    }

    private function match_source_case_variant($value, $source_blob) {
        $candidate = preg_replace('/\s+/', '\\s+', preg_quote(trim((string) $value), '/'));
        if ($candidate === '') {
            return '';
        }
        if (preg_match('/\b(' . $candidate . ')\b/u', (string) $source_blob, $matches)) {
            return trim((string) $matches[1]);
        }
        return '';
    }

    private function get_generator_previews() {
        $store = get_option('kaco_generator_preview_store', array());
        if (!is_array($store)) {
            return array();
        }

        $user_id = (int) get_current_user_id();
        $previews = $store[$user_id] ?? array();
        return is_array($previews) ? $previews : array();
    }

    private function set_generator_previews($previews) {
        $store = get_option('kaco_generator_preview_store', array());
        if (!is_array($store)) {
            $store = array();
        }

        $user_id = (int) get_current_user_id();
        $store[$user_id] = is_array($previews) ? array_values($previews) : array();
        update_option('kaco_generator_preview_store', $store, false);
    }

    private function get_generator_url_inbox() {
        $value = get_option('kaco_generator_url_inbox', array());
        return is_array($value) ? array_values(array_filter(array_map('esc_url_raw', $value))) : array();
    }

    private function set_generator_url_inbox($urls) {
        update_option('kaco_generator_url_inbox', array_values(array_unique(array_filter(array_map('esc_url_raw', (array) $urls)))), false);
    }

    private function get_generator_automation_review() {
        $value = get_option('kaco_generator_automation_review', array());
        return is_array($value) ? array_values($value) : array();
    }

    private function set_generator_automation_review($items) {
        update_option('kaco_generator_automation_review', is_array($items) ? array_values($items) : array(), false);
    }

    private function process_generator_url_inbox() {
        if (get_option('kaco_automation_process_url_inbox', '1') !== '1') {
            return array(
                'processed' => 0,
                'created' => 0,
                'queued_for_review' => 0,
                'skipped_duplicates' => 0,
                'failed' => 0,
                'remaining_inbox' => count($this->get_generator_url_inbox()),
            );
        }

        $inbox = $this->get_generator_url_inbox();
        $batch_size = min(100, max(1, (int) get_option('kaco_automation_url_batch_size', 10)));
        $auto_create = get_option('kaco_automation_auto_create_drafts', '1') === '1';
        $auto_schedule = get_option('kaco_automation_auto_schedule_generated_posts', '1') === '1';
        $create_confidence = min(1, max(0, (float) get_option('kaco_automation_generator_create_confidence', 0.90)));
        $review_items = $this->get_generator_automation_review();

        $processed = 0;
        $created = 0;
        $queued_for_review = 0;
        $skipped_duplicates = 0;
        $failed = 0;
        $remaining = array();

        foreach ($inbox as $url) {
            if ($processed >= $batch_size) {
                $remaining[] = $url;
                continue;
            }
            $processed++;

            if ($this->find_existing_generated_post_by_source_url($url) > 0) {
                $this->append_automation_log(array(
                    'lane' => 'generator',
                    'action' => 'create_post',
                    'status' => 'skipped',
                    'url' => $url,
                    'message' => 'Skipped because a post with this source URL already exists.',
                ));
                $skipped_duplicates++;
                continue;
            }

            $preview = $this->request_generator_preview($url);
            if (is_wp_error($preview)) {
                $error_message = $preview->get_error_message();
                $debug = $this->extract_error_debug($preview);
                $this->append_automation_log(array(
                    'lane' => 'generator',
                    'action' => 'generate_preview',
                    'status' => 'failed',
                    'url' => $url,
                    'message' => $error_message,
                    'debug' => $debug,
                ));
                $review_items[] = array(
                    'preview_source' => 'automation',
                    'url' => $url,
                    'title' => '',
                    'image_url' => '',
                    'designer_names' => array(),
                    'foundry_name' => '',
                    'font_style_name' => '',
                    'font_mood_names' => array(),
                    'font_use_case_names' => array(),
                    'tags' => array(),
                    'content' => '<p>Generation failed for this URL. Review the source manually.</p>',
                    'confidence' => 0,
                    'automation_error' => $error_message,
                    'diagnostics' => $debug,
                );
                $queued_for_review++;
                $failed++;
                continue;
            }

            $preview['preview_source'] = 'automation';
            $confidence = isset($preview['confidence']) ? (float) $preview['confidence'] : 0.0;
            if ($auto_create && $confidence >= $create_confidence) {
                $post_id = $this->create_generated_draft_from_preview($preview, array(
                    'scheduled' => $auto_schedule,
                ));
                if (!is_wp_error($post_id) && (int) $post_id > 0) {
                    $this->append_automation_log(array(
                        'lane' => 'generator',
                        'action' => 'create_post',
                        'status' => 'success',
                        'url' => $url,
                        'post_id' => (int) $post_id,
                        'title' => (string) ($preview['title'] ?? ''),
                        'confidence' => $confidence,
                        'message' => !empty($auto_schedule) ? 'Generated post was scheduled automatically.' : 'Generated draft was created automatically.',
                    ));
                    $created++;
                    continue;
                }
                $preview['automation_error'] = is_wp_error($post_id) ? $post_id->get_error_message() : 'Draft creation failed.';
                $this->append_automation_log(array(
                    'lane' => 'generator',
                    'action' => 'create_post',
                    'status' => 'failed',
                    'url' => $url,
                    'title' => (string) ($preview['title'] ?? ''),
                    'confidence' => $confidence,
                    'message' => (string) $preview['automation_error'],
                    'debug' => !empty($preview['diagnostics']) ? $preview['diagnostics'] : array(),
                ));
                $failed++;
            }

            $this->append_automation_log(array(
                'lane' => 'generator',
                'action' => 'queue_review',
                'status' => 'needs_review',
                'url' => $url,
                'title' => (string) ($preview['title'] ?? ''),
                'confidence' => $confidence,
                'message' => !empty($preview['automation_error']) ? (string) $preview['automation_error'] : 'Preview requires manual review before creation.',
                'debug' => !empty($preview['diagnostics']) ? $preview['diagnostics'] : array(),
            ));
            $review_items[] = $preview;
            $queued_for_review++;
        }

        $this->set_generator_url_inbox($remaining);
        $this->set_generator_automation_review($review_items);

        return array(
            'processed' => $processed,
            'created' => $created,
            'queued_for_review' => $queued_for_review,
            'skipped_duplicates' => $skipped_duplicates,
            'failed' => $failed,
            'remaining_inbox' => count($remaining),
        );
    }

    private function create_generated_draft_from_preview($preview, $options = array()) {
        $title = sanitize_text_field((string) ($preview['title'] ?? ''));
        $content = wp_kses_post((string) ($preview['content'] ?? ''));
        $summary_excerpt = sanitize_textarea_field((string) ($preview['summary_excerpt'] ?? ''));
        if ($summary_excerpt === '') {
            $summary_excerpt = $this->build_generated_summary_excerpt($preview);
        }
        $source_url = esc_url_raw((string) ($preview['url'] ?? ''));
        if ($title === '' || $content === '') {
            return new WP_Error('missing_preview_fields', 'Generated preview is missing a title or content.');
        }

        $existing_post_id = $this->find_existing_generated_post_by_source_url($source_url);
        if ($existing_post_id > 0) {
            return new WP_Error('duplicate_source_url', 'A post with this source URL already exists.');
        }

        $tags = array();
        $raw_tags = $preview['tags'] ?? array();
        if (is_string($raw_tags)) {
            $raw_tags = explode(',', $raw_tags);
        }
        foreach ((array) $raw_tags as $tag) {
            $tag = sanitize_text_field((string) $tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        $tags = array_values(array_unique($tags));

        $post_status = !empty($options['scheduled']) ? 'future' : 'draft';
        $post_dates = array();
        $post_data = array(
            'post_type' => 'post',
            'post_status' => $post_status,
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $summary_excerpt,
            'tags_input' => $tags,
        );
        if ($post_status === 'future') {
            $post_dates = $this->reserve_next_generated_post_schedule();
            $post_data['post_date'] = $post_dates['post_date'];
            $post_data['post_date_gmt'] = $post_dates['post_date_gmt'];
        }

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id) || !$post_id) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('draft_insert_failed', 'Draft creation failed.');
        }

        update_post_meta((int) $post_id, '_kaco_source_url', $source_url);
        update_post_meta((int) $post_id, '_kaco_source_marketplace', $this->detect_marketplace_name($source_url));
        update_post_meta((int) $post_id, '_kaco_generated_at', current_time('mysql', true));
        update_post_meta((int) $post_id, 'kreativ-page-summary', $summary_excerpt);
        if ($post_status === 'future' && !empty($post_dates['post_date_gmt'])) {
            update_post_meta((int) $post_id, '_kaco_scheduled_at_gmt', (string) $post_dates['post_date_gmt']);
        }

        $category_result = $this->assign_generated_post_categories((int) $post_id, $preview);
        $linked_terms = !empty($category_result['linked_terms']) ? (array) $category_result['linked_terms'] : array();
        $created_terms = !empty($category_result['created_terms']) ? (array) $category_result['created_terms'] : array();
        $this->queue_category_description_drafts_for_terms($created_terms);

        $image_url = esc_url_raw((string) ($preview['image_url'] ?? ''));
        if ($image_url !== '') {
            $attachment_id = $this->sideload_generated_post_image((int) $post_id, $image_url, $title);
            if ($attachment_id) {
                $local_image_url = wp_get_attachment_url((int) $attachment_id);
                if ($local_image_url) {
                    $updated_content = $this->inject_local_image_into_content($content, $image_url, $local_image_url, $title);
                    if ($updated_content !== $content) {
                        $image_update = wp_update_post(array(
                            'ID' => (int) $post_id,
                            'post_content' => $updated_content,
                        ), true);
                        if (is_wp_error($image_update) || (int) $image_update <= 0) {
                            return is_wp_error($image_update) ? $image_update : new WP_Error('image_content_update_failed', 'Draft image content update failed.');
                        }
                    }
                }
            }
        }

        if (!empty($linked_terms)) {
            $content = (string) get_post_field('post_content', (int) $post_id);
            $relinked_content = $this->replace_generator_lead_paragraph($content, $preview, $linked_terms);
            $relinked_content = $this->relink_font_mentions_to_internal_categories($relinked_content, $linked_terms);
            if ($relinked_content !== $content) {
                $relink_update = wp_update_post(array(
                    'ID' => (int) $post_id,
                    'post_content' => $relinked_content,
                ), true);
                if (is_wp_error($relink_update) || (int) $relink_update <= 0) {
                    return is_wp_error($relink_update) ? $relink_update : new WP_Error('relink_update_failed', 'Draft relink update failed.');
                }
            }
        }

        return (int) $post_id;
    }

    private function reserve_next_generated_post_schedule() {
        $spacing_hours = min(24, max(1, (int) get_option('kaco_automation_generated_post_spacing_hours', 3)));
        $last_scheduled_gmt = (string) get_option('kaco_automation_last_scheduled_gmt', '');
        $now_gmt = current_time('timestamp', true);
        $base_timestamp = $now_gmt;

        $latest_future = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'future',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        if (!empty($latest_future[0])) {
            $future_post = get_post((int) $latest_future[0]);
            if ($future_post && !empty($future_post->post_date_gmt) && $future_post->post_date_gmt !== '0000-00-00 00:00:00') {
                $future_timestamp = strtotime((string) $future_post->post_date_gmt . ' UTC');
                if ($future_timestamp !== false) {
                    $base_timestamp = max($base_timestamp, (int) $future_timestamp);
                }
            }
        }

        if ($last_scheduled_gmt !== '') {
            $last_timestamp = strtotime($last_scheduled_gmt . ' UTC');
            if ($last_timestamp !== false) {
                $base_timestamp = max($base_timestamp, (int) $last_timestamp);
            }
        }

        $next_timestamp = $base_timestamp + ($spacing_hours * HOUR_IN_SECONDS);
        $post_date_gmt = gmdate('Y-m-d H:i:s', $next_timestamp);
        update_option('kaco_automation_last_scheduled_gmt', $post_date_gmt, false);

        return array(
            'post_date_gmt' => $post_date_gmt,
            'post_date' => get_date_from_gmt($post_date_gmt),
        );
    }

    private function assign_generated_post_categories($post_id, $preview) {
        if (!taxonomy_exists('category')) {
            return array(
                'linked_terms' => array(),
                'created_terms' => array(),
            );
        }

        $category_ids = wp_get_post_terms((int) $post_id, 'category', array('fields' => 'ids'));
        if (is_wp_error($category_ids) || !is_array($category_ids)) {
            $category_ids = array();
        }

        $targets = $this->font_category_parent_targets();
        $fonts_term = $this->find_category_by_name($targets['fonts']);
        if ($fonts_term) {
            $category_ids[] = (int) $fonts_term->term_id;
        }

        $linked_terms = array();
        $created_terms = array();
        $targets_map = array(
            'designer_names' => 'designer',
            'foundry_name' => 'foundry',
            'font_style_name' => 'font_style',
            'font_mood_names' => 'font_mood',
            'font_use_case_names' => 'font_use_case',
        );
        foreach ($targets_map as $field => $target_key) {
            $parent_term = $this->find_category_by_name($targets[$target_key]);
            if (!$parent_term) {
                continue;
            }
            $current_terms = wp_get_post_terms((int) $post_id, 'category');
            if (is_wp_error($current_terms) || !is_array($current_terms)) {
                $current_terms = array();
            }
            $values = $this->normalize_hierarchy_input_values($preview[$field] ?? array(), $target_key);
            $existing_children = $this->find_assigned_child_categories($current_terms, (int) $parent_term->term_id);
            if (!empty($existing_children)) {
                $category_ids = array_values(array_diff($category_ids, wp_list_pluck($existing_children, 'term_id')));
            }
            if (empty($values)) {
                continue;
            }
            $detail_terms = $this->resolve_hierarchy_terms_for_parent($values, $target_key, (int) $parent_term->term_id);
            foreach ($detail_terms as $detail) {
                if (empty($detail['term']) || is_wp_error($detail['term'])) {
                    continue;
                }
                if (!isset($linked_terms[$target_key])) {
                    $linked_terms[$target_key] = array();
                }
                $linked_terms[$target_key][] = $detail['term'];
                $category_ids[] = (int) $detail['term']->term_id;
                if (!empty($detail['created'])) {
                    $created_terms[(int) $detail['term']->term_id] = $detail['term'];
                }
            }
        }
        foreach ($linked_terms as $terms) {
            foreach ((array) $terms as $term) {
                if ($term && !is_wp_error($term)) {
                    $category_ids[] = (int) $term->term_id;
                }
            }
        }

        $category_ids = array_values(array_unique(array_map('intval', $category_ids)));
        if (!empty($category_ids)) {
            wp_set_post_terms((int) $post_id, $category_ids, 'category', false);
        }

        return array(
            'linked_terms' => $linked_terms,
            'created_terms' => array_values($created_terms),
        );
    }

    private function sideload_generated_post_image($post_id, $image_url, $title) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return false;
        }

        $filetype = wp_check_filetype(basename($image_url));
        $extension = !empty($filetype['ext']) ? '.' . $filetype['ext'] : '.jpg';
        $filename = sanitize_title($title) . $extension;
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, (int) $post_id, $title);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }

        set_post_thumbnail((int) $post_id, (int) $attachment_id);
        return (int) $attachment_id;
    }

    private function replace_generator_lead_paragraph($content, $preview, $linked_terms) {
        $content = (string) $content;
        $source_url = esc_url((string) ($preview['url'] ?? ''));
        $font_name = $this->extract_font_name_from_title((string) ($preview['title'] ?? ''));
        $lead = $this->build_generator_lead_paragraph(
            $source_url,
            $font_name,
            $this->first_linked_term($linked_terms['font_style'] ?? array()),
            $linked_terms['font_mood'] ?? array(),
            $linked_terms['font_use_case'] ?? array(),
            $linked_terms['designer'] ?? array(),
            $this->first_linked_term($linked_terms['foundry'] ?? array()),
            sanitize_text_field((string) ($preview['font_style_name'] ?? '')),
            (array) ($preview['font_mood_names'] ?? array()),
            (array) ($preview['font_use_case_names'] ?? array()),
            (array) ($preview['designer_names'] ?? array()),
            sanitize_text_field((string) ($preview['foundry_name'] ?? ''))
        );
        if ($lead === '') {
            return $content;
        }

        if ($source_url !== '') {
            $pattern = '/<p>\s*<a href="' . preg_quote($source_url, '/') . '"[^>]*target="_blank"[^>]*rel="noopener"[^>]*>.*?<\/a>.*?<\/p>/is';
            $updated = preg_replace($pattern, $lead, $content, 1, $count);
            if ($count > 0) {
                return $updated;
            }
        }

        return $content . "\n\n" . $lead;
    }

    private function first_linked_term($terms) {
        foreach ((array) $terms as $term) {
            if ($term && !is_wp_error($term)) {
                return $term;
            }
        }
        return null;
    }

    private function build_generator_lead_paragraph($source_url, $font_name, $font_style_term, $font_mood_terms, $font_use_case_terms, $designer_terms, $foundry_term, $font_style_name, $font_mood_names, $font_use_case_names, $designer_names, $foundry_name) {
        $source_url = esc_url((string) $source_url);
        $font_name = sanitize_text_field((string) $font_name);
        if ($source_url === '' || $font_name === '') {
            return '';
        }

        $lead = '<p><a href="' . $source_url . '" target="_blank" rel="noopener">' . esc_html($font_name) . '</a>';
        if ($font_style_term && !is_wp_error($font_style_term)) {
            $style_url = get_term_link($font_style_term, 'category');
            $style_label = (string) $font_style_term->name;
            if (!is_wp_error($style_url)) {
                $lead .= ' is a <a href="' . esc_url($style_url) . '">' . esc_html($style_label) . '</a> typeface';
            }
        } elseif ($font_style_name !== '') {
            $lead .= ' is a ' . esc_html($font_style_name) . ' typeface';
        } else {
            $lead .= ' is a typeface';
        }

        $designer_links = $this->term_links_inline($designer_terms, false);
        if ($designer_links !== '') {
            $lead .= ' from ' . $designer_links;
        } elseif (!empty($designer_names)) {
            $lead .= ' from ' . esc_html($this->compact_designer_credit($designer_names));
        }

        if ($foundry_term && !is_wp_error($foundry_term)) {
            $foundry_url = get_term_link($foundry_term, 'category');
            $foundry_label = (string) $foundry_term->name;
            if (!is_wp_error($foundry_url)) {
                $lead .= !empty($designer_links) || !empty($designer_names)
                    ? ', published by <a href="' . esc_url($foundry_url) . '">' . esc_html($foundry_label) . '</a>'
                    : ' from <a href="' . esc_url($foundry_url) . '">' . esc_html($foundry_label) . '</a>';
            }
        } elseif ($foundry_name !== '') {
            $lead .= !empty($designer_links) || !empty($designer_names)
                ? ', published by ' . esc_html($foundry_name)
                : ' from ' . esc_html($foundry_name);
        }

        $lead .= '.</p>';
        return $lead;
    }

    private function term_links_inline($terms, $lowercase) {
        $links = array();
        foreach ((array) $terms as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $url = get_term_link($term, 'category');
            if (is_wp_error($url)) {
                continue;
            }
            $label = (string) $term->name;
            if ($lowercase) {
                $label = strtolower($label);
            }
            $links[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        return implode(', ', $links);
    }

    private function inject_local_image_into_content($content, $remote_image_url, $local_image_url, $title) {
        $content = (string) $content;
        $remote_image_url = esc_url_raw((string) $remote_image_url);
        $local_image_url = esc_url_raw((string) $local_image_url);
        $title = sanitize_text_field((string) $title);

        if ($content === '' || $local_image_url === '') {
            return $content;
        }

        if ($remote_image_url !== '' && strpos($content, $remote_image_url) !== false) {
            return str_replace($remote_image_url, $local_image_url, $content);
        }

        if (preg_match('/<img\b[^>]*src=["\'][^"\']+["\'][^>]*>/i', $content)) {
            return preg_replace(
                '/<img\b([^>]*)src=["\'][^"\']+["\']([^>]*)>/i',
                '<img$1src="' . esc_url($local_image_url) . '"$2>',
                $content,
                1
            );
        }

        $image_html = '<img src="' . esc_url($local_image_url) . '" alt="' . esc_attr($title) . '" style="max-width:100%; height:auto;" />';
        return $image_html . "\n\n" . $content;
    }

    private function find_existing_generated_post_by_source_url($source_url) {
        $source_url = esc_url_raw((string) $source_url);
        if ($source_url === '') {
            return 0;
        }

        $query = new WP_Query(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_kaco_source_url',
                    'value' => $source_url,
                ),
            ),
        ));

        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private function request_category_description_ai($term) {
        $api_key = (string) get_option('kaco_openai_api_key', '');
        $endpoint = (string) get_option('kaco_openai_endpoint', self::OPENAI_ENDPOINT);
        $model = (string) get_option('kaco_openai_model', self::OPENAI_MODEL);
        if ($api_key === '' || $endpoint === '' || $model === '') {
            return false;
        }

        $decoded = $this->request_openai_json_payload(
            $api_key,
            $endpoint,
            $model,
            'You write concise SEO-aware WordPress category descriptions for a font marketplace. Return valid minified JSON only.',
            wp_json_encode(array(
                'task' => 'Write a category description.',
                'output_schema' => array('description' => 'string'),
                'term' => array(
                    'id' => (int) $term->term_id,
                    'taxonomy' => 'category',
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                    'count' => (int) $term->count,
                    'current_description' => (string) $term->description,
                ),
                'constraints' => array('2_to_4_sentences', 'specific_to_font_category', 'no_keyword_stuffing', 'no_markdown'),
            ))
        );
        if (!is_array($decoded) || empty($decoded['description'])) {
            return false;
        }
        return wp_kses_post((string) $decoded['description']);
    }

    private function request_openai_json_payload($api_key, $endpoint, $model, $system_prompt, $user_prompt) {
        $endpoint = trim((string) $endpoint);
        $body = $this->build_openai_request_body($endpoint, $model, $system_prompt, $user_prompt);
        $response = wp_remote_post($endpoint, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return $this->build_error_result('openai_request_failed', 'OpenAI request failed: ' . $response->get_error_message(), array(
                'stage' => 'openai_request',
                'endpoint' => $endpoint,
                'model' => $model,
            ));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $raw === '') {
            return $this->build_error_result('openai_http_error', 'OpenAI returned HTTP ' . $code . '.', array(
                'stage' => 'openai_request',
                'endpoint' => $endpoint,
                'model' => $model,
                'http_code' => $code,
                'body_excerpt' => substr($raw, 0, 500),
            ));
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $this->build_error_result('openai_invalid_json', 'OpenAI returned invalid JSON.', array(
                'stage' => 'openai_response_parse',
                'endpoint' => $endpoint,
                'model' => $model,
                'body_excerpt' => substr($raw, 0, 500),
            ));
        }

        $content = $this->extract_openai_response_content($endpoint, $json);
        if ($content === '') {
            return $this->build_error_result('openai_empty_content', 'OpenAI response did not contain usable content.', array(
                'stage' => 'openai_response_extract',
                'endpoint' => $endpoint,
                'model' => $model,
            ));
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return $this->build_error_result('openai_invalid_json_payload', 'OpenAI content was not valid JSON for the expected schema.', array(
                'stage' => 'openai_content_parse',
                'endpoint' => $endpoint,
                'model' => $model,
                'content_excerpt' => substr($content, 0, 500),
            ));
        }
        return $decoded;
    }

    private function build_openai_request_body($endpoint, $model, $system_prompt, $user_prompt) {
        if ($this->is_responses_endpoint($endpoint)) {
            return array(
                'model' => $model,
                'text' => array(
                    'format' => array('type' => 'json_object'),
                ),
                'input' => array(
                    array(
                        'role' => 'system',
                        'content' => array(
                            array(
                                'type' => 'input_text',
                                'text' => (string) $system_prompt,
                            ),
                        ),
                    ),
                    array(
                        'role' => 'user',
                        'content' => array(
                            array(
                                'type' => 'input_text',
                                'text' => (string) $user_prompt,
                            ),
                        ),
                    ),
                ),
            );
        }

        return array(
            'model' => $model,
            'response_format' => array('type' => 'json_object'),
            'temperature' => 0.2,
            'messages' => array(
                array('role' => 'system', 'content' => (string) $system_prompt),
                array('role' => 'user', 'content' => (string) $user_prompt),
            ),
        );
    }

    private function extract_openai_response_content($endpoint, $json) {
        if ($this->is_responses_endpoint($endpoint)) {
            $content = '';
            foreach ((array) ($json['output'] ?? array()) as $item) {
                if (($item['type'] ?? '') !== 'message') {
                    continue;
                }
                foreach ((array) ($item['content'] ?? array()) as $part) {
                    if (($part['type'] ?? '') === 'output_text' && !empty($part['text'])) {
                        $content .= (string) $part['text'];
                    }
                }
            }
            return trim($content);
        }

        return !empty($json['choices'][0]['message']['content']) ? (string) $json['choices'][0]['message']['content'] : '';
    }

    private function is_responses_endpoint($endpoint) {
        return strpos((string) $endpoint, '/responses') !== false;
    }

    private function sanitize_ai_payload($payload) {
        $safe = array(
            'title' => sanitize_text_field((string) ($payload['title'] ?? '')),
            'refreshed_intro' => wp_kses_post((string) ($payload['refreshed_intro'] ?? '')),
            'visual_analysis' => wp_kses_post((string) ($payload['visual_analysis'] ?? '')),
            'best_for' => array(),
            'pairing_notes' => array(),
            'font_features' => array(),
            'whats_included' => array(),
            'pricing_details' => array(),
            'verified_details' => array(),
            'content_append' => wp_kses_post((string) ($payload['content_append'] ?? '')),
            'excerpt' => sanitize_textarea_field((string) ($payload['excerpt'] ?? '')),
            'term_descriptions' => array(),
            'font_category_hierarchy' => array(),
            'evidence' => array(),
            'internal_links' => array(),
            'confidence' => max(0, min(1, (float) ($payload['confidence'] ?? 0))),
            'notes' => sanitize_textarea_field((string) ($payload['notes'] ?? '')),
        );

        if (!empty($payload['internal_links']) && is_array($payload['internal_links'])) {
            foreach ($payload['internal_links'] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $url = esc_url_raw((string) ($link['url'] ?? ''));
                $anchor = sanitize_text_field((string) ($link['anchor'] ?? ''));
                $reason = sanitize_text_field((string) ($link['reason'] ?? ''));
                if ($url !== '' && $anchor !== '') {
                    $safe['internal_links'][] = array('url' => $url, 'anchor' => $anchor, 'reason' => $reason);
                }
            }
        }

        $safe['best_for'] = $this->sanitize_simple_bullets($payload['best_for'] ?? array(), 5);
        $safe['pairing_notes'] = $this->sanitize_pairing_notes($payload['pairing_notes'] ?? array());
        $safe['font_features'] = $this->sanitize_simple_bullets($payload['font_features'] ?? array(), 8);
        $safe['whats_included'] = $this->sanitize_simple_bullets($payload['whats_included'] ?? array(), 8);
        $safe['pricing_details'] = $this->sanitize_simple_bullets($payload['pricing_details'] ?? array(), 5);
        $safe['verified_details'] = $this->sanitize_simple_bullets($payload['verified_details'] ?? array(), 8);

        if (!empty($payload['term_descriptions']) && is_array($payload['term_descriptions'])) {
            foreach ($payload['term_descriptions'] as $taxonomy => $items) {
                $taxonomy = sanitize_key((string) $taxonomy);
                if (!is_array($items)) {
                    continue;
                }
                $safe['term_descriptions'][$taxonomy] = array();
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $term_name = sanitize_text_field((string) ($item['term'] ?? ''));
                    $desc = wp_kses_post((string) ($item['description'] ?? ''));
                    if ($term_name !== '' && $desc !== '') {
                        $safe['term_descriptions'][$taxonomy][] = array('term' => $term_name, 'description' => $desc);
                    }
                }
            }
        }

        if (!empty($payload['font_category_hierarchy']) && is_array($payload['font_category_hierarchy'])) {
            $safe['font_category_hierarchy'] = array(
                'designer_names' => $this->sanitize_name_list($payload['font_category_hierarchy']['designer_names'] ?? ($payload['font_category_hierarchy']['designer_name'] ?? array())),
                'foundry_name' => sanitize_text_field((string) ($payload['font_category_hierarchy']['foundry_name'] ?? '')),
                'font_style_name' => $this->canonical_font_style_name((string) ($payload['font_category_hierarchy']['font_style_name'] ?? '')),
                'font_mood_names' => $this->sanitize_canonical_name_list($payload['font_category_hierarchy']['font_mood_names'] ?? ($payload['font_category_hierarchy']['font_mood_name'] ?? array()), 'canonical_font_mood_name'),
                'font_use_case_names' => $this->sanitize_canonical_name_list($payload['font_category_hierarchy']['font_use_case_names'] ?? ($payload['font_category_hierarchy']['font_use_case_name'] ?? array()), 'canonical_font_use_case_name'),
                'notes' => sanitize_textarea_field((string) ($payload['font_category_hierarchy']['notes'] ?? '')),
            );
        }

        if (!empty($payload['evidence']) && is_array($payload['evidence'])) {
            $safe['evidence'] = array(
                'designer' => sanitize_text_field((string) ($payload['evidence']['designer'] ?? '')),
                'foundry' => sanitize_text_field((string) ($payload['evidence']['foundry'] ?? '')),
                'font_style' => sanitize_text_field((string) ($payload['evidence']['font_style'] ?? '')),
                'font_mood' => sanitize_text_field((string) ($payload['evidence']['font_mood'] ?? '')),
                'font_use_case' => sanitize_text_field((string) ($payload['evidence']['font_use_case'] ?? '')),
            );
        }

        return $safe;
    }

    private function detect_marketplace_name($url) {
        $host = (string) wp_parse_url((string) $url, PHP_URL_HOST);
        $host = preg_replace('/^www\./', '', strtolower($host));
        if (strpos($host, 'myfonts.com') !== false) {
            return 'MyFonts';
        }
        if (strpos($host, 'creativemarket.com') !== false) {
            return 'Creative Market';
        }
        if (strpos($host, 'creativefabrica.com') !== false) {
            return 'Creative Fabrica';
        }
        return $host !== '' ? $host : 'Unknown';
    }
}
