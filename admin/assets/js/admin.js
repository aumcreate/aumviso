/* AumViso Admin JS */
jQuery(function ($) {

    var cfg = window.aumViso || {};
    cfg.strings = cfg.strings || {};

    function escHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    // =============================================
    // Settings: post type level toggles
    // =============================================
    $(document).on('change', '.aumviso-pt-toggle', function () {
        var pt = $(this).data('pt');
        $('.aumviso-level-' + pt).prop('disabled', !this.checked);
    });

    // =============================================
    // Tab navigation
    // =============================================
    $(document).on('click', '.aumviso-tab', function () {
        var tab = $(this).data('tab');
        $(this).siblings().removeClass('active');
        $(this).addClass('active');
        $(this).closest('.aumviso-metabox').find('.aumviso-tab-content').removeClass('active');
        $(this).closest('.aumviso-metabox').find('[data-content="' + tab + '"]').addClass('active');
    });

    // =============================================
    // Character counter
    // =============================================
    function updateCount($el) {
        var max   = parseInt($el.data('max'), 10);
        var count = $el.val().length;
        var $ctr  = $('#' + $el.data('count'));

        $ctr.text(count + ' / ' + max);
        $ctr.removeClass('warn over');
        if (count > max) {
            $ctr.addClass('over');
        } else if (count > max * 0.85) {
            $ctr.addClass('warn');
        }
    }

    $('.aumviso-char-input').each(function () { updateCount($(this)); });
    $(document).on('input', '.aumviso-char-input', function () { updateCount($(this)); });

    // =============================================
    // SERP Preview
    // =============================================
    var $previewTitle = $('#aumviso_preview_title');
    var $previewDesc  = $('#aumviso_preview_desc');

    $('#aumviso_seo_title').on('input', function () {
        var val = $(this).val().trim();
        $previewTitle.text(val || $(this).attr('placeholder'));
        $previewTitle.toggleClass('too-long', val.length > 60);
    });

    $('#aumviso_seo_description').on('input', function () {
        var val = $(this).val().trim();
        $previewDesc.text(val || $(this).attr('placeholder'));
    });

    // =============================================
    // OG Image picker
    // =============================================
    var ogFrame;
    $('#aumviso_og_select').on('click', function () {
        if (ogFrame) { ogFrame.open(); return; }
        ogFrame = wp.media({
            title: 'Select OG Image',
            button: { text: 'Use this image' },
            multiple: false,
            library: { type: 'image' }
        });
        ogFrame.on('select', function () {
            var att = ogFrame.state().get('selection').first().toJSON();
            $('#aumviso_og_image').val(att.url);
            $('#aumviso_og_preview').html('<img src="' + escHtml(att.url) + '" alt="">');
            $('#aumviso_og_remove').show();
        });
        ogFrame.open();
    });

    $('#aumviso_og_remove').on('click', function () {
        $('#aumviso_og_image').val('');
        $('#aumviso_og_preview').html('');
        $(this).hide();
    });

    // =============================================
    // Schema field toggles
    // =============================================
    function toggleSchemaFields() {
        var val = $('#aumviso_schema_type').val();
        $('#aumviso-product-fields').toggle(val === 'Product');
        $('#aumviso-review-fields').toggle(val === 'Review');
        $('#aumviso-video-fields').toggle(val === 'VideoObject');
    }

    $(document).on('change', '#aumviso_schema_type', toggleSchemaFields);
    toggleSchemaFields();

    // =============================================
    // SEO Score — real-time debounced auto-refresh
    // =============================================

    var $reanalyzeBtn = $('#aumviso-reanalyze');
    var scoreDebounce = null;

    // Retrieve the current editor content
    // Compatible with Gutenberg, TinyMCE, and plain-text mode
    function getLiveContent() {
        // Gutenberg block editor
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            try {
                var gutenbergContent = wp.data.select('core/editor').getEditedPostContent();
                if (typeof gutenbergContent === 'string') {
                    return gutenbergContent;
                }
            } catch (e) {}
        }
        // Classic editor: TinyMCE
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('content');
            if (editor && !editor.isHidden()) {
                return editor.getContent();
            }
        }
        // Classic editor: plain-text mode
        return $('#content').val() || '';
    }

    // Retrieve the post title
    // Compatible with Gutenberg and the classic editor
    function getLivePostTitle() {
        // Gutenberg block editor
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            try {
                var gutenbergTitle = wp.data.select('core/editor').getEditedPostAttribute('title');
                if (typeof gutenbergTitle === 'string') {
                    return gutenbergTitle;
                }
            } catch (e) {}
        }
        return $('#title').val() || '';
    }

    // Detect the featured image state, including unsaved changes
    function getLiveThumbnail() {
        // Classic editor: #_thumbnail_id has a value other than -1 or 0 when set
        var thumbId = jQuery('#_thumbnail_id').val();
        if (thumbId !== undefined) {
            return (thumbId && thumbId !== '-1' && thumbId !== '0') ? 1 : 0;
        }
        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            try {
                var featuredMedia = wp.data.select('core/editor').getEditedPostAttribute('featured_media');
                return featuredMedia ? 1 : 0;
            } catch (e) {}
        }
        return 0;
    }

    function triggerScoreRefresh(saveKw) {
        if (!$reanalyzeBtn.length) return;

        var postId  = $reanalyzeBtn.data('post');
        var nonce   = $reanalyzeBtn.data('nonce');
        var kw      = $('#aumviso_focus_kw').val() || '';

        $reanalyzeBtn.prop('disabled', true).addClass('is-busy');

        $.post(ajaxurl, {
            action:              'aumviso_get_score',
            post_id:             postId,
            focus_kw:            kw,
            nonce:               nonce,
            save_kw:             saveKw ? 1 : 0,
            live_seo_title:      $('#aumviso_seo_title').val() || '',
            live_seo_desc:       $('#aumviso_seo_description').val() || '',
            live_post_title:     getLivePostTitle(),
            live_post_content:   getLiveContent(),
            live_has_thumbnail:  getLiveThumbnail()
        })
        .done(function (res) {
            if (res.success) {
                $('.aumviso-score-number').text(res.data.total);
                $('.aumviso-score-circle').attr('class', 'aumviso-score-circle aumviso-score-' + escHtml(res.data.grade_class));
                $('.aumviso-score-label').text(res.data.grade_label);
                var html = '';
                res.data.checks.forEach(function (c) {
                    var iconClass = c.status === 'good' ? 'dashicons-yes-alt' : (c.status === 'ok' ? 'dashicons-warning' : 'dashicons-dismiss');
                    html += '<li class="aumviso-check aumviso-check--' + escHtml(c.status) + '">'
                        + '<span class="aumviso-check__icon dashicons ' + iconClass + '" aria-hidden="true"></span>'
                        + '<span class="aumviso-check__text">' + escHtml(c.label) + '</span>'
                        + '<span class="aumviso-check__pts">+' + escHtml(c.points) + '</span>'
                        + '</li>';
                });
                $('#aumviso-checks-list').html(html);
            }
        })
        .always(function () {
            $reanalyzeBtn.prop('disabled', false).removeClass('is-busy');
        });
    }

    // Handle manual clicks on the re-analyze button
    // The focus keyword is persisted when triggered manually
    $reanalyzeBtn.on('click', function () {
        triggerScoreRefresh(true);
    });

    // Debounced refresh for SEO Title / Meta Description / Focus Keyword input events
    function scheduleRefresh() {
        clearTimeout(scoreDebounce);
        scoreDebounce = setTimeout(function () {
            triggerScoreRefresh(false);
        }, 800);
    }

    $(document).on('input', '#aumviso_seo_title, #aumviso_seo_description, #aumviso_focus_kw', scheduleRefresh);

    // Listen for post title changes
    $(document).on('input', '#title', scheduleRefresh);

    // Listen for TinyMCE editor content changes
    if (typeof tinymce !== 'undefined') {
        $(document).on('tinymce-editor-init', function (event, editor) {
            if (editor.id === 'content') {
                // Trigger an immediate refresh once TinyMCE is initialized
                // to avoid scoring against empty initial content
                triggerScoreRefresh(false);
                // input/keyup captures typing and deletion
                // NodeChange captures toolbar formatting changes
                // such as heading level changes or bold formatting
                editor.on('input keyup NodeChange', function () {
                    scheduleRefresh();
                });
            }
        });
    }

    // Listen for Gutenberg editor content changes
    if (typeof wp !== 'undefined' && wp.data) {
        wp.data.subscribe(function () {
            scheduleRefresh();
        });
    }

    // Listen for featured image changes
    // Classic editor: observe #postimagediv via MutationObserver
    var thumbContainer = document.getElementById('postimagediv');
    if (thumbContainer) {
        new MutationObserver(function () {
            scheduleRefresh();
        }).observe(thumbContainer, { childList: true, subtree: true });
    }

    // =============================================
    // AI Tools
    // =============================================
    var $aiResult = $('#aumviso-ai-result');
    var $aiOutput = $aiResult.find('.aumviso-ai-output');

    $(document).on('click', '.aumviso-ai-btn', function () {
        var $btn    = $(this);
        var action  = $btn.data('action');
        var postId  = $btn.data('post');

        $btn.prop('disabled', true).text(cfg.strings.generating);
        $aiResult.hide();

        $.post(cfg.ajax_url, {
            action:            'aumviso_' + action,
            post_id:           postId,
            nonce:             cfg.nonce,
            live_post_content: getLiveContent(),
            live_post_title:   getLivePostTitle()
        })
        .done(function (res) {
            if (res.success) {
                $aiOutput.text(res.data.text);
                $aiResult.show();
            } else {
                alert((res.data && res.data.message) || cfg.strings.error);
            }
        })
        .fail(function () { alert(cfg.strings.error); })
        .always(function () { $btn.prop('disabled', false).text($btn.data('orig-text') || $btn.text()); });
    });

    // Store original button text
    $('.aumviso-ai-btn').each(function () { $(this).data('orig-text', $(this).text()); });

    // Copy AI output
    $('#aumviso-ai-copy').on('click', function () {
        var text = $aiOutput.text();
        navigator.clipboard.writeText(text).then(function () {
            var $btn = $('#aumviso-ai-copy');
            $btn.html('<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' + cfg.strings.copied);
            setTimeout(function () { $btn.html('<span class="dashicons dashicons-clipboard" aria-hidden="true"></span> Copy'); }, 2000);
        });
    });

    // =============================================
    // Internal Links (AJAX add / delete)
    // =============================================
    $('#aumviso-ilink-add').on('click', function () {
        var $btn    = $(this);
        var keyword = $('#ilink_keyword').val().trim();
        var url     = $('#ilink_url').val().trim();
        var max     = $('#ilink_max').val();
        var caseS   = $('#ilink_case').is(':checked') ? 1 : 0;

        if (!keyword || !url) { alert('Please fill in keyword and URL.'); return; }

        $btn.prop('disabled', true);

        $.post(cfg.ajax_url, {
            action:        'aumviso_ilink_add',
            nonce:         $btn.data('nonce'),
            keyword:       keyword,
            target_url:    url,
            max_links:     max,
            case_sensitive: caseS
        })
        .done(function (res) {
            if (res.success) {
                var id = res.data.id;
                var row = '<tr id="ilink-row-' + id + '">'
                    + '<td><strong>' + escHtml(keyword) + '</strong></td>'
                    + '<td><a class="aml-link" href="' + escHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escHtml(url) + '</a></td>'
                    + '<td class="aml-col-center">' + escHtml(max) + '</td>'
                    + '<td class="aml-col-center">' + (caseS ? '<span class="dashicons dashicons-yes" aria-hidden="true"></span>' : '-') + '</td>'
                    + '<td class="aml-col-center"><button type="button" class="aml-btn aml-btn-danger aumviso-ilink-delete" data-id="' + escHtml(id) + '" data-nonce="' + escHtml($btn.data('nonce')) + '">Delete</button></td>'
                    + '</tr>';

                $('#aumviso-no-keywords').remove();
                $('#aumviso-ilinks-table tbody').append(row);

                $('#ilink_keyword, #ilink_url').val('');
                $('#ilink_max').val('1');
                $('#ilink_case').prop('checked', false);
            } else {
                alert((res.data && res.data.message) || 'Error.');
            }
        })
        .always(function () { $btn.prop('disabled', false); });
    });

    $(document).on('click', '.aumviso-ilink-delete', function () {
        if (!confirm('Delete this keyword?')) return;
        var $btn = $(this);
        var id   = $btn.data('id');

        $.post(cfg.ajax_url, {
            action: 'aumviso_ilink_delete',
            nonce:  $btn.data('nonce'),
            id:     id
        })
        .done(function (res) {
            if (res.success) {
                $('#ilink-row-' + id).remove();
            }
        });
    });

    // =============================================
    // FAQ related questions
    // =============================================
    $(document).on('click', '#aum-rq-add', function () {
        var html = '<div class="aum-rq-item" style="margin-bottom:8px;display:flex;gap:4px;">'
            + '<input type="text" name="aumviso_related_questions[]" placeholder="' + escHtml(cfg.strings.related_question || 'Related question...') + '" style="flex:1;">'
            + '<button type="button" class="button aum-rq-remove" aria-label="' + escHtml(cfg.strings.remove || 'Remove') + '"><span class="dashicons dashicons-no-alt"></span></button>'
            + '</div>';
        $('#aum-rq-list').append(html);
    });

    $(document).on('click', '.aum-rq-remove', function () {
        $(this).closest('.aum-rq-item').remove();
    });

    $(document).on('click', '#aum-copy-prompt', function () {
        var $btn = $(this);
        var $ta = $('#aum-ai-prompt-template');
        $ta.trigger('select');
        document.execCommand('copy');
        $btn.html('<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' + escHtml(cfg.strings.copied || 'Copied!'));
        setTimeout(function () {
            $btn.html('<span class="dashicons dashicons-clipboard" aria-hidden="true"></span> ' + escHtml(cfg.strings.copy_prompt || 'Copy Prompt'));
        }, 2000);
    });

    // =============================================
    // Guide meta box dynamic rows
    // =============================================
    function reindexSteps() {
        $('#aum-guide-steps .aum-step-item').each(function (i) {
            $(this).find('> div > strong').text((cfg.strings.step || 'Step') + ' ' + (i + 1));
        });
    }

    $(document).on('click', '#aum-add-step', function () {
        var html = '<div class="aum-step-item" style="border:1px solid #ddd;padding:12px;margin-bottom:8px;background:#fafafa;">'
            + '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">'
            + '<strong></strong>'
            + '<button type="button" class="button button-small aum-remove-step">' + escHtml(cfg.strings.remove || 'Remove') + '</button>'
            + '</div>'
            + '<input type="text" name="guide_step_name[]" placeholder="' + escHtml(cfg.strings.step_name || 'Step name') + '" class="large-text" style="margin-bottom:6px;">'
            + '<textarea name="guide_step_text[]" class="large-text" rows="3" placeholder="' + escHtml(cfg.strings.step_description || 'Step description') + '"></textarea>'
            + '</div>';
        $('#aum-guide-steps').append(html);
        reindexSteps();
        scheduleRefresh();
    });

    $(document).on('click', '.aum-remove-step', function () {
        $(this).closest('.aum-step-item').remove();
        reindexSteps();
        scheduleRefresh();
    });

    $(document).on('click', '#aum-add-tool', function () {
        var html = '<div class="aum-tool-item" style="display:flex;gap:4px;margin-bottom:4px;">'
            + '<input type="text" name="guide_tools[]" style="width:250px;">'
            + '<button type="button" class="button aum-remove-tool"><span class="dashicons dashicons-no-alt"></span></button>'
            + '</div>';
        $('#aum-guide-tools').append(html);
    });

    $(document).on('click', '.aum-remove-tool', function () {
        $(this).closest('.aum-tool-item').remove();
    });

    $(document).on('click', '#aum-add-material', function () {
        var html = '<div class="aum-material-item" style="display:flex;gap:4px;margin-bottom:4px;">'
            + '<input type="text" name="guide_materials[]" style="width:250px;">'
            + '<button type="button" class="button aum-remove-material"><span class="dashicons dashicons-no-alt"></span></button>'
            + '</div>';
        $('#aum-guide-materials').append(html);
    });

    $(document).on('click', '.aum-remove-material', function () {
        $(this).closest('.aum-material-item').remove();
    });

    // =============================================
    // FAQ AI Prompt — keep title and content synchronized in real time
    // =============================================
    var $promptTpl = $('#aum-ai-prompt-template');

    function updateFaqPrompt() {
        if (!$promptTpl.length) return;

        var title   = $('#title').val() || '[FAQ Title]';
        var content = '';

        // Retrieve content
        // Compatible with TinyMCE and plain-text mode
        if (typeof tinymce !== 'undefined') {
            var ed = tinymce.get('content');
            if (ed && !ed.isHidden()) {
                content = ed.getContent({ format: 'text' });
            }
        }
        if (!content) content = $('#content').val() || '';

        // Extract the first 30 words
        // Keep this consistent with wp_trim_words on the PHP side
        var words = content.trim().split(/\s+/).slice(0, 30).join(' ');
        if (content.trim().split(/\s+/).length > 30) words += '...';

        var prompt = 'Generate 5 related questions (with short answers, max 50 words each) for the following FAQ:\n\n'
            + 'Title: ' + title + '\n'
            + 'Content: ' + words + '\n\n'
            + 'Format:\nQ: [question]\nA: [answer]\n\n(Repeat for all 5 questions)';

        $promptTpl.val(prompt);
    }

    if ($promptTpl.length) {
        // Initialize once on page load
        updateFaqPrompt();

        // Listen for title changes
        $(document).on('input', '#title', updateFaqPrompt);

        // Listen for TinyMCE changes
        if (typeof tinymce !== 'undefined') {
            $(document).on('tinymce-editor-init', function (event, editor) {
                if (editor.id === 'content') {
                    editor.on('input keyup', updateFaqPrompt);
                }
            });
        }

        // Listen for plain-text mode changes
        $(document).on('input', '#content', updateFaqPrompt);
    }

    // =============================================
    // CPT-specific field changes → trigger score refresh
    // =============================================
    // Glossary: short definition, full explanation
    $(document).on('input', '#glossary_short_def, #glossary_full_explanation', scheduleRefresh);

    // Guide: step name, step description
    // Uses delegated events for dynamically added fields
    $(document).on('input', 'input[name="guide_step_name[]"], textarea[name="guide_step_text[]"]', scheduleRefresh);

    // FAQ: Answer field
    // wp_editor uses TinyMCE, so it is handled separately
    $(document).on('input', 'textarea[name="aumviso_faq_answer"]', scheduleRefresh);

    // =============================================
    // Run score calculation once during page initialization
    // =============================================
    // Poll until TinyMCE is initialized, then trigger immediately
    // Wait up to 10 seconds maximum
    if ($reanalyzeBtn.length) {
        var initTries = 0;
        var initTimer = setInterval(function () {
            initTries++;
            var ready = false;

            // Check whether TinyMCE is ready
            if (typeof tinymce !== 'undefined') {
                var ed = tinymce.get('content');
                if (ed && !ed.isHidden() && ed.getContent().length > 0) {
                    ready = true;
                }
            }
            // Plain-text mode: #content textarea already has content
            if (!ready && $('#content').val()) {
                ready = true;
            }
            // Gutenberg
            if (!ready && typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                try {
                    if (wp.data.select('core/editor').getEditedPostContent() !== undefined) {
                        ready = true;
                    }
                } catch(e) {}
            }

            if (ready || initTries >= 20) {
                clearInterval(initTimer);
                triggerScoreRefresh(false);
            }
        }, 500);
    }

});

// Overview: toggle issue detail panels
jQuery(function ($) {
    // Toggle button
    $(document).on('click', '.aumviso-toggle-detail', function () {
        var target = $('#' + $(this).data('target'));
        var visible = target.is(':visible');
        target.slideToggle(150);
        $(this).text(visible ? 'Show details' : 'Hide details');
    });

    // Auto-expand when paginating via URL hash
    var hash = window.location.hash;
    if (hash && $(hash).length) {
        $(hash).show();
        $('[data-target="' + hash.substring(1) + '"]').text('Hide details');
    }
});

/*
 * The AI key and model fields only apply to a direct provider.
 *
 * The WordPress AI Client carries its own credentials, configured once under Settings → Connectors, so
 * asking for a key here would contradict the hint printed right above the select. The server already
 * renders the right initial state; this only keeps it true when the choice changes.
 */
jQuery(function ($) {
    var $sel = $('#aumviso_ai_provider');
    if (!$sel.length) return;
    $sel.on('change', function () {
        $('.aumviso-ai-direct').prop('hidden', $(this).val() === 'core');
    });
});
