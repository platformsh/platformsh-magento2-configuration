<?php

include 'Platformsh.php';

$platformSh = new \Platformsh\Magento\Platformsh();
$platformSh->build();
