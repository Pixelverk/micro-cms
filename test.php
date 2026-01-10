<?php
//file_put_contents('_pages/test.json', json_encode(['hello'=>'world'])) or die('Cannot write!');
//echo "Success!";

// chmod-test.php
$folder = __DIR__ . '/pages';
if (chmod($folder, 0775)) {
    echo "Permissions changed to 775!";
} else {
    echo "Failed to change permissions. You probably don't have ownership.";
}