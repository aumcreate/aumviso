<?php defined('ABSPATH') || exit; ?>
<?php
// Group terms alphabetically
$grouped = [];
foreach ($terms as $term) {
    $letter = strtoupper(mb_substr($term->post_title, 0, 1));
    $grouped[$letter][] = $term;
}
ksort($grouped);
?>

<div class="aum-glossary-index">
    <?php foreach (array_keys($grouped) as $letter): ?>
    <a href="#glossary-<?php echo esc_attr($letter); ?>" class="aum-glossary-letter-link">
        <?php echo esc_html($letter); ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="aum-glossary-list">
    <?php foreach ($grouped as $letter => $group_terms): ?>
    <div class="aum-glossary-group" id="glossary-<?php echo esc_attr($letter); ?>">
        <h3 class="aum-glossary-letter"><?php echo esc_html($letter); ?></h3>
        <?php foreach ($group_terms as $term): ?>
        <div class="aum-glossary-term" id="term-<?php echo esc_attr($term->post_name); ?>">
            <h4 class="aum-glossary-term__name">
                <a href="<?php echo esc_url(get_permalink($term->ID)); ?>">
                    <?php echo esc_html($term->post_title); ?>
                </a>
            </h4>
            <?php
            $short_def = get_post_meta($term->ID, '_aumviso_glossary_short_def', true);
            if ($short_def): ?>
            <p class="aum-glossary-term__def"><?php echo esc_html($short_def); ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
