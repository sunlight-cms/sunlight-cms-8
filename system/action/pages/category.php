<?php

if (!defined('_root')) {
    exit;
}

// vychozi nastaveni
if ($_page['var2'] === null) {
    $_page['var2'] = _articlesperpage;
}

// zobrazeni clanku?
if ($_index['segment'] !== null) {
    require _root . 'system/action/pages/include/article.php';
    return;
}

// nastaveni strankovani
$artsperpage = $_page['var2'];
switch ($_page['var1']) {
    case 1:
        $artorder = "time DESC";
        break;
    case 2:
        $artorder = "id DESC";
        break;
    case 3:
        $artorder = "title";
        break;
    case 4:
        $artorder = "title DESC";
        break;
}

// titulek
$_index['title'] = $_page['title'];

// rss
$_index['rsslink'] = _linkRSS($id, _rss_latest_articles, false);

// obsah
Sunlight\Extend::call('page.category.content.before', $extend_args);
if ($_page['content'] != '') {
    $output .= _parseHCM($_page['content']) . "\n\n<div class='hr category-hr'><hr></div>\n\n";
}
Sunlight\Extend::call('page.category.content.after', $extend_args);

// vypis clanku
list($art_joins, $art_cond, $art_count) = _articleFilter('art', array($id), null, true);
$paging = _resultPaging($_index['url'], $artsperpage, $art_count);
$userQuery = _userQuery('art.author');
$arts = DB::query("SELECT art.id,art.title,art.slug,art.perex," . $userQuery['column_list'] . "," . ($_page['var4'] ? 'art.picture_uid,' : '') . "art.time,art.comments,art.readnum,cat1.slug AS cat_slug,(SELECT COUNT(*) FROM " . _posts_table . " AS post WHERE home=art.id AND post.type=" . _post_article_comment . ") AS comment_count FROM " . _articles_table . " AS art " . $art_joins . ' ' . $userQuery['joins'] . " WHERE " . $art_cond . " ORDER BY " . $artorder . " " . $paging['sql_limit']);

if (DB::size($arts) != 0) {
    if (_showPagingAtTop()) {
        $output .= $paging['paging'];
    }
    while ($art = DB::row($arts)) {
        $extend_item_args = Sunlight\Extend::args($output, array('page' => $_page, 'item-query' => &$art));
        Sunlight\Extend::call('page.category.item.before', $extend_item_args);
        $output .= _articlePreview($art, $userQuery, $_page['var3'] == 1, true, $art['comment_count']);
        Sunlight\Extend::call('page.category.item.after', $extend_item_args);
    }
    if (_showPagingAtBottom()) {
        $output .= $paging['paging'];
    }
} else {
    $output .= '<p>' . $_lang['misc.category.noarts'] . '</p>';
}
