<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

function askAI_Engine($promptText)
{
    $HF_TOKEN = "Your-AI-Model-API-Key-Place-Here";
    $url = "Your-AI-Model-API-URL-Place-Here";

    $payload = [
        "model" => "meta-llama/Meta-Llama-3-8B-Instruct",
        "messages" => [
            [
                "role" => "user",
                "content" => $promptText
            ]
        ],
        "temperature" => 0.1,   // Lower = more consistent, Higher = more creative
        "max_tokens" => 256     // Increase if SQL queries are getting cut off
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $HF_TOKEN",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents("debug_ai.txt", $response);

    if ($response === false) {
        return null;
    }

    if ($httpCode !== 200) {
        return null;
    }

    $responseData = json_decode($response, true);
    $reply = $responseData['choices'][0]['message']['content'] ?? '';

    return $reply ?: null;
}

function generateSQLFromText($question, $schemaText)
{
    $HF_TOKEN = "Your-AI-Model-API-Key-Place-Here";
    $url = "Your-AI-Model-API-URL-Place-Here";

    $systemPrompt = "You are an expert Microsoft SQL Server developer.

CRITICAL RULES:
1. ALWAYS use the FULL table name format: schema.tablename (e.g., sctcrb.tbl_members)
2. NEVER use just the table name without the schema prefix
3. Use ONLY tables and columns exactly as listed in the schema
4. Return ONLY the SQL SELECT query - no explanations, no markdown blocks, no code fences

IMPORTANT - When to use TOP clause:
- If user asks for 'all', 'show all', 'list all', 'every', 'complete': DO NOT use TOP
- If user asks for specific number (e.g., '5 members', 'top 10', 'first 20'): Use TOP with that number
- If user asks for 'some', 'few', or doesn't specify: Use TOP 100 as default
- Examples:
  * 'Show all members' → SELECT * FROM sctcrb.tbl_members (NO TOP)
  * 'Show 5 members' → SELECT TOP 5 * FROM sctcrb.tbl_members
  * 'Show members' → SELECT TOP 100 * FROM sctcrb.tbl_members

SQL SERVER SYNTAX:
- Use TOP instead of LIMIT
- Use single quotes for strings
- Example: SELECT TOP 10 * FROM sctcrb.tbl_members WHERE name = 'John'

FORBIDDEN: INSERT, UPDATE, DELETE, DROP, CREATE, ALTER, TRUNCATE, EXEC";

    // Analyze the question for keywords
    $questionLower = strtolower($question);
    $wantsAll = preg_match('/\b(all|every|complete|entire|full list)\b/', $questionLower);
    $hasSpecificNumber = preg_match('/\b(\d+)\b/', $question, $numberMatches);
    
    // Add context to the prompt
    $contextHint = "";
    if ($wantsAll) {
        $contextHint = "\nIMPORTANT: User wants ALL records - do NOT use TOP clause.";
    } elseif ($hasSpecificNumber) {
        $contextHint = "\nIMPORTANT: User wants exactly " . $numberMatches[1] . " records - use TOP " . $numberMatches[1] . ".";
    } else {
        $contextHint = "\nIMPORTANT: User didn't specify quantity - use TOP 100 as safe default.";
    }

    $userPrompt = "$schemaText\n\nUser Question: $question$contextHint\n\nGenerate SQL query using FULL table names (schema.table):";

    $payload = [
        "model" => "meta-llama/Meta-Llama-3-8B-Instruct",
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $userPrompt]
        ],
        "temperature" => 0.1,
        "max_tokens" => 512
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $HF_TOKEN",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents("debug_ai.txt", $response);
    file_put_contents(
        __DIR__ . "/ai_debug.log",
        date("c") . 
        "\n=== QUESTION ===\n" . $question . 
        "\n\n=== CONTEXT HINT ===\n" . $contextHint .
        "\n\n=== SCHEMA ===\n" . $schemaText . 
        "\n\n=== AI RESPONSE ===\n" . $response . "\n\n",
        FILE_APPEND
    );

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $responseData = json_decode($response, true);
    $reply = $responseData['choices'][0]['message']['content'] ?? '';

    // Clean up response - remove markdown, extra spaces
    $reply = preg_replace('/```sql\s*/i', '', $reply);
    $reply = preg_replace('/```\s*/i', '', $reply);
    $reply = preg_replace('/^\s*SELECT/i', 'SELECT', trim($reply));

    return $reply ?: null;
}
