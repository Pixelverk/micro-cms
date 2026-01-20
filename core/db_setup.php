<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Create SQLite Database
|--------------------------------------------------------------------------
*/

$dbPath = STORAGE_PATH . '/data.sqlite';

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
|--------------------------------------------------------------------------
| Create Tables
|--------------------------------------------------------------------------
*/

$pdo->exec("
    CREATE TABLE contents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        slug TEXT NOT NULL,
        status TEXT NOT NULL,
        data TEXT NOT NULL,
        updated_at INTEGER NOT NULL
    );
");

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        updated_at INTEGER NULL
    );
");

$pdo->exec("
    CREATE TABLE menus (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL,
        items TEXT NOT NULL,
        updated_at INTEGER NULL
    );
");

/*
|--------------------------------------------------------------------------
| Insert Initial Admin User
|--------------------------------------------------------------------------
*/

$username = 'admin';
$password = password_hash('admin', PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (username, password)
    VALUES (:username, :password)
");

$stmt->execute([
    'username' => $username,
    'password' => $password,
]);

/*
|--------------------------------------------------------------------------
| Insert Initial Homepage
|--------------------------------------------------------------------------
*/

$homeData = [
    'title' => 'Home',
    'layout' => 'default',
    'components' => [
        [
            'component' => 'hero',
            'props' => [
                'title' => 'Welcome',
            ],
        ],
    ],
];

$stmt = $pdo->prepare("
    INSERT INTO contents (type, slug, status, data, updated_at)
    VALUES (:type, :slug, :status, :data, :updated_at)
");

$stmt->execute([
    'type'       => 'page',
    'slug'       => '/',
    'status'     => 'published',
    'data'       => json_encode($homeData, JSON_THROW_ON_ERROR),
    'updated_at' => time(),
]);

/*
|--------------------------------------------------------------------------
| First Run Complete
|--------------------------------------------------------------------------
*/

exit;
