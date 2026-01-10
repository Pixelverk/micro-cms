<?php
// _components/site-header/body.php

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
        $id = 'header-' . uniqid();
        extract($props, EXTR_SKIP);

        ob_start();
        ?>
        <header id="<?= $id ?>">
            <div class="inner">
                <a href="/" class="logo">Acme Consulting</a>
                <nav>
                    <a href="/">Home</a>
                    <a href="/services/">Services</a>
                    <a href="/contact/">Contact</a>
                </nav>
            </div>
        </header>
        <?php
        return ob_get_clean();
    },
];