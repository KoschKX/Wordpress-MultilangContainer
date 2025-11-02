<?php
// gzip-compress and decompress text data in PHP

function gzip_compress($data) {
    // Compress string using gzip
    return gzencode($data, 9);
}

function gzip_decompress($gzdata) {
    // Decompress gzip-compressed string
    return gzdecode($gzdata);
}

?>
