<?php

if (!defined('_root')) {
    exit;
}

/* ---  priprava promennych  --- */

$message = "";
$continue = false;
if (isset($_GET['g'])) {
    $g = (int) _get('g');
    $galdata = DB::queryRow("SELECT title,var2,var3,var4 FROM " . _root_table . " WHERE id=" . $g . " AND type=" . _page_gallery);
    if ($galdata !== false) {
        if ($galdata['var2'] === null) {
            $galdata['var2'] = _galdefault_per_page;
        }
        if ($galdata['var3'] === null) {
            $galdata['var3'] = _galdefault_thumb_h;
        }
        if ($galdata['var4'] === null) {
            $galdata['var4'] = _galdefault_thumb_w;
        }
        $continue = true;
    }
}

/* ---  akce  --- */

if (isset($_POST['xaction']) && $continue) {

    switch (_post('xaction')) {

            /* -  vlozeni obrazku  - */
        case 1:

            // nacteni zakladnich promennych
            $title = _cutHtml(_e(trim(_post('title'))), 255);
            if (!_checkboxLoad("autoprev")) {
                $prev = _cutHtml(_e(_post('prev')), 255);
            } else {
                $prev = "";
            }
            $full = _cutHtml(_e(_post('full')), 255);

            // vlozeni na zacatek nebo nacteni poradoveho cisla
            if (_checkboxLoad("moveords")) {
                $smallerord = DB::queryRow("SELECT ord FROM " . _images_table . " WHERE home=" . $g . " ORDER BY ord LIMIT 1");
                if ($smallerord !== false) {
                    $ord = $smallerord['ord'];
                } else {
                    $ord = 1;
                }
                DB::update(_images_table, 'home=' . $g, array('ord' => DB::raw('ord+1')));
            } else {
                $ord = floatval(_post('ord'));
            }

            // kontrola a vlozeni
            if ($full != '') {
                DB::insert(_images_table, array(
                    'home' => $g,
                    'ord' => $ord,
                    'title' => $title,
                    'prev' => $prev,
                    'full' => $full
                ));
                $message = _msg(_msg_ok, _lang('global.inserted'));
            } else {
                $message = _msg(_msg_warn, _lang('admin.content.manageimgs.insert.error'));
            }

            break;

            /* -  aktualizace obrazku  - */
        case 4:
            $lastid = -1;
            $sql = "";
            foreach ($_POST as $var => $val) {
                if ($var == "xaction") {
                    continue;
                }
                $var = explode("_", $var);
                if (count($var) == 2) {
                    $id = (int) substr($var[0], 1);
                    $var = $var[1];
                    if ($lastid == -1) {
                        $lastid = $id;
                    }
                    $quotes = true;
                    $skip = false;
                    switch ($var) {
                        case "title":
                            $val = _e($val);
                            break;
                        case "full":
                            $val = 'IF(in_storage,full,' . DB::val(_e($val)) . ')';
                            $quotes = false;
                            break;
                        case "prevtrigger":
                            $var = "prev";
                            if (!_checkboxLoad('i' . $id . '_autoprev')) {
                                $val = _cutHtml(_e(_post('i' . $id . '_prev')), 255);
                            } else {
                                $val = '';
                            }
                            break;
                        case "ord":
                            $val = (int) $val;
                            $quotes = false;
                            break;
                        default:
                            $skip = true;
                            break;
                    }

                    // ukladani a cachovani
                    if (!$skip) {

                        // ulozeni
                        if ($lastid != $id) {
                            $sql = trim($sql, ",");
                            DB::query("UPDATE " . _images_table . " SET " . $sql . " WHERE id=" . $lastid . " AND home=" . $g);
                            $sql = "";
                            $lastid = $id;
                        }

                        if ($sql !== '') {
                            $sql .= ',';
                        }
                        $sql .= $var . '=';
                        if ($quotes) {
                            $sql .= DB::val($val);
                        } else {
                            $sql .= $val;
                        }
                    }

                }
            }

            // ulozeni posledniho nebo jedineho obrazku
            if ($sql != '') {
                DB::query("UPDATE " . _images_table . " SET " . $sql . " WHERE id=" . $id . " AND home=" . $g);
            }

            $message = _msg(_msg_ok, _lang('global.saved'));
            break;

            /* -  presunuti obrazku  - */
        case 5:
            $newhome = (int) _post('newhome');
            if ($newhome != $g) {
                if (DB::count(_root_table, 'id=' . DB::val($newhome) . ' AND type=' . _page_gallery) !== 0) {
                    if (DB::count(_images_table, 'home=' . DB::val($g)) !== 0) {

                        // posunuti poradovych cisel v cilove galerii
                        $moveords = _checkboxLoad("moveords");
                        if ($moveords) {

                            // nacteni nejvetsiho poradoveho cisla v teto galerii
                            $greatestord = DB::queryRow("SELECT ord FROM " . _images_table . " WHERE home=" . $g . " ORDER BY ord DESC LIMIT 1");
                            $greatestord = $greatestord['ord'];

                            DB::update(_images_table, 'home=' . $newhome, array('ord' => DB::raw('ord+' . $greatestord)));
                        }

                        // presun obrazku
                        DB::update(_images_table, 'home=' . $g, array('home' => $newhome));

                        // zprava
                        $message = _msg(_msg_ok, _lang('global.done'));

                    } else {
                        $message = _msg(_msg_warn, _lang('admin.content.manageimgs.moveimgs.nokit'));
                    }
                } else {
                    $message = _msg(_msg_warn, _lang('global.badinput'));
                }
            } else {
                $message = _msg(_msg_warn, _lang('admin.content.manageimgs.moveimgs.samegal'));
            }
            break;

            /* -  odstraneni vsech obrazku  - */
        case 6:
            if (_checkboxLoad("confirm")) {
                _adminDeleteGalleryStorage('home=' . $g);
                DB::delete(_images_table, 'home=' . $g);
                $message = _msg(_msg_ok, _lang('global.done'));
            }
            break;

            /* -  upload obrazku  - */
        case 7:

            // prepare vars
            $done = array();
            $total = 0;

            // prepare and check image storage
            $stor_a = 'images/galleries/' . $g . '/';
            $stor = _root . $stor_a;
            if (($nostor = !is_dir($stor)) || !is_writeable($stor)) {
                // try to create or chmod
                if ($nostor && !mkdir($stor, 0777) || !$nostor && !chmod($stor, 0777)) {
                    $message = _msg(_msg_err, sprintf(_lang('admin.content.manageimgs.upload.acerr'), $stor));
                    break;
                }
            }

            // process uploads
            foreach ($_FILES as $file) {

                if (!is_array($file['name'])) {
                    continue;
                }
                for ($i = 0; isset($file['name'][$i]); ++$i) {

                    ++$total;

                    // check file
                    if ($file['error'][$i] != 0 || !is_uploaded_file($file['tmp_name'][$i])) {
                        continue;
                    }

                    // prepare options
                    $picOpts = array(
                        'file_path' => $file['tmp_name'][$i],
                        'file_name' => $file['name'][$i],
                        'target_path' => $stor,
                        'jpg_quality' => 95,
                        'resize' => array(
                            'mode' => 'fit',
                            'keep_smaller' => true,
                            'x' => _galuploadresize_w,
                            'y' => _galuploadresize_h,
                        ),
                    );
                    Sunlight\Extend::call('admin.gallery.picture', array('opts' => &$picOpts));

                    // process
                    $picUid = _pictureProcess($picOpts, $picError, $picFormat);

                    if ($picUid === false) {
                        continue;
                    }

                    $done[] = $picUid . '.' . $picFormat;

                }

            }

            // save to database
            if (!empty($done)) {

                // get order number
                if (isset($_POST['moveords'])) {
                    // move
                    $ord = 0;
                    DB::update(_images_table, 'home=' . $g, array('ord' => DB::raw('ord+' . count($done))));
                } else {
                    // get max + 1
                    $ord = DB::queryRow("SELECT ord FROM " . _images_table . " WHERE home=" . $g . " ORDER BY ord DESC LIMIT 1");
                    $ord = $ord['ord'] + 1;
                }

                // query
                $insertdata = array();
                foreach ($done as $d) {
                    $insertdata[] = array(
                        'home' => $g,
                        'ord' => $ord,
                        'title' => '',
                        'prev' => '',
                        'full' => $stor_a . $d,
                        'in_storage' => 1
                    );
                    ++$ord;
                }
                DB::insertMulti(_images_table, $insertdata);

            }

            // message
            $done = count($done);
            $message = _msg(($done === $total) ? _msg_ok : _msg_warn, sprintf(_lang('admin.content.manageimgs.upload.msg'), $done, $total));
            break;

    }

}

