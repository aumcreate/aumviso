<?php defined('ABSPATH') || exit; ?>
<div class="aum-guide-steps">
    <?php foreach ($steps as $i => $step): ?>
    <div class="aum-guide-step" id="step-<?php echo esc_attr($i + 1); ?>">
        <div class="aum-guide-step__number"><?php echo esc_html($i + 1); ?></div>
        <div class="aum-guide-step__content">
            <?php if (!empty($step['name'])): ?>
            <h3 class="aum-guide-step__title"><?php echo esc_html($step['name']); ?></h3>
            <?php endif; ?>
            <?php if (!empty($step['text'])): ?>
            <p class="aum-guide-step__text"><?php echo esc_html($step['text']); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
