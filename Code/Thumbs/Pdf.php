<?php

namespace Code\Thumbs;

use Code\Storage\Stdio;

class Pdf
{

    public function Match($type)
    {
        return (($type === 'application/pdf') ? true : false);
    }

    public function Thumb($attach, $preview_style, $height = 300, $width = 300)
    {

        $photo = false;

        $file = dbunescbin($attach['content']);
        $tmpfile = $file . '.pdf';
        $outfile = $file . '.jpg';

        Stdio::fcopy($file,$tmpfile);

        $imagick_path = get_config('system', 'imagick_convert_path');
        if ($imagick_path && @file_exists($imagick_path)) {
            $cmd = $imagick_path . ' ' . escapeshellarg(PROJECT_BASE . '/' . $tmpfile . '[0]') . ' -resize ' . $width . 'x' . $height . ' ' . escapeshellarg(PROJECT_BASE . '/' . $outfile);
            //  logger('imagick thumbnail command: ' . $cmd);
            for ($x = 0; $x < 4; $x++) {
                exec($cmd);
                if (!file_exists($outfile)) {
                    logger('imagick scale failed. Retrying.');
                    continue;
                }
            }
            if (!file_exists($outfile)) {
                logger('imagick scale failed.');
            } else {
                @rename($outfile, $file . '.thumb');
            }
        }
        @unlink($tmpfile);
    }
}
