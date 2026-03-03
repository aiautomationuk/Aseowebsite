<?php
header('Content-Type: application/json');
session_start();
echo json_encode(['paid' => !empty($_SESSION['paid'])]);
