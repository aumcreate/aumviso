<?php defined('ABSPATH') || exit; ?>
<div class="aum-faq-list <?php echo esc_attr($atts['style'] ?? 'accordion'); ?>">
    <?php foreach ($faqs as $faq): ?>
    <?php
    $answer = get_post_meta($faq->ID, '_aumviso_faq_answer', true) ?: $faq->post_content;
    ?>
    <div class="aum-faq-item" id="faq-<?php echo esc_attr($faq->ID); ?>">
        <div class="aum-faq-question">
            <?php echo esc_html($faq->post_title); ?>
        </div>
        <div class="aum-faq-answer">
            <?php echo wp_kses_post($answer); ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
