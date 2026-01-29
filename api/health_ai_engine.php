<?php
require "ai_engine.php";

$reply = askAI_Engine("Reply with OK only.");

echo json_encode([
    "ok" => trim($reply) === "OK"
]);
