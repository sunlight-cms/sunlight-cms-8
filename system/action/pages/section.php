<?php

use Sunlight\Comment\CommentService;
use Sunlight\Extend;

if (!defined('_root')) {
    exit;
}

// titulek
$_index['title'] = $_page['title'];

// obsah
Extend::call('page.section.content.before', $extend_args);
$output .= \Sunlight\HCM::parse($_page['content']);
Extend::call('page.section.content.after', $extend_args);

// komentare
if ($_page['var1'] == 1 && _comments) {
    $output .= CommentService::render(CommentService::RENDER_SECTION_COMMENTS, $id, $_page['var3']);
}
