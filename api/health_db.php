<?php
require "db.php";

$db = getDb();

echo json_encode([
    "ok" => $db !== null
]);
