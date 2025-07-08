<?php
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Cleantalk_Antispam',
    __DIR__
);
\Cleantalk\Antispam\lib\Autoloader::autoload();
