        // Get tag objects by name, ensure all tags from the list are present
        $tags_data = [];
        if (!empty($tag_names)) {
            foreach ($tag_names as $tag_name) {
                $tag = $this->get_term_by_name_and_lang($tag_name, $source_language);
                if (!$tag) {
                    // Create the source language tag if it doesn't exist
                    $new_tag = wp_insert_term($tag_name, 'post_tag', [
                        'slug' => sanitize_title($tag_name) . '-' . $source_language,
                        'name' => $tag_name
                    ]);
                    if (!is_wp_error($new_tag)) {
                        $tag = get_term($new_tag['term_id']);
                        // Set language to source language if Polylang is available
                        if (function_exists('pll_set_term_language')) {
                            pll_set_term_language($tag->term_id, $source_language);
                        }
                    } else {
                        // tag exists, we need to ensure it's in the correct language
                        $tag = get_term_by('name', $tag_name, 'post_tag');
                        if (function_exists('pll_set_term_language')) {
                            pll_set_term_language($tag->term_id, $source_language);
                        }
                    }
                }
                if ($tag) {
                    // Get translations for this tag
                    $terms = function_exists('pll_get_term_translations') ? pll_get_term_translations($tag->term_id) : [];
                    $translations = [];
                    foreach ($langs as $lang) {
                        if ($lang === $source_language) {
                            continue;
                        }
                        $translated_term_id = $terms[$lang] ?? null;
                        $translation = $translated_term_id ? get_term($translated_term_id, 'post_tag') : null;
                        if ($translation) {
                            $translations[$lang] = [
                                'term_id' => $translation->term_id,
                                'name' => $translation->name,
                            ];
                        }
                    }
                    $tags_data[] = [
                        'term_id' => $tag->term_id,
                        'name' => $tag->name,
                        'translations' => $translations,
                    ];
                }
            }
        }

        // Get source language name
        $source_lang_name = '';
        if (function_exists('pll_languages_list')) {
            $lang_names = pll_languages_list(['fields' => 'name']);
        } else {
            $lang_names = ['Polish', 'English', 'Italian'];
        }
        $lang_index = array_search($source_language, $langs);
        if ($lang_index !== false) {
            $source_lang_name = $lang_names[$lang_index] ?? strtoupper($source_language);
        } else {
            $source_lang_name = strtoupper($source_language);
        }

        TemplateRenderer::display('admin/tag-translation/page.twig', [
            'tags' => $tags_data,
            'langs' => $langs,
            'source_language' => $source_language,
            'source_lang_name' => $source_lang_name,
            'can_manage_options' => current_user_can('manage_options'),
        ]);
    }

