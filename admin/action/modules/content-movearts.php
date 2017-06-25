<?php

if (!defined('_root')) {
    exit;
}

/* ---  priprava promennych  --- */

$message = "";

/* ---  akce  --- */

if (isset($_POST['source'])) {

    // nacteni promennych
    $source = (int) _post('source');
    $target = (int) _post('target');
    $fullmove = _checkboxLoad("fullmove");

    // kontrola promennych
    $error_log = array();
    if (DB::count(_root_table, 'id=' . DB::val($source) . ' AND type=' . _page_category) === 0) {
        $error_log[] = _lang('admin.content.movearts.badsource');
    }
    if (DB::count(_root_table, 'id=' . DB::val($target) . ' AND type=' . _page_category) === 0) {
        $error_log[] = _lang('admin.content.movearts.badtarget');
    }
    if ($source == $target) {
        $error_log[] = _lang('admin.content.movearts.samecats');
    }

    // aplikace
    if (count($error_log) == 0) {

        if (!$fullmove) {
            $query = DB::query("SELECT id,home1,home2,home3 FROM " . _articles_table . " WHERE home1=" . $source . " OR home2=" . $source . " OR home3=" . $source);
            $counter = 0;
            while ($item = DB::row($query)) {
                if ($item['home1'] == $source) {
                    $homeid = 1;
                    $homecheck = array(2, 3);
                }
                if ($item['home2'] == $source) {
                    $homeid = 2;
                    $homecheck = array(1, 3);
                }
                if ($item['home3'] == $source) {
                    $homeid = 3;
                    $homecheck = array(1, 2);
                }
                DB::update(_articles_table, 'id=' . $item['id'], array('home' . $homeid => $target));
                foreach ($homecheck as $hc) {
                    if ($item['home' . $hc] == $target) {
                        $updatedata = array();
                        if ($hc != 1) {
                            $updatedata['home' . $hc] = -1;
                        } else {
                            $updatedata['home' . $homeid] = -1;
                        }
                        DB::update(_articles_table, 'id=' . $item['id'], $updatedata);
                    }
                }
                $counter++;
            }
        } else {
            DB::update(_articles_table, 'home1=' . $source . ' OR home2=' . $source. ' OR home3=' . $source, array(
                'home1' => $target,
                'home2' => -1,
                'home3' => -1
            ));
            $counter = DB::affectedRows();
        }

        $message = _msg(_msg_ok, _lang('admin.content.movearts.done', array("*moved*" => $counter)));
    } else {
        $message = _msg(_msg_warn, _msgList($error_log, 'errors'));
    }

}

/* ---  vystup  --- */

$output .= $message . "
<form class='cform' action='index.php?p=content-movearts' method='post'>"
    . _lang('admin.content.movearts.text1')
    . " " . _adminRootSelect("source", array('type' => _page_category))
    . _lang('admin.content.movearts.text2')
    . " " . _adminRootSelect("target", array('type' => _page_category))
    . " <input class='button' type='submit' value='" . _lang('global.do') . "'>
<br><br>
<label><input type='checkbox' name='fullmove' value='1'> " . _lang('admin.content.movearts.fullmove') . "</label>
" . _xsrfProtect() . "</form>
";
