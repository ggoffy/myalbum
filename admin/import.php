<?php
// ------------------------------------------------------------------------- //
//                      myAlbum-P - XOOPS photo album                        //
//                        <http://www.peak.ne.jp>                           //
// ------------------------------------------------------------------------- //

use Xmf\Module\Admin;
use Xmf\Request;
use XoopsModules\Myalbum\{
    Forms,
    Utility
};

require_once __DIR__ . '/admin_header.php';

// From myalbum*
if (!empty($_POST['myalbum_import']) && !empty($_POST['cid'])) {
    // anti-CSRF
    $xsecurity = new XoopsSecurity();
    if (!$xsecurity->checkReferer()) {
        exit('XOOPS_URL is not included in your REFERER');
    }

    // get src module
    $src_cid     = Request::getInt('cid', 0, 'POST');
    $src_dirname = Request::getString('src_dirname', '', 'POST');
    if ($moduleDirName === $src_dirname) {
        exit('source dirname is same as dest dirname: ' . htmlspecialchars($src_dirname, ENT_QUOTES | ENT_HTML5));
    }
    if (!preg_match('/^myalbum(\d*)$/', $src_dirname, $regs)) {
        exit('invalid dirname of myalbum: ' . htmlspecialchars($src_dirname, ENT_QUOTES | ENT_HTML5));
    }
    /** @var \XoopsModuleHandler $moduleHandler */
    $moduleHandler = xoops_getHandler('module');
    $module        = $moduleHandler->getByDirname($src_dirname);
    if (!is_object($module)) {
        exit('invalid module dirname:' . htmlspecialchars($src_dirname, ENT_QUOTES | ENT_HTML5));
    }
    $src_mid = $module->getVar('mid');

    // authority check
    if (!$GLOBALS['xoopsUser']->isAdmin($src_mid)) {
        exit;
    }

    // read configs from xoops_config directly
    $rs = $GLOBALS['xoopsDB']->query('SELECT conf_name,conf_value FROM  ' . $GLOBALS['xoopsDB']->prefix('config') . " WHERE conf_modid='$src_mid'");
    while (list($key, $val) = $GLOBALS['xoopsDB']->fetchRow($rs)) {
        $src_configs[$key] = $val;
    }
    $src_photos_dir = XOOPS_ROOT_PATH . $src_configs['myalbum_photospath'];
    $src_thumbs_dir = XOOPS_ROOT_PATH . $src_configs['myalbum_thumbspath'];
    // src table names
    $src_table_photos   = $GLOBALS['xoopsDB']->prefix("{$src_dirname}_photos");
    $src_table_cat      = $GLOBALS['xoopsDB']->prefix("{$src_dirname}_cat");
    $src_table_text     = $GLOBALS['xoopsDB']->prefix("{$src_dirname}_text");
    $src_table_votedata = $GLOBALS['xoopsDB']->prefix("{$src_dirname}_votedata");

    if (Request::hasVar('copyormove', 'POST') && 'move' === $_POST['copyormove']) {
        $move_mode = true;
    } else {
        $move_mode = false;
    }

    // create category
    $GLOBALS['xoopsDB']->query('INSERT INTO ' . $GLOBALS['xoopsDB']->prefix($table_cat) . "(pid, title, imgurl) SELECT '0',title,imgurl FROM $src_table_cat WHERE cid='$src_cid'")
    || exit('DB error: INSERT cat table');
    $cid = $GLOBALS['xoopsDB']->getInsertId();

    // INSERT loop
    $rs           = $GLOBALS['xoopsDB']->query("SELECT lid,ext FROM $src_table_photos WHERE cid='$src_cid'");
    $import_count = 0;
    while (list($src_lid, $ext) = $GLOBALS['xoopsDB']->fetchRow($rs)) {
        // photos table
        $set_comments = $move_mode ? 'comments' : "'0'";
        $sql          = 'INSERT INTO ' . $GLOBALS['xoopsDB']->prefix($table_photos) . "(cid,title,ext,res_x,res_y,submitter,`status`,date,hits,rating,votes,comments) SELECT '$cid',title,ext,res_x,res_y,submitter,`status`,date,hits,rating,votes,$set_comments FROM $src_table_photos WHERE lid='$src_lid'";
        $GLOBALS['xoopsDB']->query($sql) or exit('DB error: INSERT photo table');
        $lid = $GLOBALS['xoopsDB']->getInsertId();

        // text table
        $sql = 'INSERT INTO  ' . $GLOBALS['xoopsDB']->prefix($table_text) . " (lid,description) SELECT '$lid',description FROM $src_table_text WHERE lid='$src_lid'";
        $GLOBALS['xoopsDB']->query($sql);

        // votedata table
        $sql = 'INSERT INTO ' . $GLOBALS['xoopsDB']->prefix($table_votedata) . " (lid,ratinguser,rating,ratinghostname,ratingtimestamp) SELECT '$lid',ratinguser,rating,ratinghostname,ratingtimestamp FROM $src_table_votedata WHERE lid='$src_lid'";
        $GLOBALS['xoopsDB']->query($sql);

        @copy("$src_photos_dir/{$src_lid}.{$ext}", "$photos_dir/{$lid}.{$ext}");
        if (in_array(mb_strtolower($ext), $myalbum_normal_exts)) {
            @copy("$src_thumbs_dir/{$src_lid}.{$ext}", "$thumbs_dir/{$lid}.{$ext}");
        } else {
            @copy("$src_photos_dir/{$src_lid}.gif", "$photos_dir/{$lid}.gif");
            @copy("$src_thumbs_dir/{$src_lid}.gif", "$thumbs_dir/{$lid}.gif");
        }

        // exec only moving mode
        if ($move_mode) {
            // moving comments
            $sql = 'UPDATE  ' . $GLOBALS['xoopsDB']->prefix('xoopscomments') . " SET com_modid='$myalbum_mid',com_itemid='$lid' WHERE com_modid='$src_mid' AND com_itemid='$src_lid'";
            $GLOBALS['xoopsDB']->query($sql);

            // delete source photos
            [$photos_dir, $thumbs_dir, $myalbum_mid, $table_photos, $table_text, $table_votedata, $saved_photos_dir, $saved_thumbs_dir, $saved_myalbum_mid, $saved_table_photos, $saved_table_text, $saved_table_votedata] = [
                $src_photos_dir,
                $src_thumbs_dir,
                $src_mid,
                $src_table_photos,
                $src_table_text,
                $src_table_votedata,
                $photos_dir,
                $thumbs_dir,
                $myalbum_mid,
                $GLOBALS['xoopsDB']->prefix($table_photos),
                $GLOBALS['xoopsDB']->prefix($table_text),
                $GLOBALS['xoopsDB']->prefix($table_votedata),
            ];
            Utility::deletePhotos("lid='$src_lid'");
            [$photos_dir, $thumbs_dir, $myalbum_mid, $table_photos, $table_text, $table_votedata] = [
                $saved_photos_dir,
                $saved_thumbs_dir,
                $saved_myalbum_mid,
                $saved_table_photos,
                $saved_table_text,
                $saved_table_votedata,
            ];
        }

        ++$import_count;
    }

    redirect_header('import.php', 2, sprintf(_AM_FMT_IMPORTSUCCESS, $import_count));
} // From imagemanager
elseif (!empty($_POST['imagemanager_import']) && !empty($_POST['imgcat_id'])) {
        // authority check
        $grouppermHandler = xoops_getHandler('groupperm');
        if (!$grouppermHandler->checkRight('system_admin', XOOPS_SYSTEM_IMAGE, $GLOBALS['xoopsUser']->getGroups())) {
            exit;
        }

        // anti-CSRF
        $xsecurity = new XoopsSecurity();
        if (!$xsecurity->checkReferer()) {
            exit('XOOPS_URL is not included in your REFERER');
        }

        // get src information
        $src_cid          = Request::getInt('imgcat_id', 0, 'POST');
        $src_table_photos = $GLOBALS['xoopsDB']->prefix('image');
        $src_table_cat    = $GLOBALS['xoopsDB']->prefix('imagecategory');

        // create category
        $crs = $GLOBALS['xoopsDB']->query("SELECT imgcat_name,imgcat_storetype FROM $src_table_cat WHERE imgcat_id='$src_cid'");
        [$imgcat_name, $imgcat_storetype] = $GLOBALS['xoopsDB']->fetchRow($crs);

        $GLOBALS['xoopsDB']->query('INSERT INTO ' . $GLOBALS['xoopsDB']->prefix($table_cat) . "SET pid=0,title='" . addslashes($imgcat_name) . "'")
        || exit('DB error: INSERT cat table');
        $cid = $GLOBALS['xoopsDB']->getInsertId();

        // INSERT loop
        $rs           = $GLOBALS['xoopsDB']->query("SELECT image_id,image_name,image_nicename,image_created,image_display FROM $src_table_photos WHERE imgcat_id='$src_cid'");
        $import_count = 0;
        while (list($image_id, $image_name, $image_nicename, $image_created, $image_display) = $GLOBALS['xoopsDB']->fetchRow($rs)) {
            $src_file = XOOPS_UPLOAD_PATH . "/$image_name";
            $ext      = mb_substr(mb_strrchr($image_name, '.'), 1);

            // photos table
            $sql = 'INSERT INTO  ' . $GLOBALS['xoopsDB']->prefix($table_photos) . "SET cid='$cid',title='" . addslashes($image_nicename) . "',ext='$ext',submitter='$my_uid',`status`='$image_display',date='$image_created'";
            $GLOBALS['xoopsDB']->query($sql) or exit('DB error: INSERT photo table');
            $lid = $GLOBALS['xoopsDB']->getInsertId();

            // text table
            $sql = 'INSERT INTO  ' . $GLOBALS['xoopsDB']->prefix($table_text) . " SET lid='$lid',description=''";
            $GLOBALS['xoopsDB']->query($sql);

            $dst_file = "$photos_dir/{$lid}.{$ext}";
            if ('db' === $imgcat_storetype) {
                $fp = fopen($dst_file, 'wb');
                if (false === $fp) {
                    continue;
                }
                $brs = $GLOBALS['xoopsDB']->query('SELECT image_body FROM  ' . $GLOBALS['xoopsDB']->prefix('imagebody') . " WHERE image_id='$image_id'");
                [$body] = $GLOBALS['xoopsDB']->fetchRow($brs);
                fwrite($fp, $body);
                fclose($fp);
                Utility::createThumb($dst_file, $lid, $ext);
            } else {
                @copy($src_file, $dst_file);
                Utility::createThumb($src_file, $lid, $ext);
            }

            [$width, $height, $type] = getimagesize($dst_file);
            $GLOBALS['xoopsDB']->query('UPDATE ' . $GLOBALS['xoopsDB']->prefix($table_photos) . "SET res_x='$width',res_y='$height' WHERE lid='$lid'");

            ++$import_count;
        }

        redirect_header('import.php', 2, sprintf(_AM_FMT_IMPORTSUCCESS, $import_count));
    }


require_once dirname(__DIR__) . '/include/myalbum.forms.php';
xoops_cp_header();
$adminObject = Admin::getInstance();
$adminObject->displayNavigation(basename(__FILE__));
//myalbum_adminMenu(basename(__FILE__), 6);
$GLOBALS['xoopsTpl']->assign('admin_title', sprintf(_AM_H3_FMT_IMPORTTO, $xoopsModule->name()));
$GLOBALS['xoopsTpl']->assign('mydirname', $GLOBALS['mydirname']);
$GLOBALS['xoopsTpl']->assign('photos_url', $GLOBALS['photos_url']);
$GLOBALS['xoopsTpl']->assign('thumbs_url', $GLOBALS['thumbs_url']);
$GLOBALS['xoopsTpl']->assign('forma', Forms::getAdminFormImportMyalbum());
$GLOBALS['xoopsTpl']->assign('formb', Forms::getAdminFormImportImageManager());

$GLOBALS['xoopsTpl']->display('db:' . $GLOBALS['mydirname'] . '_cpanel_import.tpl');

//  myalbum_footer_adminMenu();
require_once __DIR__ . '/admin_footer.php';
