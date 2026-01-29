<?php
require "api/ai_engine.php";

echo askAI_Engine("Reply with OK only.") ?? "NO RESPONSE";
