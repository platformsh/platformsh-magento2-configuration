<?php

include 'Platformsh.php';

$platformSh = new Platformsh();
$platformSh->init();
$platformSh->deploy();
