<?php

$username = $_SESSION['user_id'] ?? 'User';

$location = url('admin/user-edit') . '?username=' . urlencode($username);
header('Location: ' . $location);