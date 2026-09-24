<?php if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__)); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? '社区便民留言板' ?></title>
    <link rel="stylesheet" href="<?= $cssPath ?? 'assets/css/style.css' ?>">
</head>
<body>
<header class="site-header">
    <div class="container">
        <div class="header-inner">
            <a href="index.php" class="logo">
                <span class="logo-icon">📋</span>
                <span>社区便民留言板</span>
            </a>
            <nav class="main-nav">
                <a href="index.php" class="nav-link <?= ($currentPage ?? '') === 'home' ? 'active' : '' ?>">首页</a>
                <a href="favorites.php" class="nav-link <?= ($currentPage ?? '') === 'favorites' ? 'active' : '' ?>">⭐ 我的收藏</a>
                <a href="my_reports.php" class="nav-link <?= ($currentPage ?? '') === 'my_reports' ? 'active' : '' ?>">🚩 我的举报</a>
                <a href="submit.php" class="nav-link <?= ($currentPage ?? '') === 'submit' ? 'active' : '' ?>">发布留言</a>
                <a href="admin/login.php" class="nav-link nav-admin">后台管理</a>
            </nav>
            <button class="mobile-menu-btn" onclick="document.querySelector('.main-nav').classList.toggle('show')">☰</button>
        </div>
    </div>
</header>
<main class="site-main">
