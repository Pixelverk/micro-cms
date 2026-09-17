<?php

$username = current_username();

$location = url('admin/user/edit') . '?username=' . urlencode($username);
header('Location: ' . $location);