/* ---  odstraneni obrazku  --- */

if (isset($_GET['del']) && _xsrfCheck(true) && $continue) {
    $del = (int) _get('del');
    _adminDeleteGalleryStorage('id=' . $del . ' AND home=' . $g);
    DB::delete(_images_table, 'id=' . $del . ' AND home=' . $g);
    if (DB::affectedRows() === 1) {
        $message = _msg(_msg_ok, _lang('global.done'));
    }
}

/* ---  vystup  --- */

if ($continue) {
    $output .= _adminBacklink('index.php?p=content-editgallery&id=' . $g) . "
<h1>" . _lang('admin.content.manageimgs.title') . "</h1>
<p class='bborder'>" . _lang('admin.content.manageimgs.p', array("*galtitle*" => $galdata['title'])) . "</p>

" . $message . "

<fieldset>
<legend>" . _lang('admin.content.manageimgs.upload') . "</legend>
<form action='index.php?p=content-manageimgs&amp;g=" . $g . "' method='post' enctype='multipart/form-data'>
    <p>" . sprintf(_lang('admin.content.manageimgs.upload.text'), _galuploadresize_w, _galuploadresize_h) . "</p>
    <input type='hidden' name='xaction' value='7'>
    <div id='fmanFiles'><input type='file' name='uf0[]' multiple> <a href='#' onclick='return Sunlight.admin.fmanAddFile();'>" . _lang('admin.fman.upload.addfile') . "</a></div>
    <div class='hr'><hr></div>
    <p>
        <input type='submit' value='" . _lang('admin.content.manageimgs.upload.submit') . "'>
        <label><input type='checkbox' value='1' name='moveords' checked> " . _lang('admin.content.manageimgs.moveords') . "</label>"
        . _renderUploadLimit()
        . ' <small>' . _lang('global.uploadext') . ": <em>" . implode(', ', Sunlight\Core::$imageExt) . "</em></small>
    </p>
" . _xsrfProtect() . "</form>
</fieldset>

<fieldset class='hs_fieldset'>
<legend>" . _lang('admin.content.manageimgs.insert') . "  <small>(" . _lang('admin.content.manageimgs.insert.tip') . ")</small></legend>
<form action='index.php?p=content-manageimgs&amp;g=" . $g . "' method='post' name='addform'>
<input type='hidden' name='xaction' value='1'>

<table>
<tr>
<th>" . _lang('admin.content.form.title') . "</th>
<td><input type='text' name='title' class='inputmedium' maxlength='255'></td>
</tr>

<tr>
<th>" . _lang('admin.content.form.ord') . "</th>
<td><input type='number' name='ord' class='inputsmall' disabled> <label><input type='checkbox' name='moveords' value='1' checked onclick=\"Sunlight.toggleFormField(this.checked, 'addform', 'ord');\"> " . _lang('admin.content.manageimgs.moveords') . "</label></td>
</tr>

<tr>
<th>" . _lang('admin.content.manageimgs.prev') . "</th>
<td><input type='text' name='prev' class='inputsmall' disabled> <label><input type='checkbox' name='autoprev' value='1' checked onclick=\"Sunlight.toggleFormField(this.checked, 'addform', 'prev');\"> " . _lang('admin.content.manageimgs.autoprev') . "</label></td>
</tr>

<tr>
<th>" . _lang('admin.content.manageimgs.full') . "</th>
<td><input type='text' name='full' class='inputmedium'></td>
</tr>

<tr>
<td></td>
<td><input type='submit' value='" . _lang('global.insert') . "'></td>
</tr>

</table>

" . _xsrfProtect() . "</form>
</fieldset>

";

    // obrazky
    $output .= "
<fieldset>
<legend>" . _lang('admin.content.manageimgs.current') . "</legend>
<form action='index.php?p=content-manageimgs&amp;g=" . $g . "' method='post' name='editform'>
<input type='hidden' name='xaction' value='4'>

<input type='submit' value='" . _lang('admin.content.manageimgs.savechanges') . "' class='gallery-savebutton'>
<div class='cleaner'></div>";

    // vypis
    $images = DB::query("SELECT * FROM " . _images_table . " WHERE home=" . $g . " ORDER BY ord");
    $images_forms = array();
    if (DB::size($images) != 0) {
        // sestaveni formularu
        $output .= '<div
    id="gallery-edit"
    class="sortable"
    data-placeholder="false"
    data-input-selector="tr.image-order-row input"
    data-hide="tr.image-order-row"
    data-cancel="input, a"
    data-auto-grid="true"
    data-tolerance="pointer"
>';
        while ($image = DB::row($images)) {
            // kod nahledu
            $preview = _galleryImage($image, "1", $galdata['var4'], $galdata['var3']);

            // kod formulare
            $output .= "
<div class='gallery-edit-image'>
<table>

<tr>
<th>" . _lang('admin.content.form.title') . "</th>
<td><input type='text' name='i" . $image['id'] . "_title' class='max-width' value='" . $image['title'] . "' maxlength='255'></td>
</tr>

<tr class='image-order-row'>
<th>" . _lang('admin.content.form.ord') . "</th>
<td><input type='text' name='i" . $image['id'] . "_ord' class='max-width' value='" . $image['ord'] . "'></td>
</tr>

" . (!$image['in_storage'] ? "<tr>
<th>" . _lang('admin.content.manageimgs.prev') . "</th>
<td><input type='hidden' name='i" . $image['id'] . "_prevtrigger' value='1'><input type='text' name='i" . $image['id'] . "_prev' class='inputsmall' value='" . $image['prev'] . "'" . _inputDisableUnless($image['prev'] != "") . "> <label><input type='checkbox' name='i" . $image['id'] . "_autoprev' value='1' onclick=\"Sunlight.toggleFormField(checked, 'editform', 'i" . $image['id'] . "_prev');\"" . _checkboxActivate($image['prev'] == "") . "> " . _lang('admin.content.manageimgs.autoprev') . "</label></td>
</tr>

<tr>
<th>" . _lang('admin.content.manageimgs.full') . "</th>
<td><input type='text' name='i" . $image['id'] . "_full' class='max-width' value='" . $image['full'] . "'></td>
</tr>" : '') . "

<tr class='valign-top'>
<th>" . _lang('global.preview') . "</th>
<td>" . $preview . "<br><br><a class='button' href='" . _xsrfLink("index.php?p=content-manageimgs&amp;g=" . $g . "&amp;del=" . $image['id']) . "' onclick='return Sunlight.confirm();'><img src='images/icons/delete.png' alt='del' class='icon'>" . _lang('admin.content.manageimgs.delete') . "</a></td>
</tr>

</table>
</div>
";
        }

        $output .= "
</div>
<div class='cleaner'></div>
<input type='submit' value='" . _lang('admin.content.manageimgs.savechanges') . "' class='gallery-savebutton'>";
    } else {
        $output .= '<p>' . _lang('global.nokit') . '</p>';
    }

    $output .= "
" . _xsrfProtect() . "</form>
</fieldset>

<table width='100%'>
<tr class='valign-top'>

<td width='50%'>
  <fieldset class='hs_fieldset'>
  <legend>" . _lang('admin.content.manageimgs.moveimgs') . "</legend>

  <form class='cform' action='index.php?p=content-manageimgs&amp;g=" . $g . "' method='post'>
  <input type='hidden' name='xaction' value='5'>
  " . _adminRootSelect("newhome", array('type' => _page_gallery)) . " <input class='button' type='submit' value='" . _lang('global.do') . "' onclick='return Sunlight.confirm();'><br><br>
  <label><input type='checkbox' name='moveords' value='1' checked> " . _lang('admin.content.manageimgs.moveords') . "</label>
  " . _xsrfProtect() . "</form>

  </fieldset>
</td>

<td>
  <fieldset class='hs_fieldset'>
  <legend>" . _lang('admin.content.manageimgs.delimgs') . "</legend>

  <form class='cform' action='index.php?p=content-manageimgs&amp;g=" . $g . "' method='post'>
  <input type='hidden' name='xaction' value='6'>
  <label><input type='checkbox' name='confirm' value='1'> " . _lang('admin.content.manageimgs.delimgs.confirm') . "</label> <input class='button' type='submit' value='" . _lang('global.do') . "' onclick='return Sunlight.confirm();'>
  " . _xsrfProtect() . "</form>

  </fieldset>
</td>

</tr>
</table>

";
} else {
    $output .= _msg(_msg_err, _lang('global.badinput'));
}
