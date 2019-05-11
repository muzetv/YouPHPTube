<?php

global $global, $config;
session_write_close();
$obj = new stdClass();
$obj->error = true;
if (!isset($global['systemRootPath'])) {
    require_once '../../videos/configuration.php';
}
if (!User::canUpload()) {
    $obj->msg = "Only logged users can upload";
    die(json_encode($obj));
}

header('Content-Type: application/json');

// A list of permitted file extensions

$allowed = array(
    'mp4',
    'ogg',
    'mp3',
    'webm'
);

if (isset($_FILES['upl']) && $_FILES['upl']['error'] == 0) {
    $extension = pathinfo($_FILES['upl']['name'], PATHINFO_EXTENSION);
    if (!in_array(strtolower($extension), $allowed)) {
        $obj->msg = "File extension error [{$_FILES['upl']['name']}], we allow only (" . implode(",", $allowed) . ")";
        die(json_encode($obj));
    }

    require_once $global['systemRootPath'] . 'objects/video.php';

    $duration = Video::getDurationFromFile($_FILES['upl']['tmp_name']);
    $path_parts = pathinfo($_FILES['upl']['name']);
    $mainName = preg_replace("/[^A-Za-z0-9]/", "", cleanString($path_parts['filename']));
    $filename = uniqid($mainName . "_", true);
    $videos_id = 0;
    if(!empty($_FILES['upl']['videoId'])){
        $videos_id = $_FILES['upl']['videoId'];
    }else if(!empty($_POST['videos_id'])){
        $videos_id = $_POST['videos_id'];
    }
    if(empty($videos_id)){
        $video = new Video(substr(preg_replace("/_+/", " ", $_FILES['upl']['name']), 0, -4), $filename, 0);
    }else{
        $video = new Video("", $filename, $videos_id);
        if($video->getTitle() === "Video automatically booked"){
            $video->setTitle(substr(preg_replace("/_+/", " ", $_FILES['upl']['name']), 0, -4));
        }
    }
    $video->setDuration($duration);

    if (!empty($_POST['title'])) {
        $video->setTitle($_POST['title']);
    }

    if (!empty($_POST['description'])) {
        $video->setDescription($_POST['description']);
    }

    if ($extension == "mp4" || $extension == "webm") {
        $video->setType("video");
    } else
    if (($extension == "mp3") || ($extension == "ogg")) {
        $video->setType("audio");
    }

    $advancedCustom = YouPHPTubePlugin::getObjectDataIfEnabled("CustomizeAdvanced");
    if (empty($advancedCustom->makeVideosInactiveAfterEncode)) {

        // set active

        $video->setStatus('a');
    } else {
        $video->setStatus('i');
    }

    $id = $video->save();
    if ($id) {
        /**
         * This is when is using in a non uploaded movie
         */
        $aws_s3 = YouPHPTubePlugin::loadPluginIfEnabled('AWS_S3');
        $tmp_name = $_FILES['upl']['tmp_name'];
        $filenameMP4 = $filename . "." . $extension;

        $destinationVideoPath = "{$global['systemRootPath']}videos/{$filenameMP4}";
        if (!move_uploaded_file($tmp_name, $destinationVideoPath)) {
            $obj->msg = print_r(sprintf(__("Could not move video file [%s]"), $destinationVideoPath), true);
            die(json_encode($obj));
        }
        decideMoveUploadedToVideos($destinationVideoPath, $filenameMP4);
        
        if ((YouPHPTubePlugin::isEnabled("996c9afb-b90e-40ca-90cb-934856180bb9")) && ($extension == "mp4" || $extension == "webm")) {
            require_once $global['systemRootPath'] . 'plugin/MP4ThumbsAndGif/MP4ThumbsAndGif.php';

            $videoFileName = $video->getFilename();
            if(MP4ThumbsAndGif::getImage($videoFileName, 'jpg')){
                $sourceImagePath = "{$global['systemRootPath']}videos/{$videoFileName}.jpg";

                decideMoveUploadedToVideos($sourceImagePath, $filename . ".jpg");
            }

            if(MP4ThumbsAndGif::getImage($videoFileName, 'gif')) {
                $sourceImagePath = "{$global['systemRootPath']}videos/{$videoFileName}.gif";
                decideMoveUploadedToVideos($sourceImagePath, $filename . ".gif");
            }
        } 

        //    } else if(($extension=="mp3")||($extension=="ogg")){
        //  }

        $obj->error = false;
        $obj->filename = $filename;
        $obj->duration = $duration;
        $obj->videos_id = $id;
        

        if(!empty($_FILES['upl']['tmp_name'])){
            YouPHPTubePlugin::afterNewVideo($obj->videos_id);
        }
        die(json_encode($obj));
    }
}

$obj->msg = "\$_FILES Error";
$obj->FILES = $_FILES;
die(json_encode($obj));
