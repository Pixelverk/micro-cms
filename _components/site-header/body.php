<?php
// _components/site-header/body.php

return [
    /** --------------------------------------------
     * CMS-Editable Schema
     * -------------------------------------------- */
    'schema' => [],

    /** --------------------------------------------
     * Child element options
     * -------------------------------------------- */
    'children' => 'none', // 'any', 'none', or 'some'
    'allowed_children' => [], // only used if children='some'

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props) {
        $id = 'header-' . uniqid();
        extract($props, EXTR_SKIP);

        ?>
        <header id="<?= $id ?>">
            <div class="inner">
                <a href="<?= url() ?>" class="logo">Acme Consulting</a>
                <nav>
                    <a href="<?= url() ?>">Home</a>
                    <a href="<?= url('services') ?>">Services</a>
                    <a href="<?= url('contact') ?>">Contact</a>
                </nav>
            </div>
        </header>
        <?php
    },
];