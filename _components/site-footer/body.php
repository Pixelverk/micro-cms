<?php
// _components/site-footer/body.php

return [
    /** --------------------------------------------
     * CMS-Editable Schema
     * -------------------------------------------- */
    'schema' => [],

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props) {
        $id = 'footer-' . uniqid();
        $year = date('Y');
        extract($props, EXTR_SKIP);

        ?>
        <footer id="<?= $id ?>">
            <div class="inner">
                <div class="top">
                    <div class="brand">Acme Consulting</div>
                    <nav>
                    <a href="<?= url() ?>">Home</a>
                    <a href="<?= url('services') ?>">Services</a>
                    <a href="<?= url('contact') ?>">Contact</a>
                    </nav>
                </div>

                <div class="bottom">
                    © <?= $year ?> Acme Consulting. All rights reserved.
                </div>
            </div>
        </footer>
        <?php
    },
];