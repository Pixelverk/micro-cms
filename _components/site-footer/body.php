<?php
// _components/site-footer/body.php

return [
    /** --------------------------------------------
     * CMS-Editable Schema
     * -------------------------------------------- */
    'schema' => [
        'title' => [
            'type' => 'string',
            'label' => 'Accordion Title',
            'required' => true,
        ]
    ],

    /** --------------------------------------------
     * Render function
     * -------------------------------------------- */
    'render' => function (array $props) {
        $id = 'footer-' . uniqid();
        $year = date('Y');
        extract($props, EXTR_SKIP);

        ob_start();
        ?>
        <footer id="<?= $id ?>">
            <div class="inner">
                <div class="top">
                    <div class="brand">Acme Consulting</div>
                    <nav>
                    <a href="/">Home</a>
                    <a href="/services/">Services</a>
                    <a href="/contact/">Contact</a>
                    </nav>
                </div>

                <div class="bottom">
                    © <?= $year ?> Acme Consulting. All rights reserved.
                </div>
            </div>
        </footer>
        <?php
        return ob_get_clean();
    },

];